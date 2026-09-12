"""
"ML architecture preparation" pass, Phase 7 — dataset validation for
FUTURE historical training data, run BEFORE `train_model.py` ever touches
a dataset. Deliberately plain Python (list-of-dict rows via the stdlib
`csv` module), matching classify.py's own `train_from_real_data()` style —
no pandas dependency added for this.

Nothing here trains a model or writes model_cache.pkl. `validate_dataset()`
is a pure function: rows in, a structured summary out. Training must not
start automatically after upload — see train_model.py, which refuses to
run against a dataset this validator has not passed.
"""

from __future__ import annotations

from collections import Counter
from dataclasses import dataclass, field

from schema import DEFAULT_CONTRACT, MAX_PERIOD_INDEX, TrainingDataContract


@dataclass
class RowError:
    row_index: int
    reason: str


@dataclass
class ValidationSummary:
    dataset_label: str
    total_records: int
    valid_records: int
    rejected_records: int
    reporting_system_counts: dict
    target_counts: dict
    errors: list  # list[RowError], kept short (see MAX_REPORTED_ERRORS)
    warnings: list  # non-blocking findings — e.g. class imbalance, possible leakage
    valid_rows: list  # the rows that passed, in original order

    def is_trainable(self) -> bool:
        """
        A hard floor, not a quality judgement — train_model.py refuses to
        run at all below this, distinct from the (non-blocking) warnings
        list, which flags things worth a human's judgement (e.g. class
        imbalance) rather than an unconditional stop.
        """
        return self.valid_records > 0 and len(self.target_counts) >= 2

    def report(self) -> str:
        """Human-readable summary in the shape requested for this pass."""
        lines = [
            f"Dataset: {self.dataset_label}",
            f"Records: {self.total_records}",
            f"Valid: {self.valid_records}",
            f"Rejected: {self.rejected_records}",
            "Reporting systems:",
        ]
        for system, count in sorted(self.reporting_system_counts.items()):
            lines.append(f"  - {system.replace('_', ' ').title()}: {count}")
        lines.append("Target:")
        for label, count in sorted(self.target_counts.items()):
            lines.append(f"  - {label.replace('_', ' ').title()}: {count}")
        if self.warnings:
            lines.append("Warnings:")
            for w in self.warnings:
                lines.append(f"  - {w}")
        return "\n".join(lines)


MAX_REPORTED_ERRORS = 200


def _is_number(value) -> bool:
    try:
        float(value)
        return True
    except (TypeError, ValueError):
        return False


def validate_dataset(
    rows: list[dict],
    dataset_label: str = 'unlabeled dataset',
    contract: TrainingDataContract = DEFAULT_CONTRACT,
) -> ValidationSummary:
    """
    Validates a list of dict rows (e.g. from csv.DictReader) against
    `contract`. Checks, in order:

      - required columns present at all (fails the WHOLE dataset, not
        row-by-row, if the header itself is wrong — a systematically
        malformed file is a format problem, not 1000 individual row
        errors)
      - forbidden (identifying) columns are ABSENT
      - per row: supported reporting_system; school_year looks like
        "YYYY-YYYY"; grade_level is 11 or 12; valid period_index for its
        reporting_system; every numeric feature is actually numeric or
        blank (blank = missing, not zero — never coerced to 0.0); valid
        target label
      - duplicate (anonymous_student_id, school_year, reporting_system,
        period_index) records
      - class distribution (warning only, not a rejection, if lopsided)
      - a crude leakage check: a single feature that alone perfectly
        separates the two target classes is flagged as a warning, not
        silently trusted — the same "a perfect score is a finding, not
        a compliment" stance classify.py's own model_accuracy.txt takes
    """
    if not rows:
        return ValidationSummary(dataset_label, 0, 0, 0, {}, {}, [], ['Dataset is empty.'], [])

    header = set(rows[0].keys())
    missing_required = [c for c in contract.required_columns() if c not in header]
    present_forbidden = [c for c in contract.forbidden_columns if c in header]

    if missing_required:
        return ValidationSummary(
            dataset_label, len(rows), 0, len(rows), {}, {},
            [RowError(-1, f"Dataset header is missing required column(s): {', '.join(missing_required)}")],
            [], [],
        )

    if present_forbidden:
        return ValidationSummary(
            dataset_label, len(rows), 0, len(rows), {}, {},
            [RowError(-1, f"Dataset contains forbidden identifying column(s), never permitted in a training dataset: {', '.join(present_forbidden)}")],
            [], [],
        )

    errors: list[RowError] = []
    valid_rows: list[dict] = []
    reporting_system_counts: Counter = Counter()
    target_counts: Counter = Counter()
    seen_keys: set = set()

    for i, row in enumerate(rows):
        row_errors = []

        reporting_system = (row.get('reporting_system') or '').strip()
        if reporting_system not in contract.reporting_systems:
            row_errors.append(f"unsupported reporting_system '{reporting_system}'")

        school_year = (row.get('school_year') or '').strip()
        if not _looks_like_school_year(school_year):
            row_errors.append(f"school_year '{school_year}' does not look like 'YYYY-YYYY'")

        grade_level = (row.get('grade_level') or '').strip()
        if grade_level not in ('11', '12'):
            row_errors.append(f"grade_level must be 11 or 12, got '{grade_level}'")

        period_index_raw = (row.get('period_index') or '').strip()
        max_period = MAX_PERIOD_INDEX.get(reporting_system)
        if not period_index_raw.isdigit() or max_period is None or not (1 <= int(period_index_raw) <= max_period):
            row_errors.append(f"period_index '{period_index_raw}' is invalid for reporting_system '{reporting_system}'")

        for feature in contract.feature_columns:
            value = row.get(feature)
            if value is not None and str(value).strip() != '' and not _is_number(value):
                row_errors.append(f"feature '{feature}' is not numeric: '{value}'")

        target = (row.get(contract.target_column) or '').strip().lower()
        if target not in contract.valid_target_values:
            row_errors.append(f"'{contract.target_column}' must be one of {contract.valid_target_values}, got '{target}'")

        dedup_key = (
            row.get('anonymous_student_id'), school_year, reporting_system, period_index_raw,
        )
        if dedup_key in seen_keys:
            row_errors.append('duplicate learner-period record (same student, school_year, reporting_system, period_index)')

        if row_errors:
            if len(errors) < MAX_REPORTED_ERRORS:
                errors.append(RowError(i, '; '.join(row_errors)))
            continue

        seen_keys.add(dedup_key)
        reporting_system_counts[reporting_system] += 1
        target_counts[target] += 1
        valid_rows.append(row)

    warnings = _build_warnings(valid_rows, target_counts, contract)

    return ValidationSummary(
        dataset_label=dataset_label,
        total_records=len(rows),
        valid_records=len(valid_rows),
        rejected_records=len(rows) - len(valid_rows),
        reporting_system_counts=dict(reporting_system_counts),
        target_counts=dict(target_counts),
        errors=errors,
        warnings=warnings,
        valid_rows=valid_rows,
    )


def _looks_like_school_year(value: str) -> bool:
    parts = value.split('-')
    if len(parts) != 2:
        return False
    try:
        start, end = int(parts[0]), int(parts[1])
    except ValueError:
        return False
    return end == start + 1 and 2000 <= start <= 2100


def _build_warnings(valid_rows: list[dict], target_counts: Counter, contract: TrainingDataContract) -> list[str]:
    warnings: list[str] = []

    if len(valid_rows) == 0:
        return warnings

    # Class distribution — a non-blocking finding, not a rejection. A
    # human decides whether a 95/5 split is expected (interventions are
    # genuinely rare) or a labelling problem.
    if len(target_counts) >= 2:
        total = sum(target_counts.values())
        minority_share = min(target_counts.values()) / total
        if minority_share < 0.05:
            warnings.append(
                f"Severe class imbalance: minority class is only {minority_share * 100:.1f}% of valid records ({dict(target_counts)})."
            )

    # Crude leakage check: does any single numeric feature alone perfectly
    # separate the two target classes? Flagged, never auto-excluded — a
    # feature that is a definitional restatement of the outcome (e.g. a
    # column literally called "already_intervened") IS a real leakage
    # risk; a feature that is merely a strong, legitimate predictor is
    # not, and only a human reviewing the actual column can tell which.
    for feature in contract.feature_columns:
        values_by_class: dict = {}
        for row in valid_rows:
            raw = row.get(feature)
            if raw is None or str(raw).strip() == '':
                continue
            values_by_class.setdefault(row[contract.target_column].strip().lower(), []).append(float(raw))

        if len(values_by_class) < 2:
            continue

        ranges = [(min(v), max(v)) for v in values_by_class.values() if v]
        if len(ranges) < 2:
            continue

        # Perfectly separable if every class's [min, max] range is
        # disjoint from every other class's range.
        disjoint = all(
            ranges[a][1] < ranges[b][0] or ranges[b][1] < ranges[a][0]
            for a in range(len(ranges)) for b in range(a + 1, len(ranges))
        )
        if disjoint:
            warnings.append(
                f"Possible data leakage: feature '{feature}' alone perfectly separates the target classes in this dataset — verify it is a legitimate predictor, not a restatement of the outcome."
            )

    return warnings
