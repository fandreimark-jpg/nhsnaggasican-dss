"""
THE AUTHORITATIVE TRAINING ENTRY POINT. The only one.

It never writes `model_cache.pkl`, never promotes what it trains, and never
invents a label. It reads a validated CSV, fits a CANDIDATE, evaluates it
against a held-out set and a transparent baseline, and saves it with enough
metadata to be audited later.

    VALIDATE -> SPLIT -> FIT CANDIDATE -> EVALUATE -> COMPARE TO BASELINE
        -> SAVE CANDIDATE -> (human review) -> PROMOTE

The last two arrows are separate commands run by a person. `train_model.py`
stops at SAVE CANDIDATE. Promotion is `model_registry.py promote <version>`.

THERE IS NO AUTHORIZED HISTORICAL DATASET IN THIS REPOSITORY. Running this
against synthetic fixtures is how the pipeline is tested; the resulting
model is tagged `dataset_type=synthetic` and `model_registry.py promote`
refuses to activate it.

Usage:
    python train_model.py data.csv --dataset-type real_historical --label "Historical SHS 2023-2026"
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import os
import sys
from collections import Counter
from datetime import datetime

import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix
from sklearn.model_selection import GroupShuffleSplit, train_test_split

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import baseline as baseline_module
import schema as schema_module
from dataset_validator import validate_dataset
from model_registry import ModelMetadata, runtime_versions, save_candidate

# Hyperparameters. THESE ARE NOT ACADEMIC RULES and they are not claimed to
# be optimal — no model-selection procedure has been run, because there is
# no real dataset to run one against. They are the prototype's values, kept
# for continuity, and chosen here for REPRODUCIBILITY and EXPLAINABILITY
# over performance: a depth-5 forest with a fixed seed produces the same
# model twice and trees shallow enough to inspect. Tuning them is a
# documented, separate exercise on real data, and whatever comes out of it
# must be recorded in metadata as a tuned result, not presented as these
# defaults having been right all along.
DEFAULT_HYPERPARAMETERS = {
    'n_estimators': 200,
    'max_depth': 5,
    'min_samples_split': 2,
    'min_samples_leaf': 1,
    'random_state': 42,
}


def load_csv(path: str) -> list:
    with open(path, newline='', encoding='utf-8') as f:
        return list(csv.DictReader(f))


def dataset_hash(path: str) -> str:
    """sha256 of the exact bytes trained on, so a metric can be tied to a file rather than to a filename someone may reuse."""
    digest = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(65536), b''):
            digest.update(chunk)
    return digest.hexdigest()


def build_matrices(valid_rows: list, contract=schema_module.DEFAULT_CONTRACT):
    """
    Feature matrix X (NaN for a blank optional feature — see
    schema.MISSING_VALUE_POLICY), label vector y, and the group vector used
    to keep one learner out of both sides of the split.

    Column order comes from `schema.build_feature_vector`, never from dict
    iteration order.
    """
    label_to_index = {label: i for i, label in enumerate(contract.valid_target_values)}

    X, y, groups, kept_rows = [], [], [], []
    for row in valid_rows:
        X.append(schema_module.build_feature_vector(row, contract.feature_columns))
        y.append(label_to_index[str(row[contract.target_column]).strip().lower()])
        groups.append(str(row[contract.grouping_column]).strip())
        kept_rows.append(row)

    return np.array(X, dtype=float), np.array(y), np.array(groups), kept_rows


def choose_split(rows: list, X, y, groups, test_size: float, random_state: int):
    """
    Three strategies, in descending order of how well they answer the
    question a deployed model actually faces ("can it call a cohort it has
    never seen?"):

    1. COHORT / TIME-AWARE. Train on the older school years, test on the
       newest completed one. This is the real question: next year's
       learners are not a random sample of this year's. Requires at least
       two school years, and enough of both classes on each side to score.
    2. GROUPED RANDOM. Same learner never appears on both sides. A learner
       contributes several period rows, and those rows are highly
       correlated; a blind row-level split would let the model see
       learner X in Term 1 and be tested on learner X in Term 2, which
       measures memorisation, not generalisation.
    3. STRATIFIED RANDOM. Only when there is one school year AND one row
       per learner, so neither of the above has anything to hold out.

    Whichever is used is recorded in the model's metadata. A reader must
    never have to guess which test they are looking at the numbers from.
    """
    school_years = sorted({row['school_year'] for row in rows})

    if len(school_years) >= 2:
        newest = school_years[-1]
        train_idx = [i for i, row in enumerate(rows) if row['school_year'] != newest]
        test_idx = [i for i, row in enumerate(rows) if row['school_year'] == newest]
        if train_idx and test_idx and len(set(y[train_idx])) > 1 and len(set(y[test_idx])) > 1:
            return X[train_idx], X[test_idx], y[train_idx], y[test_idx], {
                'method': 'cohort_time_aware',
                'rationale': 'Train on older school years, test on the newest completed one — the closest available '
                             'analogue of predicting a future cohort.',
                'train_school_years': school_years[:-1],
                'test_school_year': newest,
                'leakage_control': 'A school year appears on exactly one side, so no learner-year can span both.',
            }

    if len(set(groups)) < len(groups):
        splitter = GroupShuffleSplit(n_splits=1, test_size=test_size, random_state=random_state)
        train_idx, test_idx = next(splitter.split(X, y, groups))
        if len(set(y[train_idx])) > 1 and len(set(y[test_idx])) > 1:
            return X[train_idx], X[test_idx], y[train_idx], y[test_idx], {
                'method': 'grouped_random',
                'rationale': 'Only one school year is represented, and learners contribute multiple period rows. '
                             'Split by learner so no learner appears on both sides.',
                'group_column': schema_module.GROUPING_COLUMN,
                'test_size': test_size,
                'random_state': random_state,
                'leakage_control': 'Grouped by anonymous_student_id.',
            }

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=test_size, random_state=random_state,
        stratify=y if len(set(y.tolist())) > 1 else None,
    )
    return X_train, X_test, y_train, y_test, {
        'method': 'stratified_random',
        'rationale': 'One school year and one row per learner — no cohort or group structure available to hold out.',
        'test_size': test_size,
        'random_state': random_state,
        'leakage_control': 'None beyond row-level separation; each learner contributes a single row.',
    }


def _class_distribution(y) -> dict:
    counts = Counter(int(v) for v in y)
    return {
        label: counts.get(i, 0)
        for i, label in enumerate(schema_module.VALID_TARGET_VALUES)
    }


def evaluate(model, X_test, y_test) -> dict:
    """
    More than accuracy, deliberately.

    Per-class precision and recall both matter and are BOTH reported. Low
    recall on `intervention` means learners who needed attention were
    missed — the costly error for a DSS. Low precision on `intervention`
    means staff time spent on learners who did not need it, which is a real
    cost too, and is exactly what optimising recall alone buys. Neither is
    a target to maximise on its own, and this function sets no threshold
    for either: no deployment criterion has been approved by the school, so
    inventing one here would be putting a number the school never agreed to
    in front of a decision they have to make.
    """
    labels = list(range(len(schema_module.VALID_TARGET_VALUES)))
    names = list(schema_module.VALID_TARGET_VALUES)

    y_pred = model.predict(X_test)
    report = classification_report(
        y_test, y_pred, labels=labels, target_names=names,
        output_dict=True, zero_division=0,
    )

    return {
        'accuracy': round(float(accuracy_score(y_test, y_pred)) * 100, 2),
        'precision': {k: round(report[k]['precision'] * 100, 2) for k in names},
        'recall': {k: round(report[k]['recall'] * 100, 2) for k in names},
        'f1': {k: round(report[k]['f1-score'] * 100, 2) for k in names},
        'confusion_matrix': confusion_matrix(y_test, y_pred, labels=labels).tolist(),
        'text_report': classification_report(
            y_test, y_pred, labels=labels, target_names=names, digits=4, zero_division=0
        ),
    }


def train_candidate(
    csv_path: str,
    dataset_label: str,
    dataset_type: str = 'synthetic',
    test_size: float = 0.25,
    random_state: int = 42,
    hyperparameters: dict | None = None,
    notes: str = '',
) -> str:
    if dataset_type not in schema_module.DATASET_TYPES:
        raise SystemExit(f"--dataset-type must be one of {schema_module.DATASET_TYPES}, got '{dataset_type}'")

    contract = schema_module.DEFAULT_CONTRACT
    rows = load_csv(csv_path)
    summary = validate_dataset(rows, dataset_label=dataset_label, contract=contract)

    print(summary.report())

    if not summary.is_trainable():
        print('\nREFUSING TO TRAIN. The dataset did not pass validation (see above). '
              'No model was fitted and nothing was written.', file=sys.stderr)
        raise SystemExit(1)

    X, y, groups, kept_rows = build_matrices(summary.valid_rows, contract)

    X_train, X_test, y_train, y_test, split_info = choose_split(
        kept_rows, X, y, groups, test_size, random_state
    )
    print(f"\nSplit: {split_info['method']} — {split_info['rationale']}")
    print(f"Train records: {len(X_train):,}   Held-out test records: {len(X_test):,}")

    params = dict(DEFAULT_HYPERPARAMETERS)
    if hyperparameters:
        params.update(hyperparameters)

    model = RandomForestClassifier(**params)
    model.fit(X_train, y_train)

    metrics = evaluate(model, X_test, y_test)
    baseline_metrics = baseline_module.evaluate(X_test, y_test, contract.feature_columns)

    # Straight from the FITTED model — never a hand-written figure. Zipped
    # against the same ordered feature tuple the matrix was built from, so
    # a name can never drift off its column.
    importances = {
        name: round(float(value), 6)
        for name, value in zip(contract.feature_columns, model.feature_importances_)
    }

    version = f"v{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    metadata = ModelMetadata(
        version=version,
        algorithm=f"sklearn.ensemble.RandomForestClassifier({', '.join(f'{k}={v}' for k, v in sorted(params.items()))})",
        trained_at=datetime.now().isoformat(timespec='seconds'),
        feature_names=list(contract.feature_columns),
        target_definition=contract.target_definition,
        feature_schema_version=schema_module.FEATURE_SCHEMA_VERSION,
        target_classes=list(contract.valid_target_values),
        prediction_domain='binary_outcome',
        dataset_type=dataset_type,
        dataset_label=dataset_label,
        dataset_hash=dataset_hash(csv_path),
        training_record_count=int(len(X_train)),
        test_record_count=int(len(X_test)),
        class_distribution=_class_distribution(y),
        train_class_distribution=_class_distribution(y_train),
        test_class_distribution=_class_distribution(y_test),
        school_years_represented=sorted({row['school_year'] for row in kept_rows}),
        reporting_systems_represented=sorted({row['reporting_system'] for row in kept_rows}),
        hyperparameters=params,
        split_methodology=split_info,
        missing_value_strategy=schema_module.MISSING_VALUE_STRATEGY,
        missing_value_policy=schema_module.MISSING_VALUE_POLICY,
        accuracy=metrics['accuracy'],
        precision=metrics['precision'],
        recall=metrics['recall'],
        f1=metrics['f1'],
        confusion_matrix=metrics['confusion_matrix'],
        feature_importances=importances,
        baseline_comparison={
            'baseline': baseline_metrics,
            'candidate': {k: metrics[k] for k in ('accuracy', 'precision', 'recall', 'f1', 'confusion_matrix')},
            'interpretation': 'The baseline is the school\'s existing academic rule, scored on the SAME held-out '
                              'records. If the candidate does not beat it, the honest conclusion is that ML adds no '
                              'value here yet — not that the dataset needs adjusting until it does.',
        },
        runtime_versions=runtime_versions(),
        validation_status='not_reviewed',
        limitations=_limitations(dataset_type, split_info, summary),
        notes=notes or f"Candidate only. Held-out test: {len(X_test)} records via {split_info['method']}.",
    )

    saved_version = save_candidate(model, metadata)

    _print_evaluation(metadata, metrics, baseline_metrics)

    print(f"\nCandidate saved: {saved_version}  (status = candidate, NOT active)")
    print("Nothing in production changed. To activate, after reviewing the metrics above:")
    print(f"    python analytics/model_registry.py promote {saved_version}")
    if dataset_type == 'synthetic':
        print("\nThis candidate was trained on SYNTHETIC data. `promote` will refuse it, by design.")

    return saved_version


def _limitations(dataset_type: str, split_info: dict, summary) -> list:
    limitations = []
    if dataset_type == 'synthetic':
        limitations.append(
            'SYNTHETIC DATASET. Every metric on this model describes fabricated rows. It is not real-world '
            'accuracy, not production accuracy, and not validated school accuracy.'
        )
    if split_info['method'] == 'stratified_random':
        limitations.append(
            'Evaluated on a random split, not a held-out cohort — a weaker test of whether it generalises to a '
            'school year it has never seen.'
        )
    for warning in summary.warnings:
        limitations.append(f'Validation warning carried forward: {warning}')
    limitations.append(
        'No deployment acceptance threshold has been approved by the school. These metrics require human review; '
        'no pass/fail criterion is encoded here.'
    )
    return limitations


def _print_evaluation(metadata: ModelMetadata, metrics: dict, baseline_metrics: dict) -> None:
    print('\n' + '=' * 72)
    print('HELD-OUT EVALUATION')
    print('=' * 72)
    print(f"Train / test records: {metadata.training_record_count:,} / {metadata.test_record_count:,}")
    print(f"Test class distribution: {metadata.test_class_distribution}")
    print(f"\nAccuracy: {metrics['accuracy']}%")
    print('\nPer class:')
    print(metrics['text_report'])
    print('Confusion matrix (rows = actual, columns = predicted; order '
          f"{'/'.join(schema_module.VALID_TARGET_VALUES)}):")
    for name, row in zip(schema_module.VALID_TARGET_VALUES, metrics['confusion_matrix']):
        print(f"  {name:>16} " + ''.join(f'{v:>8}' for v in row))

    print('\n' + '-' * 72)
    print('BASELINE COMPARISON (same held-out records, no fitting)')
    print('-' * 72)
    print(baseline_metrics['rule'])
    print(f"\n{'':<24}{'candidate':>12}{'baseline':>12}")
    print(f"{'accuracy':<24}{metrics['accuracy']:>11}%{baseline_metrics['accuracy']:>11}%")
    for label in schema_module.VALID_TARGET_VALUES:
        print(f"{'recall (' + label + ')':<24}{metrics['recall'][label]:>11}%{baseline_metrics['recall'][label]:>11}%")
        print(f"{'precision (' + label + ')':<24}{metrics['precision'][label]:>11}%{baseline_metrics['precision'][label]:>11}%")

    print('\nRead recall and precision on `intervention` together: recall is the learners who needed attention and')
    print('were found; precision is how much of the flagged group genuinely needed it. Raising one lowers the other,')
    print('and which trade-off is acceptable is the school\'s decision, not this script\'s.')

    print('\n' + '-' * 72)
    print('FEATURE IMPORTANCE (from the fitted model — not hand-written)')
    print('-' * 72)
    for name, value in sorted(metadata.feature_importances.items(), key=lambda kv: -kv[1]):
        bar = '#' * int(round(value * 40))
        print(f"  {name:<26}{value:>8.4f}  {bar}")
    print('\nImportance describes what this forest split on. It is not evidence of causation, and a feature scoring')
    print('low is not proof it does not matter to a learner.')


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
        description='Train a CANDIDATE risk model from a validated historical dataset. Never auto-activates anything.'
    )
    parser.add_argument('csv_path', help='Normalized training CSV (see schema.py / TRAINING_DATA_CONTRACT.md).')
    parser.add_argument('--dataset-type', required=True, choices=list(schema_module.DATASET_TYPES),
                        help="Required, and recorded in the model's metadata. There is no default: whether a dataset "
                             "is real or synthetic is the single most important thing about a reported metric, and it "
                             "must be stated, not assumed.")
    parser.add_argument('--label', default=None, help='Human-readable dataset label for the report and metadata.')
    parser.add_argument('--test-size', type=float, default=0.25,
                        help='Held-out fraction when a cohort split is not available (default 0.25).')
    parser.add_argument('--random-state', type=int, default=42, help='Fixed for reproducibility (default 42).')
    parser.add_argument('--notes', default='', help='Free-text note stored in the metadata.')
    args = parser.parse_args()

    train_candidate(
        args.csv_path,
        dataset_label=args.label or os.path.basename(args.csv_path),
        dataset_type=args.dataset_type,
        test_size=args.test_size,
        random_state=args.random_state,
        notes=args.notes,
    )


if __name__ == '__main__':
    main()
