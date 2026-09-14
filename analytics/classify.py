import sys
import json
import os
import argparse
from datetime import datetime
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import cross_val_score, cross_val_predict, train_test_split
from sklearn.metrics import confusion_matrix, classification_report

# ============================================================================
# TRAINING DATA LIMITATION (documented per project policy — do not remove)
#
# This model is trained on ONE feature (average_grade) against 90 PURELY
# SYNTHETIC samples that are just clean numeric boundaries matching the
# calibrated thresholds below (85-100 / 75-84.9 / 0-74.9 — see
# train_model()'s docstring for why these were recalibrated from the
# original 90/75/60 split). The 100% cross-validation
# accuracy reported in model_accuracy.txt is EXPECTED, not evidence of
# real-world validity — a single-feature threshold problem with hand-picked,
# perfectly-separable training points will always score perfectly. This
# model has never been trained or validated against real student outcomes.
#
# Laravel (see App\Services\RiskFeatureExtractor and
# Adviser\ReportController::runAnalytics) now sends a richer feature set —
# ww_mean, pt_mean, exam_mean, failing_subject_count, weak_component_count,
# prev_term_average, trend_delta — alongside average_grade. classify_students()
# below intentionally does NOT read any of them yet: retraining to actually
# use them now would trade this simple, well-understood model for a more
# complex one with no real assessment data yet to validate it against (the
# assessment-evidence layer was only just built). The extra fields are
# included in the payload so that once real data has accumulated, doing that
# retrain is a small follow-up with something real to check it against —
# not a second speculative rebuild. Any future retrain must be validated
# against real (not synthetic) student outcomes before being trusted, and
# this comment must be updated to reflect what was actually validated.
# ============================================================================

# Path where the trained model is cached after first run
# Avoids retraining on every report submission — improves performance
MODEL_PATH = os.path.join(os.path.dirname(__file__), 'model_cache.pkl')


def get_model():
    """Load the deployed model. Missing artifacts require explicit operator action."""
    import joblib

    if not os.path.isfile(MODEL_PATH):
        raise FileNotFoundError('The deployed analytics model is missing. Restore the approved model artifact; automatic training is disabled.')
    return joblib.load(MODEL_PATH)


def train_model():
    """
    Train the Random Forest classifier using DepEd SHS grading thresholds.

    "Correctness and interface pass" TASK 3 — RECALIBRATED. The prior
    bands (low >=90, moderate 75-89, high 60-74, with <60 hardcoded to
    high) put nearly the school's entire passing cohort in one 15-point
    "moderate" band: a learner averaging 87 and one averaging 79 both
    read as moderate, telling the Principal nothing (observed live: 39 of
    40 learners moderate, 1 low, 0 high — see CLAUDE.md's "Known
    limitations"). These bands are still a single-feature (average_grade)
    threshold — that limitation is NOT solved here, only calibrated so
    the three levels correspond to something meaningful under THIS
    school's grading, anchored to figures already used elsewhere in this
    codebase (PerformanceAnalysisService::DEFAULT_TARGET = 75,
    InTermStatusService::FAILING_THRESHOLD = 74) rather than percentiles
    of any one section's snapshot, which would not generalize:

    - LOW RISK      : 85 - 100 (DepEd "Very Satisfactory"/"Outstanding" —
                       comfortably above the 75 passing mark)
    - MODERATE RISK : 75 - 84.9 (DepEd "Fairly Satisfactory"/
                       "Satisfactory" — passing, but close to the line)
    - HIGH RISK     : 0 - 74.9 (below the DepEd passing mark — genuinely
                       failing, not merely "not excellent")

    High now covers the FULL 0-74.9 range directly in training data,
    rather than a 60-74 band with a hardcoded `< 60` override — that
    override existed because the old High band's training data (60-74)
    left grades below 60 outside anything the model had seen; extending
    the band itself removes the need for a bypass. See
    CannotDeliverUndecidedInterventionTest for an unrelated task's tests —
    this one's calibration check lives in test_classify.py's
    TestRiskLevelDistribution.

    Training data: 30 samples per class = 90 total.
    Balanced dataset — equal samples per class prevents model bias.
    """

    # LOW RISK samples — grades from 85 to 100 (30 samples)
    low_risk = [
        [85.0], [85.5], [86.0], [86.5], [87.0],
        [87.5], [88.0], [88.5], [89.0], [89.5],
        [90.0], [91.0], [92.0], [93.0], [94.0],
        [95.0], [96.0], [97.0], [98.0], [99.0],
        [100.0],[85.3], [86.8], [88.3], [90.3],
        [92.7], [94.5], [96.5], [98.5], [99.2],
    ]

    # MODERATE RISK samples — grades from 75 to 84.9 (30 samples)
    moderate_risk = [
        [75.0], [75.5], [76.0], [76.5], [77.0],
        [77.5], [78.0], [78.5], [79.0], [79.5],
        [80.0], [80.5], [81.0], [81.5], [82.0],
        [82.5], [83.0], [83.5], [84.0], [84.5],
        [84.9], [75.3], [76.8], [78.3], [79.8],
        [80.3], [81.7], [82.9], [83.5], [84.7],
    ]

    # HIGH RISK samples — grades from 0 to 74.9 (30 samples), spread
    # across the FULL failing range rather than only 60-74 (see the
    # docstring above on why the old <60 hardcoded override is gone).
    high_risk = [
        [74.9], [73.0], [71.0], [69.0], [67.0],
        [65.0], [63.0], [61.0], [59.0], [56.0],
        [53.0], [50.0], [47.0], [44.0], [41.0],
        [38.0], [35.0], [32.0], [29.0], [26.0],
        [23.0], [20.0], [16.0], [12.0], [8.0],
        [5.0], [3.0], [1.0], [0.5], [0.0],
    ]

    X_train = np.array(low_risk + moderate_risk + high_risk)

    # Labels: 0 = low, 1 = moderate, 2 = high
    y_train = np.array(
        [0] * len(low_risk) +
        [1] * len(moderate_risk) +
        [2] * len(high_risk)
    )

    # Random Forest with 200 decision trees
    # n_estimators=200: more trees = more votes = more accurate classification
    # random_state=42: ensures reproducible results on every run
    # max_depth=5: prevents overfitting by limiting tree depth
    model = RandomForestClassifier(
        n_estimators=200,
        random_state=42,
        max_depth=5,
        min_samples_split=2,
        min_samples_leaf=1,
    )
    model.fit(X_train, y_train)

    # Cross-validation — evaluates model accuracy on unseen FOLDS of this
    # same synthetic dataset (never on real student outcomes — see
    # TRAINING DATA LIMITATION above). cv=5: splits data into 5 folds,
    # tests on each fold once. cross_val_predict below reuses the exact
    # same 5-fold split to produce the confusion matrix / per-class
    # report from genuinely held-out-per-fold predictions, not
    # trivially-perfect in-sample ones — see write_model_accuracy_report().
    try:
        class_names = ['low', 'moderate', 'high']
        scores = cross_val_score(model, X_train, y_train, cv=5, scoring='accuracy')
        cv_predictions = cross_val_predict(model, X_train, y_train, cv=5)

        write_model_accuracy_report(
            model=model,
            scores=scores,
            y_true=y_train,
            y_pred=cv_predictions,
            class_names=class_names,
            class_counts={'Low risk': len(low_risk), 'Moderate risk': len(moderate_risk), 'High risk': len(high_risk)},
        )
    except Exception:
        pass  # Reporting failure is non-critical — it must never block classification.

    return model


def write_model_accuracy_report(model, scores, y_true, y_pred, class_names, class_counts):
    """
    Rewrites model_accuracy.txt so the caveat travels WITH the number —
    see the "honest model evaluation" prompt. This file gets read,
    cited, and screenshotted on its own; a reader who opens only this
    file, with no other context, must not be able to come away thinking
    100% means the model works on real students.

    Order matters and is deliberate:
      1. what the training data actually is (synthetic, hand-constructed)
      2. what feature it uses (one of the seven Laravel sends)
      3. the metrics (accuracy, per-fold, confusion matrix, per-class
         precision/recall/F1, class distribution)
      4. IMMEDIATELY after the metrics — not at the bottom under a
         heading someone would skip — the plain-language reason a
         perfect score here is expected arithmetic, not a finding
      5. that it has never been validated against real outcomes
      6. what real validation would actually require
      7. WORK ORDER Part 6c — the trained model's own feature_importances_,
         direct numeric evidence of point 2 in the model's own numbers
         rather than only a comment. Single-feature training makes this
         trivially [1.0]; that triviality IS the finding, not a bug in
         this report.
    """
    avg = round(scores.mean() * 100, 2)
    cm = confusion_matrix(y_true, y_pred)
    report = classification_report(y_true, y_pred, target_names=class_names, digits=4, zero_division=0)

    log_path = os.path.join(os.path.dirname(__file__), 'model_accuracy.txt')
    with open(log_path, 'w', encoding='utf-8') as f:
        f.write("=" * 78 + "\n")
        f.write("NAGGASICAN NHS DSS — RISK CLASSIFIER MODEL REPORT\n")
        f.write(f"Generated: {datetime.now().isoformat(timespec='seconds')}\n")
        f.write("=" * 78 + "\n\n")

        f.write("1. TRAINING DATA\n")
        f.write("-" * 78 + "\n")
        f.write(f"SYNTHETIC data: {len(y_true)} hand-constructed samples, not real student\n")
        f.write("records. Each sample's class was assigned by construction according to\n")
        f.write("the DepEd grade thresholds below — the classes are perfectly separable\n")
        f.write("by design, not discovered by the model.\n")
        for label, count in class_counts.items():
            f.write(f"  {label}: {count} samples\n")
        f.write("\n")

        f.write("2. FEATURES USED\n")
        f.write("-" * 78 + "\n")
        f.write("ONE feature: average_grade.\n")
        f.write("Laravel sends SEVEN features per student (average_grade, ww_mean,\n")
        f.write("pt_mean, exam_mean, weak_component_count, prev_term_average,\n")
        f.write("trend_delta) — this model reads only the first. See the TRAINING DATA\n")
        f.write("LIMITATION comment at the top of classify.py for why.\n\n")

        f.write("3. METRICS (5-fold cross-validation on the synthetic training set)\n")
        f.write("-" * 78 + "\n")
        f.write(f"Cross-validation Accuracy: {avg}%\n")
        f.write(f"Per-fold scores: {[round(float(s) * 100, 2) for s in scores]}\n\n")

        f.write("Confusion matrix (rows = actual, columns = predicted; order low/moderate/high):\n")
        header = "             " + "".join(f"{name:>12}" for name in class_names)
        f.write(header + "\n")
        for name, row in zip(class_names, cm):
            f.write(f"{name:>12} " + "".join(f"{v:>12}" for v in row) + "\n")
        f.write("\n")

        f.write("Precision / recall / F1 per class:\n")
        f.write(report + "\n")

        f.write("4. WHAT THIS SCORE ACTUALLY MEANS\n")
        f.write("-" * 78 + "\n")
        f.write("A perfect or near-perfect score above is the EXPECTED ARITHMETIC RESULT\n")
        f.write("of testing a single-feature model against hand-picked, perfectly\n")
        f.write("separable training points (every value >=85 was labelled low, 75-84.9\n")
        f.write("moderate, below 75 high, by construction) — it is NOT evidence that this\n")
        f.write("model has learned anything predictive about real students, and it is\n")
        f.write("NOT evidence of real-world predictive validity. A trivial single-\n")
        f.write("threshold rule would score the same on this data.\n\n")

        f.write("5. VALIDATION STATUS\n")
        f.write("-" * 78 + "\n")
        f.write("This model has NEVER been trained or validated against real student\n")
        f.write("outcomes. Every number above describes performance on synthetic data\n")
        f.write("only.\n\n")

        f.write("6. WHAT REAL VALIDATION WOULD REQUIRE\n")
        f.write("-" * 78 + "\n")
        f.write("At least one full school year of real student grades with KNOWN\n")
        f.write("end-of-year outcomes (e.g. did the student actually end up at risk /\n")
        f.write("fail / need intervention), held out from training and evaluated on\n")
        f.write("after the fact — see train_from_real_data() in classify.py, which\n")
        f.write("this report's numbers do NOT come from and were not used to produce.\n\n")

        f.write("7. FEATURE IMPORTANCE (this trained model)\n")
        f.write("-" * 78 + "\n")
        feature_names = ['average_grade']
        for name, importance in zip(feature_names, model.feature_importances_):
            f.write(f"  {name}: {importance:.4f} ({importance * 100:.1f}%)\n")
        f.write("\n")
        f.write("Reads as 100% on average_grade because average_grade is the ONLY\n")
        f.write("feature this model was trained on — direct numeric confirmation of\n")
        f.write("section 2, in the model's own numbers rather than only a comment.\n")
        f.write("A healthy multi-feature model trained on real outcomes would instead\n")
        f.write("show importance SPREAD across several of the seven features Laravel\n")
        f.write("already sends (ww_mean, pt_mean, exam_mean, weak_component_count,\n")
        f.write("prev_term_average, trend_delta) rather than concentrated in one\n")
        f.write("column — a single feature still dominating after a real retrain\n")
        f.write("would itself be a finding worth investigating, not an assumption to\n")
        f.write("start from.\n")


def classify_students(grades_data, model):
    """
    Classify each student's risk level based on their average grade.

    Args:
        grades_data: list of dicts with at least { student_id, average_grade }.
            Laravel now also sends ww_mean, pt_mean, exam_mean,
            failing_subject_count, weak_component_count, prev_term_average,
            trend_delta — intentionally unused here for now, see the
            TRAINING DATA LIMITATION note at the top of this file.
        model: trained RandomForestClassifier

    Returns:
        list of { student_id, average_grade, risk_level, confidence }

    Confidence score = percentage of trees that agreed on the classification.
    Example: 198 out of 200 trees said 'high' → confidence = 99%
    """
    label_map = {0: 'low', 1: 'moderate', 2: 'high'}
    results   = []

    for student in grades_data:
        student_id    = student['student_id']
        average_grade = float(student['average_grade'])

        # Correctness and interface pass" TASK 3 — no more hardcoded `< 60`
        # override: the High band's training data now spans the full
        # 0-74.9 failing range (see train_model()), so the model itself
        # already covers this instead of a bypass around it.

        # Run classification — model returns predicted class index
        prediction = model.predict([[average_grade]])[0]

        # predict_proba returns probability for each class [low, moderate, high]
        # max() of these probabilities is the confidence score
        probabilities = model.predict_proba([[average_grade]])[0]

        results.append({
            'student_id':    student_id,
            'average_grade': average_grade,
            'risk_level':    label_map[prediction],
            'confidence':    round(float(max(probabilities)) * 100, 2),
        })

    return results


def train_from_real_data(csv_path, test_size=0.25, random_state=42):
    """
    NOT called anywhere in this file, NOT wired into get_model(), and does
    NOT touch model_cache.pkl or model_accuracy.txt — see the "honest
    model evaluation" prompt's Task 4. This exists so that retraining on
    the full feature set, once real outcome data exists, is a
    configuration change (point this at a CSV) rather than a rewrite.

    Expected CSV columns (header row required), one row per student per
    term:
        average_grade, ww_mean, pt_mean, exam_mean, weak_component_count,
        prev_term_average, trend_delta, outcome
    `outcome` is the KNOWN GROUND-TRUTH label — what actually happened to
    that student (e.g. their real end-of-year risk status) — one of
    'low', 'moderate', 'high'. Not a prediction, not a guess. Every row
    must have all seven numeric features populated; this function does
    not handle missing values.

    Unlike the synthetic model above (which reads only average_grade),
    this trains on the FULL seven-feature set Laravel already sends —
    the retrain the TRAINING DATA LIMITATION comment describes as
    premature until real data like this exists. Splits the CSV into a
    training portion and a held-out test portion (never seen during
    training) via train_test_split, trains only on the training portion,
    and reports Task 1's metrics computed against the held-out portion —
    genuine out-of-sample performance, not cross-validation folds of the
    same synthetic data.

    Returns (model, metrics_dict). Prints the held-out metrics to stdout;
    does not write any file. Whether/how to promote the returned model to
    production (e.g. saving it over model_cache.pkl) is a decision for
    whoever calls this with real data — deliberately not automated here.
    """
    import csv as csv_module

    feature_columns = [
        'average_grade', 'ww_mean', 'pt_mean', 'exam_mean',
        'weak_component_count', 'prev_term_average', 'trend_delta',
    ]
    label_to_index = {'low': 0, 'moderate': 1, 'high': 2}
    class_names = ['low', 'moderate', 'high']

    X, y = [], []
    with open(csv_path, newline='') as f:
        for row in csv_module.DictReader(f):
            X.append([float(row[col]) for col in feature_columns])
            y.append(label_to_index[row['outcome'].strip().lower()])

    X = np.array(X)
    y = np.array(y)

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=test_size, random_state=random_state, stratify=y
    )

    model = RandomForestClassifier(
        n_estimators=200,
        random_state=random_state,
        max_depth=5,
        min_samples_split=2,
        min_samples_leaf=1,
    )
    model.fit(X_train, y_train)

    y_pred = model.predict(X_test)
    metrics = {
        'train_samples':         len(y_train),
        'test_samples':          len(y_test),
        'held_out_test_accuracy': round(float((y_pred == y_test).mean()) * 100, 2),
        'confusion_matrix':      confusion_matrix(y_test, y_pred).tolist(),
        'classification_report': classification_report(y_test, y_pred, target_names=class_names, digits=4, zero_division=0),
    }

    print('=== train_from_real_data: held-out test metrics (real data) ===')
    print(f"Train samples: {metrics['train_samples']}, held-out test samples: {metrics['test_samples']}")
    print(f"Held-out test accuracy: {metrics['held_out_test_accuracy']}%")
    print('Confusion matrix (rows = actual, columns = predicted; order low/moderate/high):')
    print(np.array(metrics['confusion_matrix']))
    print(metrics['classification_report'])

    return model, metrics


def main():
    """
    Entry point — called by Laravel via exec().

    Arguments:
        sys.argv[1] = path to input JSON file (grades data from Laravel)
        sys.argv[2] = path to output JSON file (results for Laravel to read)

    Flow:
        Laravel writes grades → Python reads → classifies → Python writes results → Laravel reads

    --train-from-real-data <csv_path> is a SEPARATE, opt-in path (Task 4
    of the "honest model evaluation" prompt) — when absent, everything
    below this check behaves exactly as it always has.
    """
    parser = argparse.ArgumentParser(add_help=True, description='Naggasican NHS DSS risk classifier')
    parser.add_argument('input_file', nargs='?', help='Path to input JSON file (grades data from Laravel)')
    parser.add_argument('output_file', nargs='?', help='Path to output JSON file (results for Laravel to read)')
    parser.add_argument(
        '--train-from-real-data', metavar='CSV_PATH', dest='train_from_real_data',
        help='Train and evaluate against real student outcome data (see train_from_real_data() docstring for the expected CSV columns). '
             'Does not affect the default classification path and does not touch model_cache.pkl.'
    )
    args = parser.parse_args()

    if args.train_from_real_data:
        train_from_real_data(args.train_from_real_data)
        return

    if not args.input_file or not args.output_file:
        print('Usage: classify.py <input_file> <output_file>')
        return

    input_file  = args.input_file
    output_file = args.output_file

    # Read grades data from Laravel
    try:
        with open(input_file, 'r') as f:
            grades_data = json.load(f)
    except Exception as e:
        print(f'Error reading input file: {e}')
        return

    # Empty data — write empty results and exit
    if not grades_data:
        with open(output_file, 'w') as f:
            json.dump([], f)
        return

    # Load or train the model, then classify
    model   = get_model()
    results = classify_students(grades_data, model)

    # Write results for Laravel to read
    try:
        with open(output_file, 'w') as f:
            json.dump(results, f)
    except Exception as e:
        print(f'Error writing output file: {e}')


if __name__ == '__main__':
    main()