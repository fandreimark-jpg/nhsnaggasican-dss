"""
Tests for schema.py, dataset_validator.py, baseline.py, model_registry.py
and train_model.py.

ALL DATA HERE IS SYNTHETIC and clearly fabricated for the test only.
Nothing here is a real historical dataset, nothing here touches
analytics/model_cache.pkl (the deployed prototype), and every registry test
points MODELS_ROOT at a temp directory for its duration, so the real
analytics/models/ tree is never written to.

Run:
    python analytics/test_training_pipeline.py
"""

import csv
import json
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import baseline
import model_registry
import schema
from dataset_validator import MIN_RECORDS_PER_CLASS, validate_dataset
from schema import DEFAULT_CONTRACT, REPORTING_SYSTEM_QUARTERLY, REPORTING_SYSTEM_THREE_TERM

FIXTURE_CSV = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'tests', 'fixtures', 'synthetic_pipeline_fixture.csv')


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


def balanced_rows(count=40, **overrides):
    """Enough of both classes to clear MIN_RECORDS_PER_CLASS."""
    return [
        make_row(
            anonymous_student_id=f'anon-{i:04d}',
            outcome='intervention' if i % 2 == 0 else 'no_intervention',
            **overrides,
        )
        for i in range(count)
    ]


class TestSchemaIsTheSingleSourceOfTruth(unittest.TestCase):
    def test_every_feature_has_a_written_definition_and_a_range(self):
        for feature in schema.FEATURE_COLUMNS:
            self.assertIn(feature, schema.FEATURE_DEFINITIONS, f'{feature} has no documented definition')
            self.assertIn(feature, schema.FEATURE_RANGES, f'{feature} has no allowed range')
            self.assertGreater(len(schema.FEATURE_DEFINITIONS[feature]), 40)

    def test_weak_component_count_has_one_explicit_definition(self):
        definition = schema.FEATURE_DEFINITIONS['weak_component_count']

        self.assertIn('THE DEFINITION IS', definition)
        self.assertIn('75', definition)
        self.assertIn('blank component is NOT counted as weak', definition)

    def test_no_identifier_is_a_feature(self):
        for identifier in schema.FORBIDDEN_COLUMNS + (schema.GROUPING_COLUMN, 'student_id'):
            self.assertNotIn(identifier, schema.FEATURE_COLUMNS)

    def test_birthdate_is_explicitly_forbidden(self):
        for column in ('birthdate', 'birth_date', 'date_of_birth'):
            self.assertIn(column, schema.FORBIDDEN_COLUMNS)

    def test_the_target_definition_forbids_deriving_it_from_a_feature(self):
        self.assertIn('never derived from current_average', schema.TARGET_DEFINITION)
        self.assertEqual(schema.VALID_TARGET_VALUES, ('no_intervention', 'intervention'))

    def test_build_feature_vector_rejects_a_boolean(self):
        row = {name: 1.0 for name in schema.FEATURE_COLUMNS}
        row['ww_mean'] = True

        with self.assertRaises(schema.SchemaError):
            schema.build_feature_vector(row, schema.FEATURE_COLUMNS)


class TestDatasetValidator(unittest.TestCase):
    def test_a_fully_valid_dataset_passes_with_zero_rejections(self):
        summary = validate_dataset(balanced_rows(40), dataset_label='synthetic test set')

        self.assertEqual(summary.total_records, 40)
        self.assertEqual(summary.valid_records, 40)
        self.assertEqual(summary.rejected_records, 0)
        self.assertTrue(summary.is_trainable(), summary.blocking_reasons)

    def test_missing_required_column_rejects_the_whole_dataset(self):
        rows = balanced_rows(20)
        for row in rows:
            del row['reporting_system']

        summary = validate_dataset(rows, 'broken header')

        self.assertEqual(summary.valid_records, 0)
        self.assertFalse(summary.is_trainable())
        self.assertIn('reporting_system', summary.errors[0].reason)

    def test_a_forbidden_identifying_column_rejects_the_whole_dataset(self):
        rows = [dict(row, lrn='123456789012') for row in balanced_rows(20)]

        summary = validate_dataset(rows, 'has real lrn')

        self.assertEqual(summary.valid_records, 0)
        self.assertFalse(summary.is_trainable())
        self.assertIn('prohibited identifying column', summary.errors[0].reason)
        self.assertIn('lrn', summary.report())

    def test_every_prohibited_identifier_is_caught_individually(self):
        for column in schema.FORBIDDEN_COLUMNS:
            with self.subTest(column=column):
                rows = [dict(row, **{column: 'x'}) for row in balanced_rows(20)]
                summary = validate_dataset(rows, f'has {column}')

                self.assertFalse(summary.is_trainable(), f"'{column}' was not rejected")

    def test_an_unsupported_reporting_system_is_rejected_per_row(self):
        summary = validate_dataset([make_row(reporting_system='semester')], 'bad reporting system')

        self.assertEqual(summary.valid_records, 0)
        self.assertEqual(summary.rejected_records, 1)

    def test_period_index_4_is_valid_for_quarterly_but_not_three_term(self):
        quarterly = validate_dataset(
            [make_row(reporting_system=REPORTING_SYSTEM_QUARTERLY, period_index='4')], 'quarterly Q4')
        three_term = validate_dataset(
            [make_row(reporting_system=REPORTING_SYSTEM_THREE_TERM, period_index='4')], 'three-term period 4')

        self.assertEqual(quarterly.valid_records, 1)
        self.assertEqual(three_term.valid_records, 0)
        self.assertIn('period_index', three_term.errors[0].reason)

    def test_period_index_zero_and_negative_are_rejected_for_both_systems(self):
        for system in schema.REPORTING_SYSTEMS:
            for bad in ('0', '-1', 'two', ''):
                with self.subTest(system=system, period_index=bad):
                    summary = validate_dataset([make_row(reporting_system=system, period_index=bad)], 'bad period')
                    self.assertEqual(summary.valid_records, 0)

    def test_quarterly_and_three_term_metadata_are_preserved_side_by_side(self):
        rows = []
        for i in range(20):
            rows.append(make_row(anonymous_student_id=f'q-{i}', school_year='2022-2023', grade_level='12',
                                 reporting_system=REPORTING_SYSTEM_QUARTERLY, period_index=str((i % 4) + 1),
                                 outcome='intervention' if i % 2 else 'no_intervention'))
        for i in range(20):
            rows.append(make_row(anonymous_student_id=f't-{i}', reporting_system=REPORTING_SYSTEM_THREE_TERM,
                                 period_index=str((i % 3) + 1),
                                 outcome='intervention' if i % 2 else 'no_intervention'))

        summary = validate_dataset(rows, 'mixed systems')

        self.assertEqual(summary.valid_records, 40)
        self.assertEqual(summary.reporting_system_counts[REPORTING_SYSTEM_QUARTERLY], 20)
        self.assertEqual(summary.reporting_system_counts[REPORTING_SYSTEM_THREE_TERM], 20)
        # Each row keeps its own native period count — no Q->Term remapping.
        for row in summary.valid_rows:
            limit = schema.MAX_PERIOD_INDEX[row['reporting_system']]
            self.assertLessEqual(int(row['period_index']), limit)

    def test_a_non_numeric_feature_value_is_rejected_not_coerced(self):
        summary = validate_dataset([make_row(ww_mean='not-a-number')], 'bad feature')

        self.assertEqual(summary.valid_records, 0)
        self.assertIn('expected a number', summary.errors[0].reason)

    def test_an_impossible_numeric_value_is_rejected(self):
        summary = validate_dataset([make_row(current_average='145')], 'impossible average')

        self.assertEqual(summary.valid_records, 0)
        self.assertIn('outside the allowed range', summary.errors[0].reason)

    def test_a_negative_count_is_rejected(self):
        summary = validate_dataset([make_row(failing_subject_count='-2')], 'negative count')

        self.assertEqual(summary.valid_records, 0)

    def test_a_malformed_school_year_is_rejected(self):
        for bad in ('2023', '2023-2025', '2023/2024', 'twenty-three'):
            with self.subTest(school_year=bad):
                summary = validate_dataset([make_row(school_year=bad)], 'bad year')
                self.assertEqual(summary.valid_records, 0)

    def test_a_blank_required_feature_is_rejected_and_never_read_as_zero(self):
        summary = validate_dataset([make_row(current_average='')], 'blank required feature')

        self.assertEqual(summary.valid_records, 0)
        self.assertIn('never read as 0', summary.errors[0].reason)

    def test_a_blank_optional_feature_is_accepted_and_stays_blank(self):
        summary = validate_dataset(
            balanced_rows(20, exam_mean='', prev_period_average='', trend_delta=''), 'no exam profile')

        self.assertEqual(summary.valid_records, 20)
        self.assertTrue(summary.is_trainable(), summary.blocking_reasons)
        for row in summary.valid_rows:
            self.assertEqual(row['exam_mean'], '')

    def test_an_optional_feature_blank_in_every_row_is_warned_about(self):
        summary = validate_dataset(balanced_rows(20, exam_mean=''), 'no exam anywhere')

        self.assertTrue(any('exam_mean' in w and 'blank in every valid row' in w for w in summary.warnings))

    def test_an_invalid_target_label_is_rejected(self):
        summary = validate_dataset([make_row(outcome='high')], 'bad target')

        self.assertEqual(summary.valid_records, 0)
        self.assertIn('expected one of', summary.errors[0].reason)

    def test_a_blank_outcome_is_rejected_as_ambiguous_never_guessed(self):
        summary = validate_dataset([make_row(outcome='')], 'missing target')

        self.assertEqual(summary.valid_records, 0)
        self.assertIn('never guessed at', summary.errors[0].reason)

    def test_a_blank_anonymous_student_id_is_rejected(self):
        summary = validate_dataset([make_row(anonymous_student_id='')], 'no grouping id')

        self.assertEqual(summary.valid_records, 0)

    def test_duplicate_learner_period_records_are_rejected(self):
        summary = validate_dataset([make_row(), make_row()], 'duplicate rows')

        self.assertEqual(summary.valid_records, 1)
        self.assertEqual(summary.rejected_records, 1)

    def test_only_one_class_present_blocks_training(self):
        summary = validate_dataset(
            [make_row(anonymous_student_id=f'anon-{i}') for i in range(20)], 'single class')

        self.assertFalse(summary.is_trainable())
        self.assertTrue(any('target class' in r for r in summary.blocking_reasons))

    def test_a_class_with_too_few_records_blocks_training(self):
        rows = [make_row(anonymous_student_id=f'anon-{i}') for i in range(30)]
        rows[0]['outcome'] = 'intervention'  # exactly one

        summary = validate_dataset(rows, 'thin minority class')

        self.assertFalse(summary.is_trainable())
        self.assertTrue(any('Insufficient class representation' in r for r in summary.blocking_reasons))
        self.assertIn(str(MIN_RECORDS_PER_CLASS), ' '.join(summary.blocking_reasons))

    def test_severe_class_imbalance_is_a_warning_not_a_rejection(self):
        rows = [make_row(anonymous_student_id=f'anon-{i}') for i in range(300)]
        for i in range(10):
            rows[i]['outcome'] = 'intervention'

        summary = validate_dataset(rows, 'imbalanced')

        self.assertTrue(summary.is_trainable(), summary.blocking_reasons)
        self.assertTrue(any('imbalance' in w.lower() for w in summary.warnings))

    def test_a_perfectly_separating_feature_is_flagged_as_possible_leakage(self):
        rows = []
        for i in range(20):
            rows.append(make_row(anonymous_student_id=f'anon-a-{i}', outcome='no_intervention', failing_subject_count='0'))
        for i in range(20):
            rows.append(make_row(anonymous_student_id=f'anon-b-{i}', outcome='intervention', failing_subject_count='5'))

        summary = validate_dataset(rows, 'leaky feature')

        self.assertTrue(any('leakage' in w.lower() and 'failing_subject_count' in w for w in summary.warnings))

    def test_an_empty_dataset_is_blocked_not_silently_passed(self):
        summary = validate_dataset([], 'empty')

        self.assertFalse(summary.is_trainable())
        self.assertIn('Dataset is empty.', summary.blocking_reasons)

    def test_the_report_names_the_offending_row_and_reason(self):
        rows = balanced_rows(20)
        rows[12]['current_average'] = '145'

        report = validate_dataset(rows, 'Historical SHS 2023-2026').report()

        self.assertIn('Dataset: Historical SHS 2023-2026', report)
        self.assertIn('Row 14', report)  # row 13 zero-indexed, +2 for header and 1-basing
        self.assertIn('current_average = 145.0', report)
        self.assertIn('outside the allowed range', report)


class TestBaselineIsSeparateFromTheModel(unittest.TestCase):
    def test_the_baseline_predicts_from_features_and_never_reads_labels(self):
        import inspect

        source = inspect.getsource(baseline.predict)

        self.assertNotIn('y_true', source)
        self.assertNotIn('outcome', source)

    def test_a_failing_learner_is_flagged_and_a_strong_one_is_not(self):
        names = list(schema.FEATURE_COLUMNS)

        def vector(**values):
            row = {name: 90.0 for name in names}
            row.update({'failing_subject_count': 0, 'weak_component_count': 0})
            row.update(values)
            return schema.build_feature_vector(row, names)

        predictions = baseline.predict(
            [vector(), vector(failing_subject_count=1), vector(current_average=60.0)], names)

        intervention = schema.VALID_TARGET_VALUES.index('intervention')
        no_intervention = schema.VALID_TARGET_VALUES.index('no_intervention')
        self.assertEqual(predictions, [no_intervention, intervention, intervention])

    def test_a_blank_feature_does_not_flag_a_learner(self):
        names = list(schema.FEATURE_COLUMNS)
        row = {name: 90.0 for name in names}
        row.update({'failing_subject_count': 0, 'weak_component_count': 0, 'exam_mean': None, 'current_average': None})

        predictions = baseline.predict([schema.build_feature_vector(row, names)], names)

        self.assertEqual(predictions, [schema.VALID_TARGET_VALUES.index('no_intervention')])


class RegistryIsolatedTestCase(unittest.TestCase):
    def setUp(self):
        self._real_root = model_registry.MODELS_ROOT
        self._tmp_root = tempfile.mkdtemp(prefix='dss_model_registry_test_')
        model_registry.MODELS_ROOT = self._tmp_root

    def tearDown(self):
        model_registry.MODELS_ROOT = self._real_root
        shutil.rmtree(self._tmp_root, ignore_errors=True)

    def _metadata(self, version='v_test_1', **overrides):
        defaults = dict(
            version=version,
            algorithm='RandomForestClassifier',
            trained_at='2026-01-01T00:00:00',
            feature_names=list(schema.FEATURE_COLUMNS),
            target_definition='synthetic test target',
            accuracy=90.0,
            precision={'intervention': 80.0},
            recall={'intervention': 70.0},
            f1={'intervention': 75.0},
            confusion_matrix=[[5, 1], [1, 3]],
        )
        defaults.update(overrides)
        return model_registry.ModelMetadata(**defaults)


class TestModelRegistry(RegistryIsolatedTestCase):
    def test_save_candidate_never_creates_an_active_model(self):
        model_registry.save_candidate(object(), self._metadata())

        self.assertEqual(model_registry.list_models('active'), [])
        candidates = model_registry.list_models('candidate')
        self.assertEqual(len(candidates), 1)
        self.assertEqual(candidates[0]['status'], 'candidate')

    def test_load_active_model_returns_nothing_rather_than_improvising(self):
        model, metadata = model_registry.load_active_model()

        self.assertIsNone(model)
        self.assertIsNone(metadata)

    def test_promote_to_active_requires_an_existing_candidate(self):
        with self.assertRaises(FileNotFoundError):
            model_registry.promote_to_active('does_not_exist')

    def test_promote_to_active_moves_it_and_archives_the_previous_active(self):
        model_registry.save_candidate(object(), self._metadata('v1'))
        model_registry.promote_to_active('v1')

        self.assertEqual(model_registry.get_active_model_metadata()['version'], 'v1')
        self.assertEqual(model_registry.get_active_model_metadata()['status'], 'active')

        model_registry.save_candidate(object(), self._metadata('v2'))
        model_registry.promote_to_active('v2')

        self.assertEqual(model_registry.get_active_model_metadata()['version'], 'v2')
        self.assertIn('v1', [m['version'] for m in model_registry.list_models('archived')])

    def test_rollback_restores_an_archived_model_and_archives_the_current_one(self):
        model_registry.save_candidate(object(), self._metadata('v1'))
        model_registry.promote_to_active('v1')
        model_registry.save_candidate(object(), self._metadata('v2'))
        model_registry.promote_to_active('v2')

        model_registry.rollback_to('v1')

        self.assertEqual(model_registry.get_active_model_metadata()['version'], 'v1')
        self.assertIn('v2', [m['version'] for m in model_registry.list_models('archived')])
        # Nothing was deleted along the way — both versions still exist.
        self.assertEqual({m['version'] for m in model_registry.list_models()}, {'v1', 'v2'})

    def test_rollback_to_an_unknown_version_fails_loudly(self):
        with self.assertRaises(FileNotFoundError):
            model_registry.rollback_to('never_existed')

    def test_metadata_rejects_an_identifier_used_as_a_feature(self):
        metadata = self._metadata('v_pii')
        metadata.feature_names = list(schema.FEATURE_COLUMNS) + ['lrn']

        with self.assertRaises(ValueError) as ctx:
            metadata.assert_no_identifiers()

        self.assertIn('lrn', str(ctx.exception))
        self.assertIn('never be a model feature', str(ctx.exception))

    def test_metadata_rejects_an_identifier_value_hiding_in_a_descriptive_field(self):
        for leak in ('export for 110000000001', 'queried by parent@example.com', 'contact 09171234567'):
            with self.subTest(leak=leak):
                metadata = self._metadata('v_pii3', dataset_label=leak)

                with self.assertRaises(ValueError):
                    metadata.assert_no_identifiers()

    def test_an_ordinary_dataset_label_is_not_mistaken_for_a_leak(self):
        metadata = self._metadata('v_clean', dataset_label='Historical SHS 2023-2026 (anonymised export, 1,250 rows)')

        metadata.assert_no_identifiers()  # must not raise

    def test_naming_the_grouping_column_as_methodology_is_allowed(self):
        """
        Recording HOW rows were grouped is documentation a reviewer needs.
        The column's NAME is not a learner's identifier, and the PII guard
        must not be so blunt that it forbids describing the split.
        """
        metadata = self._metadata('v_group')
        metadata.split_methodology = {'method': 'grouped_random', 'group_column': schema.GROUPING_COLUMN}

        metadata.assert_no_identifiers()  # must not raise

    def test_save_candidate_refuses_metadata_carrying_an_identifier(self):
        metadata = self._metadata('v_pii2')
        metadata.feature_names = ['anonymous_student_id']

        with self.assertRaises(ValueError):
            model_registry.save_candidate(object(), metadata)

        self.assertEqual(model_registry.list_models('candidate'), [])

    def test_runtime_versions_are_read_not_hardcoded(self):
        import sklearn

        versions = model_registry.runtime_versions()

        self.assertEqual(versions['scikit-learn'], sklearn.__version__)
        self.assertEqual(versions['python'], __import__('platform').python_version())


class TestTrainModel(RegistryIsolatedTestCase):
    """
    train_model.py's own docstring says there is no authorized real dataset
    yet. Everything here uses clearly-fabricated synthetic rows and asserts
    the GATES hold.
    """

    def setUp(self):
        super().setUp()
        fd, self._tmp_csv_path = tempfile.mkstemp(suffix='.csv')
        os.close(fd)

    def tearDown(self):
        super().tearDown()
        os.unlink(self._tmp_csv_path)

    def _write_csv(self, rows):
        with open(self._tmp_csv_path, 'w', newline='', encoding='utf-8') as f:
            writer = csv.DictWriter(f, fieldnames=list(rows[0].keys()))
            writer.writeheader()
            writer.writerows(rows)

    def _candidate_metadata(self, version):
        path = os.path.join(self._tmp_root, 'candidate', f'{version}.json')
        with open(path, encoding='utf-8') as f:
            return json.load(f)

    def test_refuses_to_train_on_a_single_class_dataset(self):
        import train_model

        self._write_csv([make_row(anonymous_student_id=f'anon-{i}') for i in range(20)])

        with self.assertRaises(SystemExit):
            train_model.train_candidate(self._tmp_csv_path, dataset_label='single class synthetic', dataset_type='synthetic')

        self.assertEqual(model_registry.list_models('candidate'), [])

    def test_refuses_to_train_on_a_dataset_carrying_an_identifier(self):
        import train_model

        self._write_csv([dict(row, lrn='123456789012') for row in balanced_rows(40)])

        with self.assertRaises(SystemExit):
            train_model.train_candidate(self._tmp_csv_path, dataset_label='pii synthetic', dataset_type='synthetic')

        self.assertEqual(model_registry.list_models('candidate'), [])

    def test_refuses_an_unknown_dataset_type(self):
        import train_model

        self._write_csv(balanced_rows(40))

        with self.assertRaises(SystemExit):
            train_model.train_candidate(self._tmp_csv_path, dataset_label='x', dataset_type='definitely_real')

    def test_trains_a_candidate_and_never_an_active_model(self):
        import train_model

        version = train_model.train_candidate(
            FIXTURE_CSV, dataset_label='synthetic pipeline fixture', dataset_type='synthetic')

        self.assertEqual(model_registry.list_models('active'), [],
                         'training must never activate anything')
        candidates = model_registry.list_models('candidate')
        self.assertEqual(len(candidates), 1)
        self.assertEqual(candidates[0]['version'], version)
        self.assertEqual(candidates[0]['status'], 'candidate')

    def test_candidate_metadata_records_everything_needed_to_audit_it(self):
        import train_model

        version = train_model.train_candidate(FIXTURE_CSV, dataset_label='fixture', dataset_type='synthetic')
        meta = self._candidate_metadata(version)

        # Feature names, in the exact fitted order.
        self.assertEqual(meta['feature_names'], list(schema.FEATURE_COLUMNS))
        self.assertEqual(meta['feature_schema_version'], schema.FEATURE_SCHEMA_VERSION)

        # Metrics — more than accuracy.
        for label in schema.VALID_TARGET_VALUES:
            self.assertIn(label, meta['precision'])
            self.assertIn(label, meta['recall'])
            self.assertIn(label, meta['f1'])
        self.assertIsInstance(meta['accuracy'], float)
        self.assertEqual(len(meta['confusion_matrix']), 2)
        self.assertGreater(meta['training_record_count'], 0)
        self.assertGreater(meta['test_record_count'], 0)
        self.assertEqual(set(meta['class_distribution']), set(schema.VALID_TARGET_VALUES))

        # Dataset provenance.
        self.assertEqual(meta['dataset_type'], 'synthetic')
        self.assertEqual(len(meta['dataset_hash']), 64)
        self.assertIn('2023-2024', meta['school_years_represented'])
        self.assertIn('quarterly', meta['reporting_systems_represented'])
        self.assertIn('three_term', meta['reporting_systems_represented'])

        # Methodology.
        self.assertIn('method', meta['split_methodology'])
        self.assertEqual(meta['missing_value_strategy'], 'native_nan')
        self.assertEqual(meta['hyperparameters']['random_state'], 42)

        # Dependency versions.
        for package in ('python', 'numpy', 'scikit-learn', 'joblib'):
            self.assertIn(package, meta['runtime_versions'])
        import sklearn
        self.assertEqual(meta['runtime_versions']['scikit-learn'], sklearn.__version__)

        # Governance.
        self.assertEqual(meta['validation_status'], 'not_reviewed')
        self.assertTrue(any('SYNTHETIC' in lim for lim in meta['limitations']))
        self.assertTrue(any('no deployment acceptance threshold' in lim.lower() for lim in meta['limitations']))

    def test_feature_importance_comes_from_the_fitted_model(self):
        import joblib
        import train_model

        version = train_model.train_candidate(FIXTURE_CSV, dataset_label='fixture', dataset_type='synthetic')
        meta = self._candidate_metadata(version)
        model = joblib.load(os.path.join(self._tmp_root, 'candidate', f'{version}.pkl'))

        self.assertEqual(set(meta['feature_importances']), set(schema.FEATURE_COLUMNS))
        for name, fitted in zip(schema.FEATURE_COLUMNS, model.feature_importances_):
            self.assertAlmostEqual(meta['feature_importances'][name], float(fitted), places=5)
        self.assertAlmostEqual(sum(meta['feature_importances'].values()), 1.0, places=4)

        # A single feature carrying everything would mean the model is a
        # threshold in disguise, the exact defect the legacy prototype had.
        self.assertLess(max(meta['feature_importances'].values()), 0.95)

    def test_baseline_comparison_is_recorded_alongside_the_candidate(self):
        import train_model

        version = train_model.train_candidate(FIXTURE_CSV, dataset_label='fixture', dataset_type='synthetic')
        comparison = self._candidate_metadata(version)['baseline_comparison']

        self.assertIn('baseline', comparison)
        self.assertIn('candidate', comparison)
        self.assertIn('accuracy', comparison['baseline'])
        self.assertIn('recall', comparison['baseline'])
        for label in schema.VALID_TARGET_VALUES:
            self.assertIn(label, comparison['baseline']['recall'])
        self.assertIn('academic rule', comparison['baseline']['rule'])

    def test_a_cohort_split_is_preferred_when_two_school_years_exist(self):
        import train_model

        version = train_model.train_candidate(FIXTURE_CSV, dataset_label='fixture', dataset_type='synthetic')
        split = self._candidate_metadata(version)['split_methodology']

        self.assertEqual(split['method'], 'cohort_time_aware')
        self.assertNotIn(split['test_school_year'], split['train_school_years'])

    def test_a_single_year_dataset_falls_back_to_a_grouped_split(self):
        import train_model

        rows = []
        for i in range(30):
            for period in (1, 2, 3):
                rows.append(make_row(
                    anonymous_student_id=f'anon-{i}', period_index=str(period),
                    outcome='intervention' if i % 2 else 'no_intervention',
                    current_average=str(60.0 + i),
                ))
        self._write_csv(rows)

        version = train_model.train_candidate(self._tmp_csv_path, dataset_label='one year', dataset_type='synthetic')
        split = self._candidate_metadata(version)['split_methodology']

        self.assertEqual(split['method'], 'grouped_random')
        self.assertEqual(split['group_column'], 'anonymous_student_id')

    def test_no_learner_appears_on_both_sides_of_a_grouped_split(self):
        """
        The leakage this guards against is subtle: a learner contributes
        three highly-correlated period rows, so a row-level split can put
        their Term 1 in training and their Term 2 in test, and the resulting
        score measures memorisation of that learner rather than
        generalisation to a new one.

        Each learner here gets a UNIQUE current_average, so a row maps back
        to exactly one learner and "which learners ended up on each side"
        is directly checkable rather than inferred.
        """
        import train_model

        rows = []
        for i in range(30):
            for period in (1, 2, 3):
                rows.append(make_row(
                    anonymous_student_id=f'anon-{i:03d}', period_index=str(period),
                    outcome='intervention' if i % 2 else 'no_intervention',
                    current_average=str(40.0 + i),  # unique per learner, identical within a learner
                ))

        summary = validate_dataset(rows, 'grouped')
        X, y, groups, kept = train_model.build_matrices(summary.valid_rows)
        X_train, X_test, _, _, info = train_model.choose_split(kept, X, y, groups, 0.25, 42)

        self.assertEqual(info['method'], 'grouped_random')
        self.assertEqual(len(X_train) + len(X_test), len(X))

        average_column = schema.FEATURE_COLUMNS.index('current_average')
        train_learners = {row[average_column] for row in X_train}
        test_learners = {row[average_column] for row in X_test}

        self.assertTrue(train_learners, 'the training side is empty')
        self.assertTrue(test_learners, 'the held-out side is empty')
        self.assertTrue(
            train_learners.isdisjoint(test_learners),
            f'learner(s) present on both sides of the split: {sorted(train_learners & test_learners)}'
        )

    def test_blank_optional_features_reach_the_matrix_as_nan_not_zero(self):
        import numpy as np
        import train_model

        summary = validate_dataset(balanced_rows(20, exam_mean='', prev_period_average='', trend_delta=''), 'blanks')
        X, _, _, _ = train_model.build_matrices(summary.valid_rows)

        exam_column = X[:, schema.FEATURE_COLUMNS.index('exam_mean')]
        self.assertTrue(np.isnan(exam_column).all())
        self.assertFalse((exam_column == 0).any())

    def test_a_dataset_with_a_no_exam_cohort_still_trains(self):
        import train_model

        rows = []
        for year in ('2022-2023', '2023-2024'):
            for i in range(30):
                rows.append(make_row(
                    anonymous_student_id=f'anon-{year}-{i}', school_year=year,
                    exam_mean='' if i % 3 == 0 else '70.0',
                    outcome='intervention' if i % 2 else 'no_intervention',
                    current_average=str(55.0 + (i % 40)),
                ))
        self._write_csv(rows)

        version = train_model.train_candidate(self._tmp_csv_path, dataset_label='mixed exam profiles', dataset_type='synthetic')

        self.assertIsNotNone(version)
        self.assertEqual(model_registry.list_models('active'), [])

    def test_training_is_reproducible_for_a_fixed_random_state(self):
        import train_model

        first = self._candidate_metadata(
            train_model.train_candidate(FIXTURE_CSV, dataset_label='fixture', dataset_type='synthetic'))
        second = self._candidate_metadata(
            train_model.train_candidate(FIXTURE_CSV, dataset_label='fixture', dataset_type='synthetic'))

        self.assertEqual(first['accuracy'], second['accuracy'])
        self.assertEqual(first['confusion_matrix'], second['confusion_matrix'])
        self.assertEqual(first['feature_importances'], second['feature_importances'])
        self.assertEqual(first['dataset_hash'], second['dataset_hash'])


class TestSyntheticModelsCannotBePromoted(RegistryIsolatedTestCase):
    def _run_cli(self, *args):
        import subprocess

        return subprocess.run(
            [sys.executable, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'model_registry.py'), *args],
            capture_output=True, text=True,
            env=dict(os.environ, DSS_MODELS_ROOT=self._tmp_root),
        )

    def test_the_cli_refuses_to_promote_a_synthetic_candidate(self):
        model_registry.save_candidate(object(), self._metadata('v_syn', dataset_type='synthetic'))

        completed = self._run_cli('promote', 'v_syn')

        self.assertEqual(completed.returncode, 2, completed.stdout + completed.stderr)
        self.assertIn('SYNTHETIC', completed.stderr)
        self.assertEqual(model_registry.list_models('active'), [],
                         'a synthetic candidate must not become active')
        self.assertEqual(model_registry.list_models('candidate')[0]['status'], 'candidate')

    def test_the_cli_promotes_a_real_candidate_and_can_roll_it_back(self):
        """Promotion and rollback through the actual command an operator types, not only the Python API."""
        model_registry.save_candidate(object(), self._metadata('v_real_1', dataset_type='real_historical'))
        model_registry.save_candidate(object(), self._metadata('v_real_2', dataset_type='real_historical'))

        promoted = self._run_cli('promote', 'v_real_1')
        self.assertEqual(promoted.returncode, 0, promoted.stderr)
        self.assertEqual(model_registry.get_active_model_metadata()['version'], 'v_real_1')

        self._run_cli('promote', 'v_real_2')
        self.assertEqual(model_registry.get_active_model_metadata()['version'], 'v_real_2')

        rolled_back = self._run_cli('rollback', 'v_real_1')
        self.assertEqual(rolled_back.returncode, 0, rolled_back.stderr)
        self.assertEqual(model_registry.get_active_model_metadata()['version'], 'v_real_1')
        self.assertIn('v_real_2', [m['version'] for m in model_registry.list_models('archived')])

    def test_the_candidate_the_fixture_produces_is_tagged_synthetic(self):
        import train_model

        version = train_model.train_candidate(FIXTURE_CSV, dataset_label='fixture', dataset_type='synthetic')
        meta = [m for m in model_registry.list_models('candidate') if m['version'] == version][0]

        self.assertEqual(meta['dataset_type'], 'synthetic')


if __name__ == '__main__':
    unittest.main(verbosity=2)
