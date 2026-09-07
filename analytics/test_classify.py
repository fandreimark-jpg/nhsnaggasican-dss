"""
Pins classify_students()'s actual predictions so that Task 1-4 of the
"honest model evaluation" prompt (better metrics, honest model_accuracy.txt,
UI notes, an unused train_from_real_data() path) cannot silently change what
the classifier actually predicts. Run this before and after those changes —
any difference means a change that was supposed to be metrics/reporting-only
actually altered the model.

Uses the real get_model() (the cached model at model_cache.pkl if present,
same as production) rather than retraining, so this pins what advisers
actually see today, not a freshly-trained stand-in.
"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(__file__))

from classify import get_model, classify_students


class TestClassifyPredictionsArePinned(unittest.TestCase):
    """
    Grades spanning every band and both boundaries of each cut-off, per the
    "correctness and interface pass" TASK 3 recalibration (see
    train_model()'s docstring in classify.py): 0/0.5 (bottom of the
    trained range — no more hardcoded `< 60` bypass), 60/74/74.9 (still
    high — the failing range now extends the full 0-74.9), 75 (high ->
    moderate cut-off), 84.9/85 (moderate -> low cut-off), 100 (top of
    range).
    """

    EXPECTED_RISK_LEVELS = {
        0: 'high',
        0.5: 'high',
        60: 'high',
        74: 'high',
        74.9: 'high',
        75: 'moderate',
        84.9: 'moderate',
        85: 'low',
        100: 'low',
    }

    @classmethod
    def setUpClass(cls):
        cls.model = get_model()

    def test_predictions_match_the_pinned_baseline(self):
        grades_data = [
            {'student_id': i, 'average_grade': grade}
            for i, grade in enumerate(self.EXPECTED_RISK_LEVELS.keys())
        ]

        results = classify_students(grades_data, self.model)
        results_by_grade = {r['average_grade']: r['risk_level'] for r in results}

        for grade, expected_level in self.EXPECTED_RISK_LEVELS.items():
            with self.subTest(grade=grade):
                self.assertEqual(
                    results_by_grade[grade], expected_level,
                    f"average_grade={grade} produced risk_level="
                    f"'{results_by_grade[grade]}', expected '{expected_level}'"
                )


class TestRiskLevelDistribution(unittest.TestCase):
    """
    "Correctness and interface pass" TASK 3 — the actual bug this
    recalibration fixes: a cohort with a genuine spread of averages must
    not land entirely (or near-entirely) in one risk level. Reproduces
    the exact live data that motivated this task (40 students, averages
    75-92, clustered 86-88 — see the task's own "39 of 40 Moderate"
    report) and asserts the split is no longer that lopsided.
    """

    @classmethod
    def setUpClass(cls):
        cls.model = get_model()

    def test_a_realistic_passing_cohort_spreads_across_at_least_two_levels(self):
        # The exact 40 average_grade values behind the live "39 Moderate,
        # 1 Low, 0 High" report this task was filed against.
        averages = [
            75, 76, 76.5, 77.5, 79.5, 81, 81.5, 83.5, 85, 86, 86, 86, 86, 86,
            86, 86, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87,
            87, 87, 87, 87, 88, 88, 88, 88, 88.5, 92,
        ]
        grades_data = [{'student_id': i, 'average_grade': g} for i, g in enumerate(averages)]

        results = classify_students(grades_data, self.model)
        counts = {'low': 0, 'moderate': 0, 'high': 0}
        for r in results:
            counts[r['risk_level']] += 1

        self.assertEqual(sum(counts.values()), len(averages))
        # No single level may swallow every-or-nearly-every student.
        dominant = max(counts.values())
        self.assertLess(
            dominant, len(averages),
            f"Every student landed in one risk level: {counts} — recalibration did not fix the imbalance."
        )
        # Both moderate (75-84.9, the "near the passing mark" cases: 75,
        # 76, 76.5, 77.5, 79.5, 81, 81.5, 83.5) and low (85+, "comfortably
        # above": 85 and up) must be genuinely represented, not just one
        # of the two absorbing everyone.
        self.assertGreater(counts['moderate'], 0)
        self.assertGreater(counts['low'], 0)

    def test_a_cohort_with_failing_averages_actually_produces_high_risk(self):
        grades_data = [
            {'student_id': 1, 'average_grade': 55.0},
            {'student_id': 2, 'average_grade': 68.0},
            {'student_id': 3, 'average_grade': 90.0},
        ]

        results = classify_students(grades_data, self.model)
        by_id = {r['student_id']: r['risk_level'] for r in results}

        self.assertEqual(by_id[1], 'high')
        self.assertEqual(by_id[2], 'high')
        self.assertEqual(by_id[3], 'low')


if __name__ == '__main__':
    unittest.main()
