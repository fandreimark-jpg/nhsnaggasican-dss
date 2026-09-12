"""
"ML architecture preparation" pass — tests for schema.py, dataset_validator.py,
model_registry.py, and train_model.py. All data here is synthetic and
clearly fabricated for the test only; nothing here is a real historical
dataset and nothing here touches analytics/model_cache.pkl (classify.py's
production model) or the real analytics/models/ directory (model_registry
tests point MODELS_ROOT at a temp directory for the duration of each test).
"""

import csv
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(__file__))

import model_registry
from dataset_validator import validate_dataset
from schema import DEFAULT_CONTRACT, REPORTING_SYSTEM_QUARTERLY, REPORTING_SYSTEM_THREE_TERM


def make_row(**overrides):
    row = {
        'anonymous_student_id': 'anon-0001',
        'school_year': '2023-2024',
        'grade_level': '11',
        'curriculum': 'sshs',
        'grading_policy': 'do015_2026',
        'reporting_system': REPORTING_SYSTEM_THREE_TERM,
        'period_index': '1',
        'ww_mean': '80.0',
        'pt_mean': '75.0',
        'exam_mean': '70.0',
        'current_average': '76.0',
        'prev_period_average': '74.0',
        'trend_delta': '2.0',
        'failing_subject_count': '0',
        'weak_component_count': '1',
        'missing_assessment_count': '0',
        'outcome': 'no_intervention',
    }
    row.update(overrides)
    return row


class TestDatasetValidator(unittest.TestCase):
    def test_a_fully_valid_dataset_passes_with_zero_rejections(self):
        rows = [
            make_row(anonymous_student_id=f'anon-{i:04d}', outcome='intervention' if i % 5 == 0 else 'no_intervention')
            for i in range(20)
        ]
        summary = validate_dataset(rows, dataset_label='synthetic test set')

        self.assertEqual(summary.total_records, 20)
        self.assertEqual(summary.valid_records, 20)
        self.assertEqual(summary.rejected_records, 0)
        self.assertTrue(summary.is_trainable())

    def test_missing_required_column_rejects_the_whole_dataset(self):
        rows = [make_row()]
        for row in rows:
            del row['reporting_system']

        summary = validate_dataset(rows, 'broken header')

        self.assertEqual(summary.valid_records, 0)
        self.assertFalse(summary.is_trainable())
        self.assertIn('reporting_system', summary.errors[0].reason)

    def test_a_forbidden_identifying_column_rejects_the_whole_dataset(self):
        rows = [dict(make_row(), lrn='123456789012')]

        summary = validate_dataset(rows, 'has real lrn')

        self.assertEqual(summary.valid_records, 0)
        self.assertIn('forbidden', summary.errors[0].reason)

    def test_an_unsupported_reporting_system_is_rejected_per_row(self):
        rows = [make_row(reporting_system='semester')]

        summary = validate_dataset(rows, 'bad reporting system')

        self.assertEqual(summary.valid_records, 0)
        self.assertEqual(summary.rejected_records, 1)

    def test_period_index_4_is_valid_for_quarterly_but_not_three_term(self):
        quarterly_row = make_row(reporting_system=REPORTING_SYSTEM_QUARTERLY, period_index='4')
        three_term_row = make_row(reporting_system=REPORTING_SYSTEM_THREE_TERM, period_index='4')

        quarterly_summary = validate_dataset([quarterly_row], 'quarterly Q4')
        three_term_summary = validate_dataset([three_term_row], 'three-term period 4 (invalid)')

        self.assertEqual(quarterly_summary.valid_records, 1)
        self.assertEqual(three_term_summary.valid_records, 0)

    def test_a_non_numeric_feature_value_is_rejected_not_coerced(self):
        rows = [make_row(ww_mean='not-a-number')]

        summary = validate_dataset(rows, 'bad feature')

        self.assertEqual(summary.valid_records, 0)

    def test_an_invalid_target_label_is_rejected(self):
        rows = [make_row(outcome='maybe')]

        summary = validate_dataset(rows, 'bad target')

        self.assertEqual(summary.valid_records, 0)

    def test_duplicate_learner_period_records_are_rejected(self):
        rows = [make_row(), make_row()]  # identical key on purpose

        summary = validate_dataset(rows, 'duplicate rows')

        self.assertEqual(summary.valid_records, 1)
        self.assertEqual(summary.rejected_records, 1)

    def test_severe_class_imbalance_is_a_warning_not_a_rejection(self):
        rows = [make_row(anonymous_student_id=f'anon-{i}', outcome='no_intervention') for i in range(100)]
        rows.append(make_row(anonymous_student_id='anon-minority', outcome='intervention'))

        summary = validate_dataset(rows, 'imbalanced')

        self.assertEqual(summary.valid_records, 101)
        self.assertTrue(any('imbalance' in w.lower() for w in summary.warnings))
        self.assertTrue(summary.is_trainable())

    def test_a_perfectly_separating_feature_is_flagged_as_possible_leakage(self):
        rows = []
        for i in range(10):
            rows.append(make_row(anonymous_student_id=f'anon-a-{i}', outcome='no_intervention', failing_subject_count='0'))
        for i in range(10):
            rows.append(make_row(anonymous_student_id=f'anon-b-{i}', outcome='intervention', failing_subject_count='5'))

        summary = validate_dataset(rows, 'leaky feature')

        self.assertTrue(any('leakage' in w.lower() and 'failing_subject_count' in w for w in summary.warnings))

    def test_report_contains_the_documented_shape(self):
        rows = [make_row(anonymous_student_id=f'anon-{i}') for i in range(5)]
        summary = validate_dataset(rows, 'Historical SHS 2023-2026')

        report = summary.report()
        self.assertIn('Dataset: Historical SHS 2023-2026', report)
        self.assertIn('Records: 5', report)
        self.assertIn('Valid: 5', report)


class TestModelRegistry(unittest.TestCase):
    def setUp(self):
        self._real_root = model_registry.MODELS_ROOT
        self._tmp_root = tempfile.mkdtemp(prefix='dss_model_registry_test_')
        model_registry.MODELS_ROOT = self._tmp_root

    def tearDown(self):
        model_registry.MODELS_ROOT = self._real_root
        shutil.rmtree(self._tmp_root, ignore_errors=True)

    def _dummy_metadata(self, version='v_test_1'):
        return model_registry.ModelMetadata(
            version=version, algorithm='RandomForestClassifier', trained_at='2026-01-01T00:00:00',
            training_record_count=10, school_years_represented=['2023-2024'],
            reporting_systems_represented=['three_term'], feature_list=['ww_mean'],
            target_definition='synthetic test target', accuracy=90.0,
            precision={'intervention': 80.0}, recall={'intervention': 70.0}, f1={'intervention': 75.0},
            confusion_matrix=[[5, 1], [1, 3]],
        )

    def test_save_candidate_never_creates_an_active_model(self):
        model_registry.save_candidate(object(), self._dummy_metadata())

        self.assertEqual(model_registry.list_models('active'), [])
        candidates = model_registry.list_models('candidate')
        self.assertEqual(len(candidates), 1)
        self.assertEqual(candidates[0]['status'], 'candidate')

    def test_promote_to_active_requires_an_existing_candidate(self):
        with self.assertRaises(FileNotFoundError):
            model_registry.promote_to_active('does_not_exist')

    def test_promote_to_active_moves_it_and_archives_the_previous_active(self):
        model_registry.save_candidate(object(), self._dummy_metadata('v1'))
        model_registry.promote_to_active('v1')

        self.assertEqual(model_registry.get_active_model_metadata()['version'], 'v1')
        self.assertEqual(model_registry.get_active_model_metadata()['status'], 'active')

        model_registry.save_candidate(object(), self._dummy_metadata('v2'))
        model_registry.promote_to_active('v2')

        self.assertEqual(model_registry.get_active_model_metadata()['version'], 'v2')
        archived_versions = [m['version'] for m in model_registry.list_models('archived')]
        self.assertIn('v1', archived_versions)


class TestTrainModelRefusesUntrainableData(unittest.TestCase):
    """
    train_model.py's own docstring says DO NOT run this against real data
    yet -- this test class proves the refusal-to-train GATE works, using
    only synthetic, clearly-fabricated rows, and never calls the actual
    RandomForestClassifier training path with anything but this synthetic
    data.
    """

    def setUp(self):
        self._real_root = model_registry.MODELS_ROOT
        self._tmp_root = tempfile.mkdtemp(prefix='dss_train_model_test_')
        model_registry.MODELS_ROOT = self._tmp_root
        fd, self._tmp_csv_path = tempfile.mkstemp(suffix='.csv')
        os.close(fd)

    def tearDown(self):
        model_registry.MODELS_ROOT = self._real_root
        shutil.rmtree(self._tmp_root, ignore_errors=True)
        os.unlink(self._tmp_csv_path)

    def _write_csv(self, rows):
        with open(self._tmp_csv_path, 'w', newline='', encoding='utf-8') as f:
            writer = csv.DictWriter(f, fieldnames=list(rows[0].keys()))
            writer.writeheader()
            writer.writerows(rows)

    def test_refuses_to_train_on_a_single_class_dataset(self):
        import train_model

        rows = [make_row(anonymous_student_id=f'anon-{i}', outcome='no_intervention') for i in range(10)]
        self._write_csv(rows)

        with self.assertRaises(SystemExit):
            train_model.train_candidate(self._tmp_csv_path, dataset_label='single class synthetic')

        self.assertEqual(model_registry.list_models('candidate'), [])

    def test_trains_and_saves_a_candidate_never_active_on_a_valid_synthetic_dataset(self):
        import train_model

        rows = []
        for year in ('2022-2023', '2023-2024'):
            for i in range(20):
                outcome = 'intervention' if i % 4 == 0 else 'no_intervention'
                rows.append(make_row(
                    anonymous_student_id=f'anon-{year}-{i}', school_year=year,
                    outcome=outcome,
                    current_average=str(60.0 if outcome == 'intervention' else 85.0),
                ))
        self._write_csv(rows)

        version = train_model.train_candidate(self._tmp_csv_path, dataset_label='two-cohort synthetic')

        self.assertEqual(model_registry.list_models('active'), [])
        candidates = model_registry.list_models('candidate')
        self.assertEqual(len(candidates), 1)
        self.assertEqual(candidates[0]['version'], version)
        self.assertEqual(candidates[0]['status'], 'candidate')
        self.assertIn('2022-2023', candidates[0]['school_years_represented'])
        self.assertIn('2023-2024', candidates[0]['school_years_represented'])


if __name__ == '__main__':
    unittest.main()
