"""
Dataset validation, run BEFORE `train_model.py` ever fits anything.

Deliberately plain Python (list-of-dict rows via the stdlib `csv` module) —
no pandas dependency added for this.

Nothing here trains a model or writes a model artifact. `validate_dataset()`
is a pure function: rows in, a structured summary out. Training must not
start automatically after an upload — `train_model.py` refuses to run
against a dataset this validator has not passed.

THE GOVERNING RULE: an invalid record is REPORTED AND REJECTED, never
coerced into a valid-looking training sample. A blank is not a zero, an
out-of-range number is not clamped, an unrecognised outcome is not guessed
at, and an identifying column is not quietly dropped.

CLI:
    python dataset_validator.py path/to/dataset.csv --label "Historical SHS 2023-2026"
"""

from __future__ import annotations

import argparse
import csv
import os
import sys
from collections import Counter
from dataclasses import dataclass, field

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from schema import (
    DEFAULT_CONTRACT,
    FEATURE_RANGES,
    MAX_PERIOD_INDEX,
    OPTIONAL_FEATURE_COLUMNS,
    TrainingDataContract,
)

MAX_REPORTED_ERRORS = 200

# Below this, a class is too thin to split into train and test and still
# mean anything — a "held-out" set containing one intervention row reports
# a recall of either 0% or 100%, neither of which is information.
MIN_RECORDS_PER_CLASS = 10


@dataclass
class RowError:
    row_index: int
    reason: str

    def render(self) -> str:
        """
        One-based and labelled the way a person reading a spreadsheet
        counts rows: row 1 is the header, so the first data row is row 2.
        Off-by-one here would send someone to the wrong line of a
        thousand-row file.
        """
        if self.row_index < 0:
            return f"Dataset-level: {self.reason}"
        return f"Row {self.row_index + 2}: {self.reason}"


@dataclass
class ValidationSummary:
    dataset_label: str
    total_records: int
    valid_records: int
    rejected_records: int
    reporting_system_counts: dict
    target_counts: dict
    errors: list  # list[RowError], capped at MAX_REPORTED_ERRORS
    warnings: list  # non-blocking findings — e.g. class imbalance, possible leakage
    valid_rows: list  # the rows that passed, in original order
    blocking_reasons: list = field(default_factory=list)

    def is_trainable(self) -> bool:
        """
        A hard floor, not a quality judgement. Distinct from the
        (non-blocking) warnings list, which flags things needing a human's
        judgement rather than an unconditional stop.
        """
        return not self.blocking_reasons

    def report(self) -> str:
        """Human-readable summary. This is what a person reads before deciding to train."""
        lines = [
            f"Dataset: {self.dataset_label}",
            f"Records: {self.total_records:,}",
            f"Valid: {self.valid_records:,}",
            f"Rejected: {self.rejected_records:,}",
        ]

        lines.append("Reporting systems:")
        for system, count in sorted(self.reporting_system_counts.items()):
            lines.append(f"  - {system.replace('_', ' ').title()}: {count:,}")

        lines.append("Target:")
        for label, count in sorted(self.target_counts.items()):
            lines.append(f"  - {label.replace('_', ' ').title()}: {count:,}")

        if self.errors:
            shown = self.errors[:20]
            lines.append("")
            lines.append(f"DATASET VALIDATION ERRORS ({len(self.errors)} reported"
                         f"{', capped' if len(self.errors) >= MAX_REPORTED_ERRORS else ''}):")
            for err in shown:
                lines.append(f"  {err.render()}")
            if len(self.errors) > len(shown):
                lines.append(f"  ... and {len(self.errors) - len(shown)} more.")

        if self.warnings:
            lines.append("")
            lines.append("Warnings (not blocking — a human decides):")
            for w in self.warnings:
                lines.append(f"  - {w}")

        lines.append("")
        if self.blocking_reasons:
            lines.append("DATASET VALIDATION FAILED — no model will be trained:")
            for reason in self.blocking_reasons:
                lines.append(f"  - {reason}")
        else:
            lines.append("DATASET VALIDATION PASSED. Trainable, subject to the warnings above.")

        return "\n".join(lines)


def _is_number(value) -> bool:
    try:
        float(value)
        return True
    except (TypeError, ValueError):
        return False


def _blank(value) -> bool:
    return value is None or str(value).strip() == ''


def validate_dataset(
    rows: list,
    dataset_label: str = 'unlabeled dataset',
    contract: TrainingDataContract = DEFAULT_CONTRACT,
) -> ValidationSummary:
    """
    Validates a list of dict rows (e.g. from csv.DictReader) against
    `contract`.

    Dataset-level checks (these fail the WHOLE file — a systematically
    malformed header is a format problem, not a thousand identical row
    errors):
      - every required column is present
      - no forbidden identifying column is present
      - no required feature column is blank in EVERY row

    Per-row checks:
      - supported reporting_system
      - school_year looks like "YYYY-YYYY"
      - grade_level is 11 or 12
      - period_index is valid FOR ITS reporting_system
      - every feature value is numeric, in range, and present when required
      - the target is one of the contract's valid values
      - the (learner, year, system, period) key has not been seen before

    Post-row checks:
      - at least two target classes, each with enough records to split
      - class balance (warning)
      - single-feature perfect separation (warning — possible leakage)
    """
    if not rows:
        return ValidationSummary(
            dataset_label, 0, 0, 0, {}, {}, [], [], [],
            blocking_reasons=['Dataset is empty.'],
        )

    header = set()
    for row in rows:
        header.update(row.keys())

    missing_required = [c for c in contract.required_columns() if c not in header]
    present_forbidden = [c for c in contract.forbidden_columns if c in header]

    if missing_required or present_forbidden:
        errors = []
        blocking = []
        if missing_required:
            errors.append(RowError(-1, f"missing required column(s): {', '.join(missing_required)}"))
            blocking.append(f"Header is missing required column(s): {', '.join(missing_required)}")
        if present_forbidden:
            errors.append(RowError(-1,
                f"prohibited identifying column(s) detected: {', '.join(present_forbidden)}. "
                f"A training dataset must never carry identifying information; this file is rejected rather than "
                f"silently stripped, so the copy that leaves the school is fixed at source."))
            blocking.append(f"Prohibited identifier column(s) present: {', '.join(present_forbidden)}")
        return ValidationSummary(dataset_label, len(rows), 0, len(rows), {}, {}, errors, [], [], blocking)

    errors: list = []
    valid_rows: list = []
    reporting_system_counts: Counter = Counter()
    target_counts: Counter = Counter()
    seen_keys: set = set()
    feature_has_any_value = {name: False for name in contract.feature_columns}

    for i, row in enumerate(rows):
        row_errors = []

        reporting_system = str(row.get('reporting_system') or '').strip()
        if reporting_system not in contract.reporting_systems:
            row_errors.append(
                f"reporting_system = '{reporting_system}'; expected one of {', '.join(contract.reporting_systems)}"
            )

        school_year = str(row.get('school_year') or '').strip()
        if not _looks_like_school_year(school_year):
            row_errors.append(f"school_year = '{school_year}'; expected 'YYYY-YYYY' with consecutive years")

        grade_level = str(row.get('grade_level') or '').strip()
        if grade_level not in ('11', '12'):
            row_errors.append(f"grade_level = '{grade_level}'; expected 11 or 12")

        period_index_raw = str(row.get('period_index') or '').strip()
        max_period = MAX_PERIOD_INDEX.get(reporting_system)
        if not period_index_raw.isdigit() or max_period is None or not (1 <= int(period_index_raw) <= max_period):
            limit = f"1-{max_period}" if max_period else 'unknown (reporting_system is invalid)'
            row_errors.append(
                f"period_index = '{period_index_raw}'; valid range for reporting_system "
                f"'{reporting_system}' is {limit}"
            )

        anonymous_id = str(row.get(contract.grouping_column) or '').strip()
        if not anonymous_id:
            row_errors.append(
                f"{contract.grouping_column} is blank; it is required for de-duplication and for keeping one "
                f"learner's records on one side of the train/test split"
            )

        for feature in contract.feature_columns:
            value = row.get(feature)

            if _blank(value):
                if feature not in OPTIONAL_FEATURE_COLUMNS:
                    row_errors.append(
                        f"{feature} is blank, and it is required. Blank means 'not recorded', which is not a "
                        f"value this model may treat as evidence — and it is never read as 0"
                    )
                continue

            feature_has_any_value[feature] = True

            if not _is_number(value):
                row_errors.append(f"{feature} = '{value}'; expected a number")
                continue

            number = float(value)
            low, high = FEATURE_RANGES[feature]
            if not (low <= number <= high):
                row_errors.append(f"{feature} = {number}; outside the allowed range {low}..{high}")

        target = str(row.get(contract.target_column) or '').strip().lower()
        if not target:
            row_errors.append(
                f"{contract.target_column} is blank. The outcome is a VERIFIED HISTORICAL FACT supplied by the "
                f"school; a row with no recorded outcome is ambiguous and is rejected, never guessed at"
            )
        elif target not in contract.valid_target_values:
            row_errors.append(
                f"{contract.target_column} = '{target}'; expected one of {'/'.join(contract.valid_target_values)}"
            )

        dedup_key = (anonymous_id, school_year, reporting_system, period_index_raw)
        if dedup_key in seen_keys:
            row_errors.append(
                'duplicate learner-period record (same anonymous_student_id, school_year, reporting_system, period_index)'
            )

        if row_errors:
            if len(errors) < MAX_REPORTED_ERRORS:
                errors.append(RowError(i, '; '.join(row_errors)))
            continue

        seen_keys.add(dedup_key)
        reporting_system_counts[reporting_system] += 1
        target_counts[target] += 1
        valid_rows.append(row)

    warnings = _build_warnings(valid_rows, target_counts, contract)
    blocking = _build_blocking_reasons(valid_rows, target_counts, feature_has_any_value, contract)

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
        blocking_reasons=blocking,
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


def _build_blocking_reasons(valid_rows, target_counts, feature_has_any_value, contract) -> list:
    reasons = []

    if not valid_rows:
        reasons.append('No valid records survived row validation.')
        return reasons

    if len(target_counts) < 2:
        present = ', '.join(sorted(target_counts)) or 'none'
        reasons.append(
            f"Only {len(target_counts)} target class present ({present}). A classifier needs both "
            f"{' and '.join(contract.valid_target_values)} to learn anything."
        )

    thin = {label: count for label, count in target_counts.items() if count < MIN_RECORDS_PER_CLASS}
    if thin and len(target_counts) >= 2:
        reasons.append(
            f"Insufficient class representation: {thin} — fewer than {MIN_RECORDS_PER_CLASS} records. "
            f"A held-out metric computed from that few examples is noise, not evidence."
        )

    # A required feature that is blank in EVERY row is a column that exists
    # in name only. Training on it would fit a constant, and worse, would
    # look like the feature was used.
    always_missing = [
        name for name, seen in feature_has_any_value.items()
        if not seen and name not in OPTIONAL_FEATURE_COLUMNS
    ]
    if always_missing:
        reasons.append(
            f"Required feature(s) blank in every single row: {', '.join(always_missing)}. "
            f"The column is present but carries no data."
        )

    return reasons


def _build_warnings(valid_rows, target_counts, contract) -> list:
    warnings: list = []

    if not valid_rows:
        return warnings

    # Class distribution — a non-blocking finding, not a rejection. A human
    # decides whether a 95/5 split is expected (interventions are genuinely
    # rare) or a labelling problem.
    if len(target_counts) >= 2:
        total = sum(target_counts.values())
        minority_share = min(target_counts.values()) / total
        if minority_share < 0.05:
            warnings.append(
                f"Severe class imbalance: minority class is only {minority_share * 100:.1f}% of valid records "
                f"({dict(target_counts)}). Accuracy will be a misleading headline figure here — read the "
                f"per-class recall instead."
            )

    # An always-blank OPTIONAL feature is legal but worth saying out loud:
    # the resulting model genuinely did not use it, whatever its name
    # suggests.
    for feature in contract.feature_columns:
        if feature not in OPTIONAL_FEATURE_COLUMNS:
            continue
        if all(_blank(row.get(feature)) for row in valid_rows):
            warnings.append(
                f"Optional feature '{feature}' is blank in every valid row. The model will be fitted with that "
                f"column entirely missing — do not later describe it as one of the features the model uses."
            )

    # Crude leakage check: does any single numeric feature alone perfectly
    # separate the two target classes? Flagged, never auto-excluded — a
    # feature that is a definitional restatement of the outcome (e.g. a
    # column literally called "already_intervened") IS a real leakage risk;
    # a feature that is merely a strong, legitimate predictor is not, and
    # only a human reviewing the actual column can tell which.
    for feature in contract.feature_columns:
        values_by_class: dict = {}
        for row in valid_rows:
            raw = row.get(feature)
            if _blank(raw):
                continue
            label = str(row[contract.target_column]).strip().lower()
            values_by_class.setdefault(label, []).append(float(raw))

        if len(values_by_class) < 2:
            continue

        ranges = [(min(v), max(v)) for v in values_by_class.values() if v]
        if len(ranges) < 2:
            continue

        disjoint = all(
            ranges[a][1] < ranges[b][0] or ranges[b][1] < ranges[a][0]
            for a in range(len(ranges)) for b in range(a + 1, len(ranges))
        )
        if disjoint:
            warnings.append(
                f"Possible data leakage: feature '{feature}' alone perfectly separates the target classes in "
                f"this dataset — verify it is a legitimate predictor, not a restatement of the outcome."
            )

    return warnings


def validate_csv(path: str, dataset_label: str | None = None) -> ValidationSummary:
    with open(path, newline='', encoding='utf-8') as f:
        rows = list(csv.DictReader(f))
    return validate_dataset(rows, dataset_label=dataset_label or os.path.basename(path))


def _use_utf8_stdout() -> None:
    """
    Windows consoles default to a legacy code page, which mangles the
    em-dashes and arrows in this module's explanatory output. Reconfigure
    rather than strip the punctuation: the report is meant to be read.
    """
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding='utf-8', errors='replace')
        except (AttributeError, OSError):
            pass


def main() -> None:
    _use_utf8_stdout()
    parser = argparse.ArgumentParser(
        description='Validate a historical training dataset. Reports only — never trains, never modifies the file.'
    )
    parser.add_argument('csv_path')
    parser.add_argument('--label', default=None)
    args = parser.parse_args()

    summary = validate_csv(args.csv_path, args.label)
    print(summary.report())
    raise SystemExit(0 if summary.is_trainable() else 1)


if __name__ == '__main__':
    main()
