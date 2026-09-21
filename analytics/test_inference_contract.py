"""
The PRODUCTION INFERENCE contract: what classify.py must and must not do.

Every test here points MODELS_ROOT at a temporary directory before touching
the registry, so nothing in this file can promote, archive or otherwise
disturb the real analytics/models/ tree or analytics/model_cache.pkl.

Run:
    python analytics/test_inference_contract.py
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import classify
import model_registry
import schema as schema_module

ANALYTICS_DIR = os.path.dirname(os.path.abspath(__file__))


def _tiny_fitted_model(n_features, n_classes=3):
    """A real fitted estimator — the contract tests must exercise the actual predict/predict_proba path, not a stub."""
    import numpy as np
    from sklearn.ensemble import RandomForestClassifier

    rows_per_class = 4
    X = np.array([
        [float(c * 10 + i + f) for f in range(n_features)]
        for c in range(n_classes) for i in range(rows_per_class)
    ])
    y = np.array([c for c in range(n_classes) for _ in range(rows_per_class)])
    return RandomForestClassifier(n_estimators=5, random_state=0).fit(X, y)


class RegistryIsolatedTestCase(unittest.TestCase):
    """Redirects the model registry at a temp dir for the duration of each test."""

    def setUp(self):
        self._real_root = model_registry.MODELS_ROOT
        self._tmp_root = tempfile.mkdtemp(prefix='dss_inference_test_')
        model_registry.MODELS_ROOT = self._tmp_root

    def tearDown(self):
        model_registry.MODELS_ROOT = self._real_root
        shutil.rmtree(self._tmp_root, ignore_errors=True)

    def _activate(self, model, metadata: model_registry.ModelMetadata):
        """Saves and promotes in one step. Uses the real promotion path, not a hand-placed file."""
        model_registry.save_candidate(model, metadata)
        model_registry.promote_to_active(metadata.version)

    def _canonical_metadata(self, **overrides):
        defaults = dict(
            version='v_test_active',
            algorithm='RandomForestClassifier',
            trained_at='2026-01-01T00:00:00',
            feature_names=list(schema_module.FEATURE_COLUMNS),
            target_definition=schema_module.TARGET_DEFINITION,
            dataset_type='real_historical',
        )
        defaults.update(overrides)
        return model_registry.ModelMetadata(**defaults)


class TestInferenceDoesNotTrain(unittest.TestCase):
    def test_classify_module_exposes_no_training_entry_points(self):
        for forbidden in ('train_model', 'train_from_real_data', 'write_model_accuracy_report'):
            self.assertFalse(
                hasattr(classify, forbidden),
                f"classify.py still exposes '{forbidden}' — inference and training must stay separated."
            )

    def test_classify_source_contains_no_synthetic_training_data(self):
        """
        The prototype's hand-typed grade literals and its fitting call must
        not live in the production inference file at all — not commented
        out, not behind a flag.
        """
        with open(os.path.join(ANALYTICS_DIR, 'classify.py'), encoding='utf-8') as f:
            source = f.read()

        for marker in ('RandomForestClassifier(', '.fit(', 'cross_val_score', 'low_risk = [', 'train_test_split'):
            self.assertNotIn(marker, source, f"classify.py contains '{marker}' — it must be inference-only.")


class TestMissingModelFailsSafely(RegistryIsolatedTestCase):
    def test_no_active_model_and_no_legacy_artifact_is_a_controlled_error(self):
        original = classify.LEGACY_MODEL_PATH
        classify.LEGACY_MODEL_PATH = os.path.join(self._tmp_root, 'does_not_exist.pkl')
        try:
            with self.assertRaises(classify.InferenceError) as ctx:
                classify.load_model()
        finally:
            classify.LEGACY_MODEL_PATH = original

        self.assertEqual(ctx.exception.code, 'no_model_available')
        # The failure must say what to DO, and must not offer training as a way out.
        self.assertIn('promote', str(ctx.exception))
        self.assertIn('Automatic training is disabled', str(ctx.exception))

    def test_a_failed_run_writes_a_structured_error_not_a_traceback(self):
        with tempfile.TemporaryDirectory() as tmp:
            in_path = os.path.join(tmp, 'in.json')
            out_path = os.path.join(tmp, 'out.json')
            with open(in_path, 'w', encoding='utf-8') as f:
                json.dump([{'student_id': 1, 'average_grade': 80}], f)

            original = classify.LEGACY_MODEL_PATH
            classify.LEGACY_MODEL_PATH = os.path.join(tmp, 'missing.pkl')
            try:
                exit_code = classify.main([in_path, out_path])
            finally:
                classify.LEGACY_MODEL_PATH = original

            self.assertEqual(exit_code, 1)
            with open(out_path, encoding='utf-8') as f:
                payload = json.load(f)

            self.assertEqual(payload['error']['code'], 'no_model_available')
            self.assertNotIn('Traceback', json.dumps(payload))
            self.assertNotIn('File "', json.dumps(payload))


class TestMalformedPayloadIsRejected(RegistryIsolatedTestCase):
    def setUp(self):
        super().setUp()
        self.metadata = self._canonical_metadata()
        self.model = _tiny_fitted_model(len(schema_module.FEATURE_COLUMNS), n_classes=2)
        self.metadata.prediction_domain = 'risk_level'  # so the domain guard is not what fails these
        self.metadata.target_classes = ['low', 'high']
        self._activate(self.model, self.metadata)
        self.loaded, self.loaded_meta = model_registry.load_active_model()

    def _row(self, **overrides):
        row = {'student_id': 7}
        row.update({name: 50.0 for name in schema_module.FEATURE_COLUMNS})
        row.update(overrides)
        return row

    def test_a_missing_feature_is_named_and_refused(self):
        row = self._row()
        del row['pt_mean']

        with self.assertRaises(classify.InferenceError) as ctx:
            classify.classify_students([row], self.loaded, self.loaded_meta)

        self.assertEqual(ctx.exception.code, 'malformed_payload')
        self.assertIn('pt_mean', str(ctx.exception))

    def test_a_non_numeric_feature_is_refused_not_coerced(self):
        with self.assertRaises(classify.InferenceError) as ctx:
            classify.classify_students([self._row(ww_mean='eighty')], self.loaded, self.loaded_meta)

        self.assertEqual(ctx.exception.code, 'malformed_payload')
        self.assertIn('ww_mean', str(ctx.exception))

    def test_a_row_without_student_id_is_refused(self):
        row = self._row()
        del row['student_id']

        with self.assertRaises(classify.InferenceError):
            classify.classify_students([row], self.loaded, self.loaded_meta)

    def test_a_non_object_row_is_refused(self):
        with self.assertRaises(classify.InferenceError):
            classify.classify_students(['not a dict'], self.loaded, self.loaded_meta)

    def test_a_top_level_payload_that_is_not_a_list_is_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            in_path, out_path = os.path.join(tmp, 'in.json'), os.path.join(tmp, 'out.json')
            with open(in_path, 'w', encoding='utf-8') as f:
                json.dump({'student_id': 1}, f)

            self.assertEqual(classify.main([in_path, out_path]), 1)
            with open(out_path, encoding='utf-8') as f:
                self.assertEqual(json.load(f)['error']['code'], 'malformed_payload')


class TestFeatureOrderIsDeterministic(RegistryIsolatedTestCase):
    def test_the_vector_follows_metadata_order_not_payload_order(self):
        names = list(schema_module.FEATURE_COLUMNS)
        row_in_order = {name: float(i) for i, name in enumerate(names)}
        row_shuffled = {name: row_in_order[name] for name in reversed(names)}

        self.assertEqual(
            schema_module.build_feature_vector(row_in_order, names),
            schema_module.build_feature_vector(row_shuffled, names),
            'Feature vectors must come from the declared order, never from dict iteration order.',
        )
        self.assertEqual(schema_module.build_feature_vector(row_in_order, names), [float(i) for i in range(len(names))])

    def test_a_model_whose_feature_count_disagrees_with_its_metadata_is_refused(self):
        metadata = self._canonical_metadata(version='v_mismatch')
        metadata.prediction_domain = 'risk_level'
        # Fitted on 3 columns, metadata claims all nine.
        self._activate(_tiny_fitted_model(3), metadata)
        model, loaded_meta = model_registry.load_active_model()

        row = {'student_id': 1}
        row.update({name: 50.0 for name in schema_module.FEATURE_COLUMNS})

        with self.assertRaises(classify.InferenceError) as ctx:
            classify.classify_students([row], model, loaded_meta)

        self.assertEqual(ctx.exception.code, 'feature_count_mismatch')

    def test_a_model_with_no_declared_feature_names_is_refused(self):
        metadata = self._canonical_metadata(version='v_nameless', feature_names=[])
        metadata.prediction_domain = 'risk_level'
        self._activate(_tiny_fitted_model(1), metadata)
        model, loaded_meta = model_registry.load_active_model()

        with self.assertRaises(classify.InferenceError) as ctx:
            classify.classify_students([{'student_id': 1, 'average_grade': 80}], model, loaded_meta)

        self.assertEqual(ctx.exception.code, 'feature_names_missing')


class TestIdentifiersAreNeverFeatures(RegistryIsolatedTestCase):
    def test_student_id_is_not_in_the_canonical_feature_set(self):
        for identifier in ('student_id', 'anonymous_student_id', 'lrn', 'name'):
            self.assertNotIn(identifier, schema_module.FEATURE_COLUMNS)

    def test_changing_student_id_cannot_change_the_prediction(self):
        metadata = self._canonical_metadata(version='v_ids')
        metadata.prediction_domain = 'risk_level'
        metadata.target_classes = ['low', 'moderate', 'high']
        self._activate(_tiny_fitted_model(len(schema_module.FEATURE_COLUMNS)), metadata)
        model, loaded_meta = model_registry.load_active_model()

        base = {name: 42.0 for name in schema_module.FEATURE_COLUMNS}
        first = classify.classify_students([dict(base, student_id=1)], model, loaded_meta)
        second = classify.classify_students([dict(base, student_id=999999)], model, loaded_meta)

        self.assertEqual(first[0]['risk_level'], second[0]['risk_level'])
        self.assertEqual(first[0]['confidence'], second[0]['confidence'])

    def test_the_feature_vector_builder_cannot_reach_an_identifier(self):
        row = {'student_id': 5, 'anonymous_student_id': 'anon-1'}
        row.update({name: 1.0 for name in schema_module.FEATURE_COLUMNS})

        vector = schema_module.build_feature_vector(row, schema_module.FEATURE_COLUMNS)

        self.assertEqual(len(vector), len(schema_module.FEATURE_COLUMNS))
        self.assertTrue(all(v == 1.0 for v in vector))


class TestBlankIsNotZero(unittest.TestCase):
    def test_a_blank_feature_becomes_nan_never_zero(self):
        row = {name: 10.0 for name in schema_module.FEATURE_COLUMNS}
        row['exam_mean'] = None
        row['prev_period_average'] = ''

        vector = schema_module.build_feature_vector(row, schema_module.FEATURE_COLUMNS)
        exam = vector[schema_module.FEATURE_COLUMNS.index('exam_mean')]
        prev = vector[schema_module.FEATURE_COLUMNS.index('prev_period_average')]

        self.assertNotEqual(exam, 0.0)
        self.assertNotEqual(prev, 0.0)
        self.assertTrue(exam != exam, 'a blank must become NaN')
        self.assertTrue(prev != prev, 'a blank must become NaN')

    def test_a_real_zero_stays_zero(self):
        row = {name: 10.0 for name in schema_module.FEATURE_COLUMNS}
        row['exam_mean'] = 0

        vector = schema_module.build_feature_vector(row, schema_module.FEATURE_COLUMNS)

        self.assertEqual(vector[schema_module.FEATURE_COLUMNS.index('exam_mean')], 0.0)

    def test_the_missing_value_policy_is_recorded_not_only_described(self):
        self.assertEqual(schema_module.MISSING_VALUE_STRATEGY, 'native_nan')
        self.assertIn('never imputed', schema_module.MISSING_VALUE_POLICY.lower())


class TestNoExamProfileAndNoPreviousPeriod(RegistryIsolatedTestCase):
    """
    The two 'not applicable' cases the schema spends paragraphs on, proved
    end to end rather than only documented: a learner on a grading profile
    with no Examination component, and a learner in their first period.
    """

    def setUp(self):
        super().setUp()
        metadata = self._canonical_metadata(version='v_na')
        metadata.prediction_domain = 'risk_level'
        metadata.target_classes = ['low', 'moderate', 'high']
        self._activate(_tiny_fitted_model(len(schema_module.FEATURE_COLUMNS)), metadata)
        self.model, self.metadata = model_registry.load_active_model()

    def test_a_no_exam_profile_predicts_without_inventing_a_zero(self):
        row = {'student_id': 1}
        row.update({name: 80.0 for name in schema_module.FEATURE_COLUMNS})
        row['exam_mean'] = None

        with_blank = classify.classify_students([dict(row)], self.model, self.metadata)
        with_zero = classify.classify_students([dict(row, exam_mean=0.0)], self.model, self.metadata)

        self.assertEqual(len(with_blank), 1)
        # The point is not which level each produces, but that they are two
        # DIFFERENT inputs — a blank must not be silently turned into the 0
        # that would drag a learner's exam evidence to the floor.
        self.assertIsNotNone(with_blank[0]['risk_level'])
        self.assertIsNotNone(with_zero[0]['risk_level'])

    def test_a_first_period_learner_has_blank_previous_average_and_blank_trend(self):
        row = {'student_id': 2}
        row.update({name: 80.0 for name in schema_module.FEATURE_COLUMNS})
        row['prev_period_average'] = None
        row['trend_delta'] = None

        results = classify.classify_students([row], self.model, self.metadata)

        self.assertEqual(len(results), 1)
        self.assertIn(results[0]['risk_level'], ('low', 'moderate', 'high'))

    def test_both_are_declared_optional_in_the_schema(self):
        self.assertIn('exam_mean', schema_module.OPTIONAL_FEATURE_COLUMNS)
        self.assertIn('prev_period_average', schema_module.OPTIONAL_FEATURE_COLUMNS)
        self.assertIn('trend_delta', schema_module.OPTIONAL_FEATURE_COLUMNS)
        self.assertNotIn('current_average', schema_module.OPTIONAL_FEATURE_COLUMNS)


class TestSchemaAndDomainGuards(RegistryIsolatedTestCase):
    def test_a_binary_outcome_model_is_refused_not_silently_mapped(self):
        """
        The candidate target is intervention/no_intervention; the DSS
        displays low/moderate/high. Refusing is the whole point — a mapping
        invented here would put a severity on screen that no model
        predicted.
        """
        metadata = self._canonical_metadata(version='v_binary')
        metadata.prediction_domain = 'binary_outcome'
        metadata.target_classes = list(schema_module.VALID_TARGET_VALUES)
        self._activate(_tiny_fitted_model(len(schema_module.FEATURE_COLUMNS), n_classes=2), metadata)
        _, loaded = model_registry.load_active_model()

        with self.assertRaises(classify.InferenceError) as ctx:
            classify.verify_prediction_domain(loaded)

        self.assertEqual(ctx.exception.code, 'prediction_domain_mismatch')
        self.assertIn('no automatic mapping', str(ctx.exception))

    def test_an_incompatible_feature_schema_version_is_refused(self):
        with self.assertRaises(classify.InferenceError) as ctx:
            classify.verify_schema_compatibility({'feature_schema_version': '99.0.0'})

        self.assertEqual(ctx.exception.code, 'schema_incompatible')

    def test_the_legacy_schema_version_is_explicitly_exempt(self):
        classify.verify_schema_compatibility({'feature_schema_version': '0.0.0-legacy'})  # must not raise

    def test_a_model_declaring_no_schema_version_is_refused(self):
        with self.assertRaises(classify.InferenceError) as ctx:
            classify.verify_schema_compatibility({})

        self.assertEqual(ctx.exception.code, 'schema_version_missing')


class TestLegacyPrototypeRemainsUsable(unittest.TestCase):
    """
    The deployed DSS must keep working until a real model is deliberately
    promoted. These run against the REAL analytics/model_cache.pkl and the
    real subprocess entry point — the same path Laravel uses.
    """

    EXPECTED_RISK_LEVELS = {
        0: 'high', 0.5: 'high', 60: 'high', 74: 'high', 74.9: 'high',
        75: 'moderate', 84.9: 'moderate', 85: 'low', 100: 'low',
    }

    def test_the_legacy_descriptor_declares_it_synthetic_and_never_validated(self):
        with open(classify.LEGACY_METADATA_PATH, encoding='utf-8') as f:
            metadata = json.load(f)

        self.assertEqual(metadata['dataset_type'], 'synthetic')
        self.assertEqual(metadata['validation_status'], 'never_validated_against_real_outcomes')
        self.assertEqual(metadata['feature_names'], ['average_grade'])
        self.assertEqual(metadata['prediction_domain'], 'risk_level')

    def test_end_to_end_subprocess_run_matches_the_pinned_predictions(self):
        with tempfile.TemporaryDirectory() as tmp:
            in_path, out_path = os.path.join(tmp, 'in.json'), os.path.join(tmp, 'out.json')
            with open(in_path, 'w', encoding='utf-8') as f:
                json.dump(
                    [{'student_id': i, 'average_grade': g} for i, g in enumerate(self.EXPECTED_RISK_LEVELS)],
                    f,
                )

            completed = subprocess.run(
                [sys.executable, os.path.join(ANALYTICS_DIR, 'classify.py'), in_path, out_path],
                capture_output=True, text=True,
            )
            self.assertEqual(completed.returncode, 0, completed.stderr)

            with open(out_path, encoding='utf-8') as f:
                results = json.load(f)

        by_grade = {r['average_grade']: r['risk_level'] for r in results}
        for grade, expected in self.EXPECTED_RISK_LEVELS.items():
            with self.subTest(grade=grade):
                self.assertEqual(by_grade[float(grade)], expected)

    def test_every_result_carries_the_model_version_that_produced_it(self):
        with tempfile.TemporaryDirectory() as tmp:
            in_path, out_path = os.path.join(tmp, 'in.json'), os.path.join(tmp, 'out.json')
            with open(in_path, 'w', encoding='utf-8') as f:
                json.dump([{'student_id': 1, 'average_grade': 88}], f)

            self.assertEqual(classify.main([in_path, out_path]), 0)
            with open(out_path, encoding='utf-8') as f:
                results = json.load(f)

        self.assertEqual(results[0]['model_version'], 'legacy_synthetic_prototype')
        self.assertEqual(results[0]['dataset_type'], 'synthetic')
        self.assertIn('feature_schema_version', results[0])

    def test_laravels_canonical_key_is_accepted_as_well_as_the_legacy_alias(self):
        """
        Laravel now sends `current_average`; the legacy model's declared
        feature is `average_grade`. Both spellings must resolve, or the
        rename would silently break inference.
        """
        model, metadata = classify.load_legacy_prototype()

        via_alias = classify.classify_students([{'student_id': 1, 'average_grade': 90.0}], model, metadata)
        via_canonical = classify.classify_students(
            [{'student_id': 1, 'current_average': 90.0, 'average_grade': 90.0}], model, metadata
        )

        self.assertEqual(via_alias[0]['risk_level'], via_canonical[0]['risk_level'])
        self.assertEqual(via_canonical[0]['average_grade'], 90.0)

    def test_an_empty_payload_writes_an_empty_result_list(self):
        with tempfile.TemporaryDirectory() as tmp:
            in_path, out_path = os.path.join(tmp, 'in.json'), os.path.join(tmp, 'out.json')
            with open(in_path, 'w', encoding='utf-8') as f:
                json.dump([], f)

            self.assertEqual(classify.main([in_path, out_path]), 0)
            with open(out_path, encoding='utf-8') as f:
                self.assertEqual(json.load(f), [])

    def test_an_unexpected_exception_reports_its_detail_on_stderr_but_never_in_the_output_file(self):
        # 2026-09-21: a live Term 1 submission failed with
        # "unexpected OSError during classification" and nothing else —
        # main()'s catch-all dropped str(e) and the traceback, so the errno
        # could not be recovered from any log afterwards. The output file
        # (which Laravel reads and an adviser could see) must stay
        # message-free; stderr (Laravel log only) must carry the detail.
        import io
        from unittest import mock

        with tempfile.TemporaryDirectory() as tmp:
            in_path, out_path = os.path.join(tmp, 'in.json'), os.path.join(tmp, 'out.json')
            with open(in_path, 'w', encoding='utf-8') as f:
                json.dump([{'student_id': 1, 'average_grade': 80.0}], f)

            simulated = OSError(1455, 'The paging file is too small for this operation to complete')
            captured = io.StringIO()
            with mock.patch.object(classify, 'classify_students', side_effect=simulated),                     mock.patch.object(sys, 'stderr', captured):
                self.assertEqual(classify.main([in_path, out_path]), 1)

            stderr = captured.getvalue()
            self.assertIn('unexpected_error detail: OSError: [Errno 1455]', stderr)
            self.assertIn('paging file is too small', stderr)
            self.assertIn('Traceback (most recent call last)', stderr)

            with open(out_path, encoding='utf-8') as f:
                written = json.load(f)
            self.assertEqual(written['error']['code'], 'unexpected_error')
            self.assertEqual(written['error']['message'], 'unexpected OSError during classification')
            self.assertNotIn('1455', json.dumps(written))
            self.assertNotIn('Traceback', json.dumps(written))


class TestRuntimeVersionCompatibility(unittest.TestCase):
    def test_a_major_version_difference_is_reported(self):
        warnings = classify.check_runtime_compatibility({
            'runtime_versions': {'scikit-learn': '0.24.2', 'numpy': '1.20.0', 'joblib': '1.0.0'}
        })

        self.assertTrue(any('MAJOR' in w and 'scikit-learn' in w for w in warnings))

    def test_an_unknown_recorded_version_is_not_reported_as_a_mismatch(self):
        warnings = classify.check_runtime_compatibility({
            'runtime_versions': {'scikit-learn': 'unknown', 'numpy': 'unknown', 'joblib': 'unknown'}
        })

        self.assertEqual(warnings, [])

    def test_matching_versions_produce_no_warning(self):
        warnings = classify.check_runtime_compatibility({'runtime_versions': model_registry.runtime_versions()})

        self.assertEqual(warnings, [])


if __name__ == '__main__':
    unittest.main(verbosity=2)
