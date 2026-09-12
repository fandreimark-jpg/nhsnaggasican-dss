"""
"ML architecture preparation" pass, Phase 9 — the future real-data
training pipeline. NOT wired to anything today: nothing calls this file,
it never touches `classify.py`'s `model_cache.pkl`, and it never
auto-activates what it trains.

DO NOT RUN THIS AGAINST REAL DATA YET. There is no school-authorized
historical dataset in this repository. This file exists so that once one
arrives, running the documented pipeline is a CLI command against a real
CSV, not a from-scratch build.

Pipeline (matches the workflow this pass's task specifies):

    UPLOAD -> DETECT FORMAT -> PARSE -> VALIDATE -> PREVIEW -> NORMALIZE
        -> BUILD DATASET -> TRAIN CANDIDATE -> EVALUATE -> ACTIVATE

This file covers everything from VALIDATE onward, on an ALREADY-normalized
CSV (one row per learner-period record, matching schema.py's contract —
UPLOAD/DETECT FORMAT/PARSE/NORMALIZE for a raw historical workbook is
Phase 6's `HistoricalEcrFormatDetector` plus a not-yet-built per-format
parser, out of scope here). ACTIVATE is deliberately never automatic —
`train_model.py` stops at saving a CANDIDATE; see model_registry.py's
`promote_to_active()`, always a separate, manual call.

Usage (once a real, validated CSV exists):
    python train_model.py path/to/historical_dataset.csv --label "Historical SHS 2023-2026"
"""

from __future__ import annotations

import argparse
import csv
import sys
from datetime import datetime

import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import confusion_matrix, classification_report, accuracy_score
from sklearn.model_selection import train_test_split

from dataset_validator import validate_dataset
from model_registry import ModelMetadata, save_candidate
from schema import DEFAULT_CONTRACT


def load_csv(path: str) -> list[dict]:
    with open(path, newline='', encoding='utf-8') as f:
        return list(csv.DictReader(f))


def build_matrices(valid_rows: list[dict], contract=DEFAULT_CONTRACT):
    """Feature matrix X and label vector y from validated rows. Rejects a row with any missing (blank) feature rather than imputing one — Phase 7's job is to have already caught this; this is a defensive re-check, not the primary gate."""
    label_to_index = {label: i for i, label in enumerate(contract.valid_target_values)}

    X, y, kept_rows = [], [], []
    for row in valid_rows:
        values = []
        skip = False
        for feature in contract.feature_columns:
            raw = row.get(feature)
            if raw is None or str(raw).strip() == '':
                skip = True
                break
            values.append(float(raw))
        if skip:
            continue

        X.append(values)
        y.append(label_to_index[row[contract.target_column].strip().lower()])
        kept_rows.append(row)

    return np.array(X), np.array(y), kept_rows


def cohort_split(rows: list[dict], X: np.ndarray, y: np.ndarray, test_size: float, random_state: int):
    """
    Prefers a COHORT split (train on older school years, test on the
    newest represented one) over a blind random split, per this pass's
    explicit preference — a random split can let a later-year student's
    record leak into training while an earlier-year record from the SAME
    student's sibling year sits in test, which is a weaker test of
    "does this generalize to a school year the model has never seen"
    than a genuine held-out year. Falls back to a stratified random split
    only when fewer than two distinct school years are present (a cohort
    split needs at least two to have a "held-out newest" at all).
    """
    school_years = sorted({row['school_year'] for row in rows})

    if len(school_years) >= 2:
        newest = school_years[-1]
        train_idx = [i for i, row in enumerate(rows) if row['school_year'] != newest]
        test_idx = [i for i, row in enumerate(rows) if row['school_year'] == newest]
        if train_idx and test_idx:
            return (
                X[train_idx], X[test_idx], y[train_idx], y[test_idx],
                {'method': 'cohort', 'train_school_years': school_years[:-1], 'test_school_year': newest},
            )

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=test_size, random_state=random_state, stratify=y if len(set(y)) > 1 else None,
    )
    return X_train, X_test, y_train, y_test, {'method': 'stratified_random', 'school_years': school_years}


def train_candidate(csv_path: str, dataset_label: str, test_size: float = 0.25, random_state: int = 42) -> str:
    rows = load_csv(csv_path)
    summary = validate_dataset(rows, dataset_label=dataset_label)

    print(summary.report())

    if not summary.is_trainable():
        print('\nREFUSING TO TRAIN: dataset did not pass validation (see errors above, or too few valid records/classes).', file=sys.stderr)
        for err in summary.errors[:20]:
            print(f'  row {err.row_index}: {err.reason}', file=sys.stderr)
        raise SystemExit(1)

    X, y, kept_rows = build_matrices(summary.valid_rows)
    if len(kept_rows) < summary.valid_records:
        print(f'\nNote: {summary.valid_records - len(kept_rows)} additional row(s) dropped for a missing feature value at matrix-build time.')

    if len(set(y.tolist())) < 2:
        print('\nREFUSING TO TRAIN: fewer than two target classes present after building the feature matrix.', file=sys.stderr)
        raise SystemExit(1)

    X_train, X_test, y_train, y_test, split_info = cohort_split(kept_rows, X, y, test_size, random_state)
    print(f"\nSplit method: {split_info['method']} ({split_info})")

    model = RandomForestClassifier(
        n_estimators=200, random_state=random_state, max_depth=5,
        min_samples_split=2, min_samples_leaf=1,
    )
    model.fit(X_train, y_train)

    y_pred = model.predict(X_test)
    accuracy = float(accuracy_score(y_test, y_pred))
    report = classification_report(
        y_test, y_pred, target_names=DEFAULT_CONTRACT.valid_target_values,
        output_dict=True, zero_division=0,
    )
    cm = confusion_matrix(y_test, y_pred).tolist()

    version = f"v{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    metadata = ModelMetadata(
        version=version,
        algorithm='RandomForestClassifier(n_estimators=200, max_depth=5)',
        trained_at=datetime.now().isoformat(timespec='seconds'),
        training_record_count=int(len(X_train)),
        school_years_represented=sorted({row['school_year'] for row in kept_rows}),
        reporting_systems_represented=sorted({row['reporting_system'] for row in kept_rows}),
        feature_list=list(DEFAULT_CONTRACT.feature_columns),
        target_definition=f"'{DEFAULT_CONTRACT.target_column}' column: one of {DEFAULT_CONTRACT.valid_target_values}, from a school-approved historical outcome record — never invented by this pipeline",
        accuracy=round(accuracy * 100, 2),
        precision={k: round(v['precision'] * 100, 2) for k, v in report.items() if k in DEFAULT_CONTRACT.valid_target_values},
        recall={k: round(v['recall'] * 100, 2) for k, v in report.items() if k in DEFAULT_CONTRACT.valid_target_values},
        f1={k: round(v['f1-score'] * 100, 2) for k, v in report.items() if k in DEFAULT_CONTRACT.valid_target_values},
        confusion_matrix=cm,
        notes=f"Held-out test set: {len(X_test)} records ({split_info['method']}). Never auto-activated — see model_registry.promote_to_active().",
    )

    saved_version = save_candidate(model, metadata)

    print(f"\nCandidate model saved: {saved_version} (status=candidate, NOT active).")
    print(f"Held-out accuracy: {metadata.accuracy}%")
    print('To activate, a human must explicitly call model_registry.promote_to_active(version) after reviewing these metrics.')

    return saved_version


def main():
    parser = argparse.ArgumentParser(description='Train a CANDIDATE risk model from a validated historical dataset. Never auto-activates.')
    parser.add_argument('csv_path', help='Path to a normalized historical training CSV (see schema.py / TRAINING_DATA_CONTRACT.md).')
    parser.add_argument('--label', default=None, help='Human-readable dataset label for the validation report.')
    parser.add_argument('--test-size', type=float, default=0.25, help='Fraction held out for testing when a cohort split is not possible.')
    args = parser.parse_args()

    train_candidate(args.csv_path, dataset_label=args.label or args.csv_path)


if __name__ == '__main__':
    main()
