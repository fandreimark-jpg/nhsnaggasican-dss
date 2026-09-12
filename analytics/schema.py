"""
"ML architecture preparation" pass, Phases 2 and 5.

The normalized academic-period and feature contract for FUTURE historical
training data. Nothing in this file is wired into classify.py's runtime
prediction path or into get_model()/model_cache.pkl — it exists so that
`dataset_validator.py` and `train_model.py` (also new in this pass) have
one shared, documented definition of what a valid training row looks like,
rather than each inventing its own column list.

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

from dataclasses import dataclass, field


# ---------------------------------------------------------------------------
# Normalized academic-period concepts (Phase 2)
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
# Training data contract (Phase 5) — one row = one learner-period record
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

# Numeric academic/ML features — computed from GradingEngine output and
# component evidence, never raw grading-policy percentages themselves
# (see Phase 3: WW/PT/Exam WEIGHTS are an academic-rules concept, owned by
# GradingEngine/SubjectGroupWeight, not a training feature; ww_mean etc.
# below are COMPUTED OUTCOMES of those rules — a mean percentage — not the
# policy that produced them).
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

# Optional, NOT required, and not included in FEATURE_COLUMNS by default —
# attendance is not collected anywhere in this system today (see CLAUDE.md,
# "Attendance is not part of any grade") and must never be silently assumed
# present. A future dataset that genuinely has authorized attendance data
# may add this explicitly; nothing in dataset_validator.py or train_model.py
# requires it.
OPTIONAL_FEATURE_COLUMNS = (
    'attendance_absences',
)

# The ground-truth label. MUST come from a school-approved historical
# definition (e.g. a documented, real intervention/outcome record) — this
# codebase does not invent one. See "NO INVENTED TARGET" below.
TARGET_COLUMN = 'outcome'

ALL_REQUIRED_COLUMNS = CONTEXT_COLUMNS + FEATURE_COLUMNS + (TARGET_COLUMN,)

# Every value TARGET_COLUMN may legitimately take. Kept small and named
# rather than free text, so a typo ("Intervention " with a trailing space)
# is a rejected row, not a silent fourth class nobody intended to create.
VALID_TARGET_VALUES = ('intervention', 'no_intervention')


# ---------------------------------------------------------------------------
# What must NEVER be a feature or appear in a training dataset
# ---------------------------------------------------------------------------

# Real identifying information a school-provided historical export might
# still carry. dataset_validator.py rejects a dataset that contains ANY of
# these column names outright, rather than silently dropping them — a
# dataset built from a file that still has a name/address/LRN column is a
# privacy problem worth stopping on, not quietly fixing.
FORBIDDEN_COLUMNS = (
    'name', 'first_name', 'last_name', 'middle_name',
    'address', 'phone', 'email', 'contact_number',
    'parent_name', 'guardian_name', 'guardian_contact',
    'lrn',  # the real LRN — never a feature; anonymous_student_id is not the LRN itself
)


@dataclass(frozen=True)
class TrainingDataContract:
    """
    A single, importable object describing the contract, for anything
    (dataset_validator.py, train_model.py, a future admin-facing report)
    that wants to introspect it rather than importing individual module
    constants.
    """

    context_columns: tuple = CONTEXT_COLUMNS
    feature_columns: tuple = FEATURE_COLUMNS
    optional_feature_columns: tuple = OPTIONAL_FEATURE_COLUMNS
    target_column: str = TARGET_COLUMN
    valid_target_values: tuple = VALID_TARGET_VALUES
    forbidden_columns: tuple = FORBIDDEN_COLUMNS
    reporting_systems: tuple = REPORTING_SYSTEMS

    def required_columns(self) -> tuple:
        return self.context_columns + self.feature_columns + (self.target_column,)

    def max_period_index(self, reporting_system: str) -> int | None:
        return MAX_PERIOD_INDEX.get(reporting_system)


DEFAULT_CONTRACT = TrainingDataContract()
