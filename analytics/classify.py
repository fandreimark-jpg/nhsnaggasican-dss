import sys
import json
import os
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import cross_val_score

# ============================================================================
# TRAINING DATA LIMITATION (documented per project policy — do not remove)
#
# This model is trained on ONE feature (average_grade) against 90 PURELY
# SYNTHETIC samples that are just clean numeric boundaries matching the
# DepEd thresholds below (90-100 / 75-89 / 60-74). The 100% cross-validation
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
    """
    Load the cached model if it exists, otherwise train a new one and cache it.

    Why caching:
    - Training takes 1-2 seconds on first run
    - Loading a cached model takes ~0.1 seconds
    - Result: faster report submission after the first time
    """
    try:
        import joblib

        if os.path.exists(MODEL_PATH):
            # Load existing trained model from disk
            return joblib.load(MODEL_PATH)

        # First run — train and save the model
        model = train_model()
        joblib.dump(model, MODEL_PATH)
        return model

    except ImportError:
        # joblib not installed — train without caching
        return train_model()


def train_model():
    """
    Train the Random Forest classifier using DepEd SHS grading thresholds.

    Risk levels based on DepEd grading standards:
    - LOW RISK      : 90 - 100 (Outstanding / Very Satisfactory)
    - MODERATE RISK : 75 - 89  (Satisfactory / Fairly Satisfactory)
    - HIGH RISK     : 60 - 74  (Did Not Meet Expectations)

    Training data: 30 samples per class = 90 total
    Balanced dataset — equal samples per class prevents model bias.
    """

    # LOW RISK samples — grades from 90 to 100 (30 samples)
    low_risk = [
        [90.0], [90.5], [91.0], [91.5], [92.0],
        [92.5], [93.0], [93.5], [94.0], [94.5],
        [95.0], [95.5], [96.0], [96.5], [97.0],
        [97.5], [98.0], [98.5], [99.0], [99.5],
        [100.0],[90.2], [91.8], [93.3], [94.7],
        [95.8], [96.3], [97.8], [98.3], [99.2],
    ]

    # MODERATE RISK samples — grades from 75 to 89 (30 samples)
    moderate_risk = [
        [75.0], [75.5], [76.0], [76.5], [77.0],
        [77.5], [78.0], [78.5], [79.0], [79.5],
        [80.0], [80.5], [81.0], [81.5], [82.0],
        [83.0], [84.0], [85.0], [86.0], [87.0],
        [88.0], [89.0], [75.3], [76.8], [78.3],
        [80.3], [82.7], [84.5], [86.5], [88.5],
    ]

    # HIGH RISK samples — grades from 60 to 74 (30 samples)
    high_risk = [
        [74.9], [74.0], [73.5], [73.0], [72.5],
        [72.0], [71.5], [71.0], [70.5], [70.0],
        [69.5], [69.0], [68.5], [68.0], [67.0],
        [66.0], [65.0], [64.0], [63.0], [62.0],
        [61.0], [60.0], [74.5], [73.3], [71.8],
        [70.3], [68.3], [66.7], [63.5], [61.5],
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

    # Cross-validation — evaluates model accuracy on unseen data
    # cv=5: splits data into 5 folds, tests on each fold once
    # Result is saved to model_accuracy.txt for documentation
    try:
        scores   = cross_val_score(model, X_train, y_train, cv=5, scoring='accuracy')
        avg      = round(scores.mean() * 100, 2)
        log_path = os.path.join(os.path.dirname(__file__), 'model_accuracy.txt')
        with open(log_path, 'w') as f:
            f.write(f"Cross-validation Accuracy: {avg}%\n")
            f.write(f"Per-fold scores: {[round(s * 100, 2) for s in scores]}\n")
            f.write(f"Training samples: {len(X_train)}\n")
            f.write(f"  Low risk:      {len(low_risk)} samples\n")
            f.write(f"  Moderate risk: {len(moderate_risk)} samples\n")
            f.write(f"  High risk:     {len(high_risk)} samples\n")
    except Exception:
        pass  # Cross-validation failure is non-critical

    return model


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

        # Edge case — grades below 60 are automatic High Risk
        # (below DepEd minimum, not covered by training data)
        if average_grade < 60:
            results.append({
                'student_id':    student_id,
                'average_grade': average_grade,
                'risk_level':    'high',
                'confidence':    100.0,
            })
            continue

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


def main():
    """
    Entry point — called by Laravel via exec().

    Arguments:
        sys.argv[1] = path to input JSON file (grades data from Laravel)
        sys.argv[2] = path to output JSON file (results for Laravel to read)

    Flow:
        Laravel writes grades → Python reads → classifies → Python writes results → Laravel reads
    """
    if len(sys.argv) < 3:
        print('Usage: classify.py <input_file> <output_file>')
        return

    input_file  = sys.argv[1]
    output_file = sys.argv[2]

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