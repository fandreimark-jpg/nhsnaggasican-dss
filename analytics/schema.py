"""
THE SINGLE SOURCE OF TRUTH for ML feature names, feature ORDER, the target
definition, and the missing-data policy.

Nothing else in this package may hard-code a feature list. `classify.py`
(inference), `train_model.py` (training), `dataset_validator.py`
(validation), `baseline.py` (comparison) and `model_registry.py` (metadata)
all read their column names from here, so a schema change is one edit and a
version bump rather than four files drifting apart.

WHY THIS EXISTS — the mapping problem it prevents
--------------------------------------------------
The school previously reported grades as Q1/Q2/Q3/Q4 (a quarterly system)
and has since moved to Term 1/Term 2/Term 3 (the current system, and the
one this whole codebase's live grading path — GradingEngine, Assessment,
Grade — is built around). These are NOT the same shape: naively mapping
Q1->Term 1, Q2->Term 2, Q3->Term 3 and discarding Q4 would silently throw
away a quarter of every historical student-year and pretend the two
systems are interchangeable when they are not (four reporting periods
compressed into three loses information about where in the year a grade
was recorded, and there is no principled reason Q4 is the one to discard
rather than, say, treating Q1+Q2 as "first half" evidence).

REPORTING_SYSTEM + PERIOD_INDEX exist instead of a forced Term 1-3
mapping specifically so a Q1-Q4 row and a Term-1-3 row can both be
represented HONESTLY — each keeps its own native period count — and a
future feature-engineering step (e.g. "first-half average" vs
"second-half average") can be built deliberately on top of this, rather
than baked into the ingestion layer as an unexamined assumption.
"""

from __future__ import annotations

import math
from dataclasses import dataclass


# ---------------------------------------------------------------------------
# Versioning
# ---------------------------------------------------------------------------

# Bumped whenever FEATURE_COLUMNS, their order, their definitions, or
# TARGET_DEFINITION changes in a way that makes a previously-trained model
# incompatible with a new payload. classify.py REFUSES to run a model whose
# metadata declares a different major version than this file — a silently
# reordered or renamed feature is the exact failure mode that check exists
# to make impossible.
FEATURE_SCHEMA_VERSION = '1.0.0'


def major_version(version: str) -> str:
    return str(version).split('.')[0]


# ---------------------------------------------------------------------------
# Normalized academic-period concepts
# ---------------------------------------------------------------------------

REPORTING_SYSTEM_QUARTERLY = 'quarterly'
REPORTING_SYSTEM_THREE_TERM = 'three_term'

REPORTING_SYSTEMS = (REPORTING_SYSTEM_QUARTERLY, REPORTING_SYSTEM_THREE_TERM)

# period_index is 1-4 for quarterly, 1-3 for three_term. A three_term row
# with period_index 4 is invalid; a quarterly row with period_index 4 is
# valid. This asymmetry is validated per-row in dataset_validator.py — it
# cannot be expressed as a single flat "valid values" list independent of
# reporting_system, which is exactly the point: the two systems are not
# secretly the same shape wearing different labels.
MAX_PERIOD_INDEX = {
    REPORTING_SYSTEM_QUARTERLY: 4,
    REPORTING_SYSTEM_THREE_TERM: 3,
}


# ---------------------------------------------------------------------------
# Training data contract — one row = one learner-period record
# ---------------------------------------------------------------------------

# Identity / context columns. NOT features — never fed to the model.
# `anonymous_student_id` is deliberately named to say what it must NOT be:
# a real LRN or name. See PRIVACY note below.
CONTEXT_COLUMNS = (
    'anonymous_student_id',
    'school_year',
    'grade_level',
    'curriculum',
    'grading_policy',
    'reporting_system',
    'period_index',
)

# Numeric academic/ML features, IN THE EXACT ORDER a model is fitted on.
# Column order is part of the contract: a fitted RandomForest indexes its
# splits positionally, so reordering this tuple without bumping
# FEATURE_SCHEMA_VERSION would silently feed pt_mean into the column the
# model learned as ww_mean. `build_feature_vector()` below is the only
# supported way to turn a payload dict into a row, precisely so nothing
# ever depends on Python dict iteration order.
#
# These are COMPUTED OUTCOMES of the academic grading rules (a mean
# percentage), never the grading policy itself — WW/PT/Exam WEIGHTS are an
# academic-rules concept owned by GradingEngine/SubjectGroupWeight and are
# never a training feature.
FEATURE_COLUMNS = (
    'ww_mean',
    'pt_mean',
    'exam_mean',
    'current_average',
    'prev_period_average',
    'trend_delta',
    'failing_subject_count',
    'weak_component_count',
    'missing_assessment_count',
)

# The one written definition of each feature. Kept in code (not only in
# TRAINING_DATA_CONTRACT.md) so `python -m schema` can print the contract a
# school's data team must satisfy, and so a reviewer reading train_model.py
# is one import away from the definition rather than one document away.
FEATURE_DEFINITIONS = {
    'ww_mean':
        "Written Work percentage for this learner-period, pooled across every "
        "subject with Written Work evidence: SUM(earned) / SUM(max) * 100. "
        "BLANK (not 0) when the learner has no Written Work evidence at all.",
    'pt_mean':
        "Performance Task percentage, pooled the same way as ww_mean. "
        "BLANK (not 0) when there is no Performance Task evidence at all.",
    'exam_mean':
        "Examination percentage, pooled the same way. BLANK (not 0) in BOTH of "
        "two distinct situations, which the dataset must not confuse with a "
        "score of zero: (a) the learner's grading profile has NO Examination "
        "component at all (DO 015 Work Immersion, Research, Design and "
        "Innovation — ex_weight is null, meaning the component does not exist, "
        "not that it is worth zero); (b) the component exists but no "
        "examination has been recorded yet. Both are 'not a number this model "
        "may treat as evidence'; neither is a failing zero.",
    'current_average':
        "This reporting period's overall academic average for the learner, on "
        "the 0-100 scale the grading order produces. Never blank on a valid row "
        "— a learner-period with no grade at all is not a training record.",
    'prev_period_average':
        "The immediately preceding comparable reporting period's overall "
        "average, same scale. BLANK when there is no previous period (the "
        "first period of a learner's first recorded year). Never fabricated, "
        "and never 0 — 'no previous period' is not 'previously scored zero'.",
    'trend_delta':
        "current_average - prev_period_average. BLANK exactly when "
        "prev_period_average is blank.",
    'failing_subject_count':
        "Count of the learner's APPLICABLE subjects this period whose grade is "
        "below the passing standard (75 — PerformanceAnalysisService::"
        "DEFAULT_TARGET). Counts only subjects the learner actually takes and "
        "actually has a grade for; a subject with no grade yet is missing "
        "evidence, counted by missing_assessment_count, never as a failure.",
    'weak_component_count':
        "Count of the learner's AVAILABLE components (of Written Work, "
        "Performance Task, Examination) whose pooled percentage for this period "
        "is strictly below the 75 target. Range 0-3. THE DEFINITION IS: "
        "count(c in {ww_mean, pt_mean, exam_mean} : c is not blank and c < 75). "
        "A blank component is NOT counted as weak and NOT counted as strong — "
        "it is excluded from the count entirely, so a learner with no "
        "Examination component can never score 'weak' for a component they do "
        "not have. This is the same rule App\\Services\\RiskFeatureExtractor::"
        "weakComponentCount() applies in Laravel; the two must not diverge.",
    'missing_assessment_count':
        "Count of (assessment item, learner) pairs where the item exists for "
        "the learner's section/subject/period/year but NO score row exists for "
        "that learner. A recorded score of 0 is a score and is NOT counted "
        "here; only an absent record is. Blank is permitted only for a "
        "historical dataset whose source genuinely cannot distinguish an "
        "unrecorded assessment from one that was never assigned — and that "
        "must be stated in the dataset's notes, not assumed.",
}

# Plausible inclusive range per feature, used by dataset_validator.py to
# reject an impossible value rather than train on it. A percentage cannot
# exceed 100 or go below 0; a delta can legitimately be negative; a count
# cannot be negative. The count ceilings are deliberately loose — they
# exist to catch a units/parsing error (a column holding a raw score, or a
# whole section's total), not to encode a policy about how many subjects a
# learner may fail.
FEATURE_RANGES = {
    'ww_mean': (0.0, 100.0),
    'pt_mean': (0.0, 100.0),
    'exam_mean': (0.0, 100.0),
    'current_average': (0.0, 100.0),
    'prev_period_average': (0.0, 100.0),
    'trend_delta': (-100.0, 100.0),
    'failing_subject_count': (0.0, 30.0),
    'weak_component_count': (0.0, 3.0),
    'missing_assessment_count': (0.0, 500.0),
}

# Features that may legitimately be blank on an otherwise valid row, and
# why. Anything NOT listed here must be present — a blank in one of those
# means the row does not describe a learner-period this model can learn
# from, and dataset_validator.py rejects it rather than imputing a value.
OPTIONAL_FEATURE_COLUMNS = frozenset({
    'exam_mean',             # grading profile may have no Examination component
    'prev_period_average',   # no previous period exists for a first period
    'trend_delta',           # blank exactly when prev_period_average is
    'missing_assessment_count',  # a historical source may not record item-level gaps
    'ww_mean',               # a period may genuinely have no Written Work evidence
    'pt_mean',               # ditto Performance Task
})

REQUIRED_FEATURE_COLUMNS = tuple(c for c in FEATURE_COLUMNS if c not in OPTIONAL_FEATURE_COLUMNS)

# MISSING-DATA POLICY, recorded in every model's metadata.
#
# A blank is carried through to the model as NaN and handled NATIVELY by
# scikit-learn's tree splitter (RandomForestClassifier has supported missing
# values since sklearn 1.4; this project runs 1.9). It is NEVER imputed to
# 0, to a mean, or to any other invented number, because every blank in this
# schema means "this quantity does not exist or was never recorded" — and
# zero is a real, different, much worse academic statement. Imputing a mean
# would be equally wrong for exam_mean: it would invent an examination result
# for a subject that has no examination.
MISSING_VALUE_STRATEGY = 'native_nan'
MISSING_VALUE_POLICY = (
    "Blank means 'not applicable or not recorded' and is passed to the model as "
    "NaN, handled natively by scikit-learn's tree splitter (>=1.4). Blanks are "
    "never imputed to 0, to a mean, or to any other value: a blank exam_mean "
    "means the component does not exist or has no record, which is not the same "
    "academic fact as scoring 0; a blank prev_period_average means there was no "
    "previous period, which is not the same as having previously averaged 0."
)

# Optional, NOT required, and not included in FEATURE_COLUMNS — attendance
# is not collected anywhere in this system today (see CLAUDE.md,
# "Attendance is not part of any grade") and must never be silently assumed
# present. A future dataset that genuinely has authorized attendance data
# may add this explicitly, as a deliberate schema version bump.
UNUSED_OPTIONAL_COLUMNS = (
    'attendance_absences',
)


# ---------------------------------------------------------------------------
# The target
# ---------------------------------------------------------------------------

# The ground-truth label. MUST come from a school-approved historical
# definition (a real, documented intervention/outcome record) — this
# codebase does not invent, infer, or derive one.
TARGET_COLUMN = 'outcome'

# Kept small and named rather than free text, so a typo ("Intervention "
# with a trailing space) is a rejected row, not a silent fourth class
# nobody intended to create.
VALID_TARGET_VALUES = ('no_intervention', 'intervention')

# THE TARGET IS NOT DERIVED FROM current_average, AND MUST NEVER BE.
#
# The legacy prototype (see analytics/legacy/) generated its own labels from
# grade bands — >=85 low, 75-84.9 moderate, else high — and then fitted a
# classifier to them. That produces a model that has learned the band
# boundaries it was handed, which is a restatement of a rule, not a
# discovered signal. Repeating that shape with a binary label (e.g.
# "current_average < 75 -> intervention") would recreate exactly the same
# problem under a new name.
#
# `outcome` must therefore be supplied EXTERNALLY, by the school, from a
# record of what actually happened to that learner in that period.
TARGET_DEFINITION = (
    "'outcome' is a VERIFIED HISTORICAL OUTCOME supplied by the school, one of "
    "no_intervention / intervention, recording whether that learner-period "
    "actually received a documented academic intervention. It is never derived "
    "from current_average or from any other column in this dataset, and this "
    "pipeline will not compute it. A dataset without a school-supplied outcome "
    "column cannot be trained on. If the school later defines a different "
    "legitimate target, that is an explicit FEATURE_SCHEMA_VERSION bump with a "
    "new TARGET_DEFINITION — never a quiet change of meaning behind the same "
    "column name."
)

# The domain the ACTIVE production path currently speaks (see classify.py
# and App\Models\RiskResult). The candidate target above is BINARY and these
# are THREE ORDERED LEVELS — they are not the same thing and there is no
# automatic mapping between them. classify.py refuses, loudly, to serve a
# binary-target model through this contract. See analytics/README.md,
# "Rule-based DSS layer".
RISK_LEVELS = ('low', 'moderate', 'high')

DATASET_TYPES = ('synthetic', 'real_historical', 'mixed')


# ---------------------------------------------------------------------------
# What must NEVER be a feature or appear in a training dataset
# ---------------------------------------------------------------------------

# Real identifying information a school-provided historical export might
# still carry. dataset_validator.py rejects a dataset that contains ANY of
# these column names outright, rather than silently dropping them — a
# dataset built from a file that still has a name/address/LRN column is a
# privacy problem worth stopping on, not quietly fixing.
FORBIDDEN_COLUMNS = (
    'name', 'student_name', 'full_name', 'first_name', 'last_name', 'middle_name',
    'address', 'phone', 'mobile', 'email', 'contact_number',
    'parent_name', 'guardian_name', 'guardian_contact', 'parent_contact',
    'birthdate', 'birth_date', 'date_of_birth',  # removed from the learner record entirely; never an ML feature
    'lrn',  # the real LRN — never a feature; anonymous_student_id is not the LRN itself
)

# anonymous_student_id is REQUIRED (for de-duplication and for grouping rows
# of the same learner on one side of a train/test split) and is explicitly
# NOT a model feature. It appears in CONTEXT_COLUMNS, never in
# FEATURE_COLUMNS, and build_feature_vector() cannot reach it.
GROUPING_COLUMN = 'anonymous_student_id'

ALL_REQUIRED_COLUMNS = CONTEXT_COLUMNS + FEATURE_COLUMNS + (TARGET_COLUMN,)


# ---------------------------------------------------------------------------
# Feature-vector construction — the ONLY supported way to order a row
# ---------------------------------------------------------------------------

class SchemaError(ValueError):
    """A payload/row cannot be expressed under this schema. Carries a message safe to show an operator."""


def build_feature_vector(row: dict, feature_names=None) -> list:
    """
    Turns a dict into a positional feature vector in `feature_names` order
    (default: this schema's FEATURE_COLUMNS), converting blank/None to
    float('nan') per MISSING_VALUE_POLICY.

    Deliberately takes the ORDER from an explicit list rather than from the
    dict's own key order: at inference time the order must come from the
    fitted model's metadata, not from however Laravel happened to serialise
    its JSON.

    Raises SchemaError for a missing required key or a non-numeric value —
    never guesses.
    """
    names = tuple(feature_names) if feature_names is not None else FEATURE_COLUMNS

    vector = []
    for name in names:
        if name not in row:
            raise SchemaError(f"required feature '{name}' is absent from the payload")

        value = row[name]
        if value is None or (isinstance(value, str) and value.strip() == ''):
            vector.append(float('nan'))
            continue

        if isinstance(value, bool):
            raise SchemaError(f"feature '{name}' is a boolean; features must be numeric")

        try:
            number = float(value)
        except (TypeError, ValueError):
            raise SchemaError(f"feature '{name}' is not numeric: {value!r}")

        if math.isinf(number):
            raise SchemaError(f"feature '{name}' is infinite")

        vector.append(number)

    return vector


@dataclass(frozen=True)
class TrainingDataContract:
    """
    A single, importable object describing the contract, for anything
    (dataset_validator.py, train_model.py, a future admin-facing report)
    that wants to introspect it rather than importing individual module
    constants.
    """

    schema_version: str = FEATURE_SCHEMA_VERSION
    context_columns: tuple = CONTEXT_COLUMNS
    feature_columns: tuple = FEATURE_COLUMNS
    required_feature_columns: tuple = REQUIRED_FEATURE_COLUMNS
    optional_feature_columns: frozenset = OPTIONAL_FEATURE_COLUMNS
    unused_optional_columns: tuple = UNUSED_OPTIONAL_COLUMNS
    target_column: str = TARGET_COLUMN
    valid_target_values: tuple = VALID_TARGET_VALUES
    target_definition: str = TARGET_DEFINITION
    forbidden_columns: tuple = FORBIDDEN_COLUMNS
    grouping_column: str = GROUPING_COLUMN
    reporting_systems: tuple = REPORTING_SYSTEMS
    feature_ranges: dict = None  # set in __post_init__-free way below
    missing_value_strategy: str = MISSING_VALUE_STRATEGY

    def required_columns(self) -> tuple:
        return self.context_columns + self.feature_columns + (self.target_column,)

    def max_period_index(self, reporting_system: str):
        return MAX_PERIOD_INDEX.get(reporting_system)

    def ranges(self) -> dict:
        return FEATURE_RANGES


DEFAULT_CONTRACT = TrainingDataContract()


def describe() -> str:
    """Human-readable dump of the contract — `python schema.py`."""
    lines = [
        f"FEATURE SCHEMA VERSION: {FEATURE_SCHEMA_VERSION}",
        "",
        "TARGET",
        "-" * 70,
        f"  column: {TARGET_COLUMN}",
        f"  values: {', '.join(VALID_TARGET_VALUES)}",
        "  " + TARGET_DEFINITION.replace('. ', '.\n  '),
        "",
        "FEATURES (in model order)",
        "-" * 70,
    ]
    for i, name in enumerate(FEATURE_COLUMNS, start=1):
        low, high = FEATURE_RANGES[name]
        optional = 'optional (may be blank)' if name in OPTIONAL_FEATURE_COLUMNS else 'required'
        lines.append(f"{i}. {name}  [{low} .. {high}]  {optional}")
        lines.append("   " + FEATURE_DEFINITIONS[name].replace('. ', '.\n   '))
        lines.append("")
    lines += [
        "MISSING-DATA POLICY",
        "-" * 70,
        "  " + MISSING_VALUE_POLICY.replace('. ', '.\n  '),
        "",
        "NEVER A FEATURE (dataset rejected outright if present)",
        "-" * 70,
        "  " + ', '.join(FORBIDDEN_COLUMNS),
    ]
    return "\n".join(lines)


if __name__ == '__main__':
    print(describe())
