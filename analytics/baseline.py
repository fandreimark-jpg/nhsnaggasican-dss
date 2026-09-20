"""
A transparent, non-learned baseline to evaluate any candidate model
AGAINST.

The question a candidate model has to answer is not "is its accuracy
high?" — a cohort where 90% of learners never needed an intervention makes
90% accuracy available to a rule that says "nobody needs one". The question
is whether the model adds predictive value over what the school's existing
academic rules already tell you for free.

So `train_model.py` scores this baseline on the SAME held-out test set as
the candidate and records both sets of metrics in the model's metadata.

WHAT THIS IS NOT
----------------
This is not part of the model, not a feature of it, and not a fallback it
falls through to. It never touches labels: `predict()` reads features and
returns predictions, exactly like the estimator it is compared against, and
`y_test` is untouched by both.

It is also NOT the production rule layer. Laravel's
`Adviser\\ReportController::applyFailingSubjectOverride()` is the live
rule-based safety net that sits AFTER the ML prediction in production; this
module is an evaluation yardstick that sits BESIDE it in the lab. They
encode a similar piece of academic common sense — a failing subject matters
regardless of the average — because that common sense is genuinely the
thing worth beating. Keeping them as two separate pieces of code is
deliberate: changing an evaluation baseline must never change what
production does to a learner's risk level.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import schema as schema_module

# The same 75 used throughout the Laravel side
# (PerformanceAnalysisService::DEFAULT_TARGET). Named, not inlined, so the
# baseline's rule is legible in one place.
PASSING_STANDARD = 75.0

RULE_DESCRIPTION = (
    f"Predict 'intervention' when the learner has at least one failing subject this period, "
    f"or a current_average below {PASSING_STANDARD:.0f}, or at least two weak components "
    f"(of Written Work / Performance Task / Examination). Otherwise predict 'no_intervention'. "
    f"No fitting, no parameters learned from data — this is the academic rule the school already applies."
)


def _value(row_vector, feature_names, name):
    """Reads one named feature out of a positional vector, or None when it is absent/NaN."""
    if name not in feature_names:
        return None
    value = row_vector[feature_names.index(name)]
    try:
        if value != value:  # NaN
            return None
    except TypeError:
        return None
    return float(value)


def predict(X, feature_names) -> list:
    """
    Returns a list of class INDEXES into schema.VALID_TARGET_VALUES, so the
    baseline's output can be scored with the same sklearn metric calls and
    the same label ordering as the candidate model's.

    Missing values are treated as "no evidence for this rule", never as a
    zero: a learner with no exam_mean does not thereby gain a weak
    component, and a learner with no current_average is not thereby
    failing.
    """
    intervention = schema_module.VALID_TARGET_VALUES.index('intervention')
    no_intervention = schema_module.VALID_TARGET_VALUES.index('no_intervention')

    feature_names = list(feature_names)
    predictions = []

    for row in X:
        failing = _value(row, feature_names, 'failing_subject_count')
        average = _value(row, feature_names, 'current_average')
        weak = _value(row, feature_names, 'weak_component_count')

        flagged = (
            (failing is not None and failing >= 1)
            or (average is not None and average < PASSING_STANDARD)
            or (weak is not None and weak >= 2)
        )
        predictions.append(intervention if flagged else no_intervention)

    return predictions


def evaluate(X, y_true, feature_names) -> dict:
    """
    Scores the baseline on the given (already held-out) test set. Returns
    the same metric shape train_model.py records for the candidate, so the
    two are directly comparable in the metadata without a reader having to
    align two different formats.
    """
    from sklearn.metrics import accuracy_score, classification_report, confusion_matrix

    y_pred = predict(X, feature_names)
    labels = list(range(len(schema_module.VALID_TARGET_VALUES)))
    report = classification_report(
        y_true, y_pred, labels=labels,
        target_names=list(schema_module.VALID_TARGET_VALUES),
        output_dict=True, zero_division=0,
    )

    return {
        'rule': RULE_DESCRIPTION,
        'accuracy': round(float(accuracy_score(y_true, y_pred)) * 100, 2),
        'precision': {k: round(report[k]['precision'] * 100, 2) for k in schema_module.VALID_TARGET_VALUES},
        'recall': {k: round(report[k]['recall'] * 100, 2) for k in schema_module.VALID_TARGET_VALUES},
        'f1': {k: round(report[k]['f1-score'] * 100, 2) for k in schema_module.VALID_TARGET_VALUES},
        'confusion_matrix': confusion_matrix(y_true, y_pred, labels=labels).tolist(),
    }
