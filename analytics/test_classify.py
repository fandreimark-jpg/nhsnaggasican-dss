"""
Pins what the CURRENTLY DEPLOYED classifier actually predicts, so an
architectural change cannot silently move a learner's risk level.

This file is a regression guard, not evidence the model is good. The model
it pins is the LEGACY SYNTHETIC PROTOTYPE (see analytics/README.md) — these
assertions say "the deployed behaviour has not changed", never "the
deployed behaviour is correct about learners".

Uses the real model resolution path (`classify.load_model()`), not a
freshly-trained stand-in, so it pins what advisers actually see today.

Run:
    python analytics/test_classify.py
"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from classify import classify_students, load_model


class LegacyModelTestCase(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.model, cls.metadata = load_model()

    def _classify(self, grades):
        """The payload Laravel sends, reduced to the one column this model reads."""
        return classify_students(
            [{'student_id': i, 'average_grade': g} for i, g in enumerate(grades)],
            self.model,
            self.metadata,
        )


class TestClassifyPredictionsArePinned(LegacyModelTestCase):
    """
    Grades spanning every band and both boundaries of each cut-off, per the
    "correctness and interface pass" TASK 3 recalibration (see
    analytics/legacy/prototype_model.py's BANDS): 0/0.5 (bottom of the
    trained range — there is no hardcoded `< 60` bypass), 60/74/74.9 (still
    high — the failing range spans the full 0-74.9), 75 (high -> moderate
    cut-off), 84.9/85 (moderate -> low cut-off), 100 (top of range).
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

    def test_predictions_match_the_pinned_baseline(self):
        results = self._classify(self.EXPECTED_RISK_LEVELS.keys())
        by_grade = {r['average_grade']: r['risk_level'] for r in results}

        for grade, expected_level in self.EXPECTED_RISK_LEVELS.items():
            with self.subTest(grade=grade):
                self.assertEqual(
                    by_grade[float(grade)], expected_level,
                    f"average_grade={grade} produced risk_level="
                    f"'{by_grade[float(grade)]}', expected '{expected_level}'"
                )


class TestRiskLevelDistribution(LegacyModelTestCase):
    """
    "Correctness and interface pass" TASK 3 — the actual bug that
    recalibration fixed: a cohort with a genuine spread of averages must
    not land entirely (or near-entirely) in one risk level. Reproduces the
    exact live data that motivated it (40 students, averages 75-92,
    clustered 86-88 — the "39 of 40 Moderate" report) and asserts the split
    is no longer that lopsided.
    """

    def test_a_realistic_passing_cohort_spreads_across_at_least_two_levels(self):
        averages = [
            75, 76, 76.5, 77.5, 79.5, 81, 81.5, 83.5, 85, 86, 86, 86, 86, 86,
            86, 86, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87, 87,
            87, 87, 87, 87, 88, 88, 88, 88, 88.5, 92,
        ]

        results = self._classify(averages)
        counts = {'low': 0, 'moderate': 0, 'high': 0}
        for r in results:
            counts[r['risk_level']] += 1

        self.assertEqual(sum(counts.values()), len(averages))
        self.assertLess(
            max(counts.values()), len(averages),
            f"Every student landed in one risk level: {counts} — recalibration did not fix the imbalance."
        )
        self.assertGreater(counts['moderate'], 0)
        self.assertGreater(counts['low'], 0)

    def test_a_cohort_with_failing_averages_actually_produces_high_risk(self):
        results = self._classify([55.0, 68.0, 90.0])
        by_grade = {r['average_grade']: r['risk_level'] for r in results}

        self.assertEqual(by_grade[55.0], 'high')
        self.assertEqual(by_grade[68.0], 'high')
        self.assertEqual(by_grade[90.0], 'low')


class TestTheDeployedModelIsHonestlyLabelled(LegacyModelTestCase):
    """
    The pinned behaviour above is only safe to keep BECAUSE the model is
    labelled for what it is. If a future change promotes a real model, this
    fails loudly and the pins above must be re-derived from the new model
    rather than carried over.
    """

    def test_the_deployed_model_still_declares_itself_synthetic(self):
        self.assertEqual(self.metadata['dataset_type'], 'synthetic')
        self.assertEqual(self.metadata['version'], 'legacy_synthetic_prototype')

    def test_it_reads_exactly_one_feature(self):
        self.assertEqual(self.metadata['feature_names'], ['average_grade'])
        self.assertEqual(getattr(self.model, 'n_features_in_', None), 1)

    def test_its_limitations_are_recorded_with_the_artifact(self):
        joined = ' '.join(self.metadata['limitations']).lower()

        self.assertIn('synthetic', joined)
        self.assertIn('not real-world accuracy', joined)


if __name__ == '__main__':
    unittest.main(verbosity=2)
