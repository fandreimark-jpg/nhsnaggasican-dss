"""
LEGACY SYNTHETIC PROTOTYPE — the generator that produced
`analytics/model_cache.pkl`.

THIS IS NOT PART OF THE TRAINING PIPELINE. `analytics/train_model.py` is
the one authoritative training entry point. This file exists for exactly
two reasons:

  1. REPRODUCIBILITY / AUDIT. `model_cache.pkl` is the model currently
     serving production predictions. A model artifact whose construction
     cannot be reproduced and inspected is not auditable, and deleting the
     code that built it would have made the shipped model a black box. A
     reader can run this file and confirm, for themselves, exactly what
     the deployed model was fitted on.
  2. HONESTY. Keeping this code visible — rather than deleting it and
     describing it in prose — is what lets the claim "the prototype's
     labels were predetermined" be checked instead of taken on trust.

WHY THIS MODEL CAN BE DESCRIBED AS HARDCODED
--------------------------------------------
Read `_synthetic_training_set()` below. Every training sample is a single
number (an average grade) that a human typed, and its class was assigned by
the band the human typed it into: >=85 -> low, 75-84.9 -> moderate, else
high. The classifier was then fitted to those labels.

A RandomForest fitted on that data learns the boundaries it was handed. Its
reported cross-validation accuracy is the expected arithmetic result of
testing a one-feature model against perfectly-separable, hand-placed points
— a single `if grade >= 85` statement scores identically. It is not
evidence that the model learned anything about learners, because there were
no learners in the training data.

That was an appropriate thing to build for integration testing: it gave the
Laravel <-> Python contract, the RiskResult write path, the rule-based
override and the Principal's screens something real and deterministic to
run against long before any real outcome data existed. It is not, and was
never, evidence of real-world predictive validity.

WHAT THIS FILE WILL NOT DO
--------------------------
It will not write to `model_cache.pkl`, and it will not write into the model
registry. `--out` is required and must be an explicit path. Regenerating the
deployed artifact is a deliberate operator action with an explicit target,
never a side effect of running a script.

    python analytics/legacy/prototype_model.py --out /tmp/rebuilt.pkl
    python analytics/legacy/prototype_model.py --report-only
"""

from __future__ import annotations

import argparse
import os
import sys
from datetime import datetime

import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import classification_report, confusion_matrix
from sklearn.model_selection import cross_val_predict, cross_val_score

ANALYTICS_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, ANALYTICS_DIR)

# The bands the samples below were typed into. These are NOT learned and
# NOT discovered — they are the calibration decision recorded in CLAUDE.md
# ("The risk classifier's levels are calibrated thresholds, not a learned
# signal"), reproduced here so the label-generation rule is visible in the
# same file as the labels it generated.
BANDS = (
    ('low', 85.0, 100.0),
    ('moderate', 75.0, 84.9),
    ('high', 0.0, 74.9),
)

HYPERPARAMETERS = {
    'n_estimators': 200,
    'max_depth': 5,
    'min_samples_split': 2,
    'min_samples_leaf': 1,
    'random_state': 42,
}


def _synthetic_training_set():
    """
    90 hand-typed average_grade values, 30 per class, labelled by the band
    they were typed into. This function IS the "hardcoded" claim: there is
    no data source here, only literals.
    """
    low_risk = [
        [85.0], [85.5], [86.0], [86.5], [87.0],
        [87.5], [88.0], [88.5], [89.0], [89.5],
        [90.0], [91.0], [92.0], [93.0], [94.0],
        [95.0], [96.0], [97.0], [98.0], [99.0],
        [100.0], [85.3], [86.8], [88.3], [90.3],
        [92.7], [94.5], [96.5], [98.5], [99.2],
    ]
    moderate_risk = [
        [75.0], [75.5], [76.0], [76.5], [77.0],
        [77.5], [78.0], [78.5], [79.0], [79.5],
        [80.0], [80.5], [81.0], [81.5], [82.0],
        [82.5], [83.0], [83.5], [84.0], [84.5],
        [84.9], [75.3], [76.8], [78.3], [79.8],
        [80.3], [81.7], [82.9], [83.5], [84.7],
    ]
    high_risk = [
        [74.9], [73.0], [71.0], [69.0], [67.0],
        [65.0], [63.0], [61.0], [59.0], [56.0],
        [53.0], [50.0], [47.0], [44.0], [41.0],
        [38.0], [35.0], [32.0], [29.0], [26.0],
        [23.0], [20.0], [16.0], [12.0], [8.0],
        [5.0], [3.0], [1.0], [0.5], [0.0],
    ]

    X = np.array(low_risk + moderate_risk + high_risk)
    y = np.array([0] * len(low_risk) + [1] * len(moderate_risk) + [2] * len(high_risk))
    counts = {
        'low': len(low_risk),
        'moderate': len(moderate_risk),
        'high': len(high_risk),
    }
    return X, y, counts


def build_prototype():
    """Fits the prototype exactly as the deployed model_cache.pkl was fitted. Returns (model, X, y, class_counts)."""
    X, y, counts = _synthetic_training_set()
    model = RandomForestClassifier(**HYPERPARAMETERS)
    model.fit(X, y)
    return model, X, y, counts


def write_accuracy_report(path: str) -> str:
    """
    Regenerates the prototype's evaluation report. The caveat travels WITH
    the number, in this order and deliberately: this file gets read, cited
    and screenshotted on its own, and a reader who opens only this file
    must not be able to come away thinking the score means the model works
    on real students.
    """
    model, X, y, class_counts = build_prototype()
    class_names = ['low', 'moderate', 'high']

    scores = cross_val_score(model, X, y, cv=5, scoring='accuracy')
    y_pred = cross_val_predict(model, X, y, cv=5)
    cm = confusion_matrix(y, y_pred)
    report = classification_report(y, y_pred, target_names=class_names, digits=4, zero_division=0)
    avg = round(float(scores.mean()) * 100, 2)

    with open(path, 'w', encoding='utf-8') as f:
        f.write("=" * 78 + "\n")
        f.write("NAGGASICAN NHS DSS — LEGACY SYNTHETIC PROTOTYPE REPORT\n")
        f.write(f"Generated: {datetime.now().isoformat(timespec='seconds')}\n")
        f.write("GENERATED FILE. Not read by any runtime code. Regenerate with:\n")
        f.write("  python analytics/legacy/prototype_model.py --report-only\n")
        f.write("=" * 78 + "\n\n")

        f.write("0. WHAT THIS MODEL IS\n")
        f.write("-" * 78 + "\n")
        f.write("A LEGACY SYNTHETIC PROTOTYPE. It is the model analytics/classify.py\n")
        f.write("currently serves, and it has never been trained or validated against\n")
        f.write("real student outcomes. It exists so the Laravel<->Python contract and\n")
        f.write("the Principal's screens had something deterministic to run against\n")
        f.write("before real outcome data existed. See analytics/README.md.\n\n")

        f.write("1. TRAINING DATA\n")
        f.write("-" * 78 + "\n")
        f.write(f"SYNTHETIC: {len(y)} hand-typed samples, not real student records. Each\n")
        f.write("sample's class was assigned BY CONSTRUCTION from the band it was typed\n")
        f.write("into (>=85 low, 75-84.9 moderate, below 75 high) — the classes are\n")
        f.write("perfectly separable by design, not discovered by the model.\n")
        for label, count in class_counts.items():
            f.write(f"  {label}: {count} samples\n")
        f.write("\n")

        f.write("2. FEATURES USED\n")
        f.write("-" * 78 + "\n")
        f.write("ONE feature: average_grade.\n")
        f.write("Laravel computes and sends the full canonical feature set (see\n")
        f.write("analytics/schema.py) — this model reads only average_grade.\n\n")

        f.write("3. METRICS (5-fold cross-validation on the synthetic training set)\n")
        f.write("-" * 78 + "\n")
        f.write(f"Cross-validation Accuracy: {avg}%\n")
        f.write(f"Per-fold scores: {[round(float(s) * 100, 2) for s in scores]}\n\n")
        f.write("Confusion matrix (rows = actual, columns = predicted; low/moderate/high):\n")
        f.write("             " + "".join(f"{n:>12}" for n in class_names) + "\n")
        for name, row in zip(class_names, cm):
            f.write(f"{name:>12} " + "".join(f"{v:>12}" for v in row) + "\n")
        f.write("\nPrecision / recall / F1 per class:\n")
        f.write(report + "\n")

        f.write("4. WHAT THIS SCORE ACTUALLY MEANS\n")
        f.write("-" * 78 + "\n")
        f.write("A perfect or near-perfect score above is the EXPECTED ARITHMETIC RESULT\n")
        f.write("of testing a single-feature model against hand-picked, perfectly\n")
        f.write("separable training points. It is NOT evidence of real-world predictive\n")
        f.write("validity. A trivial single-threshold rule scores the same on this data.\n\n")

        f.write("5. VALIDATION STATUS\n")
        f.write("-" * 78 + "\n")
        f.write("NEVER validated against real student outcomes. Every number above\n")
        f.write("describes performance on synthetic data only. This is not real-world\n")
        f.write("accuracy, not production accuracy, and not validated school accuracy.\n\n")

        f.write("6. WHAT REAL VALIDATION WOULD REQUIRE\n")
        f.write("-" * 78 + "\n")
        f.write("A school-authorized historical dataset with VERIFIED outcomes per\n")
        f.write("learner-period (see TRAINING_DATA_CONTRACT.md), validated by\n")
        f.write("analytics/dataset_validator.py and trained by analytics/train_model.py,\n")
        f.write("evaluated on a held-out cohort and compared against the transparent\n")
        f.write("rule baseline in analytics/baseline.py.\n\n")

        f.write("7. FEATURE IMPORTANCE (this fitted model)\n")
        f.write("-" * 78 + "\n")
        for name, importance in zip(['average_grade'], model.feature_importances_):
            f.write(f"  {name}: {importance:.4f} ({importance * 100:.1f}%)\n")
        f.write("\nReads as 100% on average_grade because it is the ONLY feature this\n")
        f.write("model was trained on. That triviality IS the finding, not a bug in\n")
        f.write("this report.\n")

    return path


def main() -> None:
    parser = argparse.ArgumentParser(
        description='Rebuild or report on the LEGACY SYNTHETIC PROTOTYPE. Never writes model_cache.pkl and never touches the model registry.'
    )
    parser.add_argument('--out', help='Explicit path to write the rebuilt .pkl to. Required to write a model at all.')
    parser.add_argument('--report-only', action='store_true', help='Only regenerate analytics/model_accuracy.txt.')
    args = parser.parse_args()

    if args.report_only:
        path = write_accuracy_report(os.path.join(ANALYTICS_DIR, 'model_accuracy.txt'))
        print(f'Wrote {path}')
        return

    if not args.out:
        parser.error('--out is required (or use --report-only). This script never picks a destination for you.')

    destination = os.path.abspath(args.out)
    if os.path.basename(destination) == 'model_cache.pkl' and os.path.dirname(destination) == ANALYTICS_DIR:
        parser.error(
            'Refusing to overwrite analytics/model_cache.pkl. That artifact is what production currently serves; '
            'replacing it is a deployment decision, not a script default. Write elsewhere and move it deliberately.'
        )

    import joblib

    model, _, _, _ = build_prototype()
    joblib.dump(model, destination)
    print(f'Rebuilt the legacy synthetic prototype at {destination}')
    print('This model is SYNTHETIC. It must not be described as validated against real outcomes.')


if __name__ == '__main__':
    main()
