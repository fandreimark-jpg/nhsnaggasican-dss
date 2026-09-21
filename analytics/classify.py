"""
PRODUCTION INFERENCE ONLY.

This file loads the active approved model and predicts. It does not train,
does not generate training data, does not cross-validate, and does not
write a metrics report. Those belong to `train_model.py` (the one
authoritative training entry point) and, for the retained prototype,
`legacy/prototype_model.py`.

If no model is available, this file FAILS with a controlled error. It never
quietly fabricates one — a production classifier that trains itself from
hand-typed grade bands whenever its artifact is missing is a silent
correctness failure dressed as resilience.

MODEL RESOLUTION ORDER
----------------------
1. `model_registry`'s ACTIVE model, if one has been explicitly promoted.
2. Otherwise the LEGACY SYNTHETIC PROTOTYPE (`analytics/model_cache.pkl`,
   described by `analytics/legacy/legacy_model.json`) — retained so the
   deployed DSS keeps working, and clearly labelled as synthetic in every
   result it produces.
3. Otherwise: a controlled error. No model, no prediction.

THE MODEL CURRENTLY SERVING IS THE LEGACY SYNTHETIC PROTOTYPE. It was
trained on 90 hand-typed average_grade values whose classes were assigned
by predetermined grade bands. Its accuracy figures are not real-world
accuracy. See `analytics/README.md`, "Why the previous prototype could be
described as hardcoded".

FEATURE ORDER comes from the loaded model's own metadata, never from the
order Laravel happened to serialise its JSON in. See
`schema.build_feature_vector()`.

I/O CONTRACT (unchanged for Laravel):
    python classify.py <input.json> <output.json>

Input: a JSON list of per-learner feature dicts.
Output: a JSON list of result dicts, one per input row, each carrying
    student_id, average_grade, risk_level, confidence, plus model_version
    and feature_schema_version so a stored result can always be traced back
    to the model that produced it.

On failure the output file receives a structured {"error": ...} object and
the process exits non-zero. Python tracebacks are never written to it.
"""

from __future__ import annotations

import json
import os
import sys
import traceback

ANALYTICS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ANALYTICS_DIR)

import model_registry
import schema as schema_module
from schema import SchemaError

# The retained prototype. Loaded only when the registry has no active model.
LEGACY_MODEL_PATH = os.path.join(ANALYTICS_DIR, 'model_cache.pkl')
LEGACY_METADATA_PATH = os.path.join(ANALYTICS_DIR, 'legacy', 'legacy_model.json')

# Laravel's historical key for what schema.py calls `current_average`. Both
# are accepted on input; see resolve_feature_aliases().
FEATURE_ALIASES = {
    'current_average': ('average_grade',),
    'prev_period_average': ('prev_term_average',),
}


class InferenceError(RuntimeError):
    """
    A controlled failure with an operator-readable message. Everything
    raised deliberately by this module is one of these, so main() can
    distinguish "we know what went wrong" from an unexpected crash and
    still never leak a traceback into the output file.
    """

    def __init__(self, message: str, code: str = 'inference_error'):
        super().__init__(message)
        self.code = code


# ---------------------------------------------------------------------------
# Model loading
# ---------------------------------------------------------------------------

def load_legacy_prototype():
    """Loads model_cache.pkl plus its hand-written descriptor. Raises InferenceError if either is missing."""
    import joblib

    if not os.path.isfile(LEGACY_MODEL_PATH):
        raise InferenceError(
            'No active model has been promoted and the legacy prototype artifact '
            '(analytics/model_cache.pkl) is missing. Restore an approved model artifact or promote a '
            'candidate with `python analytics/model_registry.py promote <version>`. '
            'Automatic training is disabled by design.',
            code='no_model_available',
        )

    if not os.path.isfile(LEGACY_METADATA_PATH):
        raise InferenceError(
            'analytics/legacy/legacy_model.json is missing. The legacy model cannot be served without its '
            'descriptor, because the descriptor is what declares its feature order and that it is synthetic.',
            code='legacy_metadata_missing',
        )

    with open(LEGACY_METADATA_PATH, encoding='utf-8') as f:
        metadata = json.load(f)

    return joblib.load(LEGACY_MODEL_PATH), metadata


def load_model():
    """
    Returns (estimator, metadata). Resolution order is documented at the
    top of this file. Never trains anything.
    """
    try:
        model, metadata = model_registry.load_active_model()
    except FileNotFoundError as e:
        raise InferenceError(str(e), code='registry_inconsistent')

    if model is not None:
        return model, metadata

    return load_legacy_prototype()


def check_runtime_compatibility(metadata: dict) -> list:
    """
    Compares the versions a model was fitted under against the versions
    unpickling it now. Returns a list of human-readable warnings; never
    raises, because a minor version drift is usually harmless and refusing
    to serve on it would take the DSS down for a cosmetic reason.

    A MAJOR version difference in scikit-learn is reported explicitly —
    that is the case where a silently-degraded unpickle is plausible and
    the operator needs to know rather than find out from wrong predictions.
    """
    warnings = []
    recorded = metadata.get('runtime_versions') or {}
    current = model_registry.runtime_versions()

    for package in ('scikit-learn', 'numpy', 'joblib'):
        was = str(recorded.get(package, 'unknown'))
        now = str(current.get(package, 'unknown'))
        if was in ('unknown', 'not installed', ''):
            continue
        if was == now:
            continue
        severity = 'MAJOR' if was.split('.')[0] != now.split('.')[0] else 'minor'
        warnings.append(
            f"{severity} version difference for {package}: model fitted under {was}, running {now}. "
            f"A model unpickled under a different {package} major version may behave differently than it did when evaluated."
        )

    return warnings


def verify_schema_compatibility(metadata: dict) -> None:
    """
    Refuses to serve a model whose declared feature-schema major version
    does not match `schema.FEATURE_SCHEMA_VERSION`.

    The legacy prototype declares `0.0.0-legacy` and is exempt: it predates
    the canonical schema, reads a single always-present column, and is
    served through the explicit legacy path with a documented feature list
    of its own. Exempting it is not a loophole in the check — it is the
    check acknowledging that the prototype was never under this contract.
    """
    declared = str(metadata.get('feature_schema_version', ''))
    if declared.endswith('-legacy'):
        return

    if not declared:
        raise InferenceError(
            'The active model declares no feature_schema_version. A model with no schema version cannot be '
            'checked for compatibility and will not be served.',
            code='schema_version_missing',
        )

    if schema_module.major_version(declared) != schema_module.major_version(schema_module.FEATURE_SCHEMA_VERSION):
        raise InferenceError(
            f"Feature-schema mismatch: the active model was fitted against schema {declared}, this runtime is "
            f"{schema_module.FEATURE_SCHEMA_VERSION}. Retrain a candidate against the current schema and promote it; "
            f"serving a model across an incompatible schema change would feed features into the wrong columns.",
            code='schema_incompatible',
        )


def verify_prediction_domain(metadata: dict) -> None:
    """
    The production contract (App\\Models\\RiskResult, every Principal
    screen) speaks three ordered risk levels: low / moderate / high. The
    candidate training target is BINARY: intervention / no_intervention.

    These are different questions. "Did this learner receive a documented
    intervention" is not a severity scale, and there is no defensible
    automatic translation from one to the other — mapping intervention ->
    high and no_intervention -> low would invent a severity the model never
    predicted and erase the moderate level entirely.

    So: a binary-target model is REFUSED here rather than quietly mapped.
    Making it serveable is a deliberate design decision (an explicit,
    documented compatibility layer, or changing what the UI shows) taken
    with the school, not a mapping added to make a test pass.
    """
    domain = metadata.get('prediction_domain', 'risk_level')
    if domain == 'risk_level':
        return

    raise InferenceError(
        f"The active model predicts '{domain}' "
        f"({', '.join(metadata.get('target_classes') or [])}), but this inference endpoint is contracted to return "
        f"a three-level risk_level (low/moderate/high) that the DSS stores and displays. These are different "
        f"questions and there is no automatic mapping between them. Keep this model out of production until an "
        f"explicit compatibility design is agreed — see analytics/README.md, 'Rule-based DSS layer'.",
        code='prediction_domain_mismatch',
    )


# ---------------------------------------------------------------------------
# Feature preparation
# ---------------------------------------------------------------------------

def resolve_feature_aliases(row: dict) -> dict:
    """
    Fills a canonical feature name from its historical Laravel alias when
    the canonical key is absent, so a payload from either side of the
    rename works. Never overwrites a canonical value that is present.

    This is a compatibility shim with one job and a known end: once Laravel
    sends only canonical names everywhere, FEATURE_ALIASES empties and this
    becomes a no-op. It exists so the rename could ship without a
    lockstep deploy, not as a permanent second vocabulary.
    """
    resolved = dict(row)
    for canonical, aliases in FEATURE_ALIASES.items():
        if resolved.get(canonical) is not None:
            continue
        for alias in aliases:
            if alias in resolved and resolved[alias] is not None:
                resolved[canonical] = resolved[alias]
                break
    return resolved


def prepare_matrix(payload: list, feature_names: list):
    """
    Builds the feature matrix in the model's own declared order.

    Raises InferenceError naming the offending row and column rather than
    letting a SchemaError escape — an adviser pressing Submit Report should
    produce a log line an admin can act on, not a stack trace.
    """
    import numpy as np

    matrix = []
    for index, row in enumerate(payload):
        if not isinstance(row, dict):
            raise InferenceError(f'row {index} is not an object', code='malformed_payload')
        if 'student_id' not in row:
            raise InferenceError(f'row {index} has no student_id', code='malformed_payload')

        try:
            matrix.append(schema_module.build_feature_vector(resolve_feature_aliases(row), feature_names))
        except SchemaError as e:
            raise InferenceError(f'row {index} (student_id {row.get("student_id")}): {e}', code='malformed_payload')

    return np.array(matrix, dtype=float)


# ---------------------------------------------------------------------------
# Prediction
# ---------------------------------------------------------------------------

def classify_students(payload: list, model, metadata: dict) -> list:
    """
    Runs the model over `payload` and returns one structured result per
    input row, in input order.

    `confidence` is the share of the forest that voted for the predicted
    class, expressed 0-100 — a measure of the model's internal agreement,
    not of how likely the prediction is to be correct about a learner.

    `student_id` is a correlation identifier only. It is carried through
    from input to output and is NEVER part of the feature matrix: it never
    reaches `prepare_matrix`, whose columns come solely from
    `feature_names`.
    """
    if not payload:
        return []

    feature_names = list(metadata.get('feature_names') or [])
    if not feature_names:
        raise InferenceError(
            'The loaded model declares no feature_names. Feature ORDER is positional and load-bearing; '
            'a model that does not record its own column order cannot be served safely.',
            code='feature_names_missing',
        )

    matrix = prepare_matrix(payload, feature_names)

    expected = getattr(model, 'n_features_in_', None)
    if expected is not None and expected != len(feature_names):
        raise InferenceError(
            f'Model/metadata disagreement: the estimator was fitted on {expected} feature(s) but its metadata '
            f'lists {len(feature_names)} ({", ".join(feature_names)}). Refusing to predict.',
            code='feature_count_mismatch',
        )

    classes = list(metadata.get('target_classes') or schema_module.RISK_LEVELS)
    predictions = model.predict(matrix)
    probabilities = model.predict_proba(matrix)

    version = metadata.get('version', 'unknown')
    schema_version = metadata.get('feature_schema_version', 'unknown')
    dataset_type = metadata.get('dataset_type', 'unknown')

    results = []
    for row, prediction, proba in zip(payload, predictions, probabilities):
        index = int(prediction)
        if not 0 <= index < len(classes):
            raise InferenceError(
                f'Model predicted class index {index}, which its metadata does not name '
                f'(target_classes = {classes}).',
                code='unknown_class_index',
            )

        canonical_average = resolve_feature_aliases(row).get('current_average')

        results.append({
            'student_id': row['student_id'],
            # Echoed back because Laravel persists it on the RiskResult row
            # alongside the prediction. Kept under its historical key so the
            # stored contract does not move; `current_average` is its
            # canonical name in schema.py.
            'average_grade': None if canonical_average is None else float(canonical_average),
            'risk_level': classes[index],
            'prediction': classes[index],
            'confidence': round(float(max(proba)) * 100, 2),
            'model_version': version,
            'feature_schema_version': schema_version,
            'dataset_type': dataset_type,
        })

    return results


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def _write_json(path: str, data) -> bool:
    try:
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(data, f)
        return True
    except OSError as e:
        print(f'Error writing output file: {e}', file=sys.stderr)
        return False


def _fail(output_file: str | None, message: str, code: str) -> int:
    """Writes a structured error (never a traceback) and returns the exit code."""
    print(f'{code}: {message}', file=sys.stderr)
    if output_file:
        _write_json(output_file, {'error': {'code': code, 'message': message}})
    return 1


def main(argv=None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)

    if len(argv) != 2:
        print('Usage: classify.py <input_file> <output_file>', file=sys.stderr)
        print('This script performs INFERENCE ONLY. To train, use analytics/train_model.py.', file=sys.stderr)
        return 2

    input_file, output_file = argv

    try:
        with open(input_file, encoding='utf-8') as f:
            payload = json.load(f)
    except (OSError, json.JSONDecodeError) as e:
        return _fail(output_file, f'could not read the input payload: {e}', 'bad_input_file')

    if not isinstance(payload, list):
        return _fail(output_file, 'the input payload must be a JSON list of learner feature objects', 'malformed_payload')

    if not payload:
        return 0 if _write_json(output_file, []) else 1

    try:
        model, metadata = load_model()
        verify_schema_compatibility(metadata)
        verify_prediction_domain(metadata)

        for warning in check_runtime_compatibility(metadata):
            print(f'WARNING: {warning}', file=sys.stderr)

        results = classify_students(payload, model, metadata)
    except InferenceError as e:
        return _fail(output_file, str(e), e.code)
    except Exception as e:  # noqa: BLE001 — deliberately broad; see below
        # An unexpected failure must still not leak a traceback into a file
        # Laravel reads and an adviser could see: the output file gets only
        # the structured, message-free error below. The exception's own
        # message and traceback go to STDERR, which reaches nothing but the
        # Laravel log (Adviser\ReportController::runAnalytics() captures
        # it, truncated). Before 2026-09-21 the message was dropped here
        # too, so a transient "unexpected OSError during classification"
        # on a live Term 1 submission could not be diagnosed after the
        # fact — the errno was gone the moment the process exited.
        print(f'unexpected_error detail: {type(e).__name__}: {e}', file=sys.stderr)
        traceback.print_exc(file=sys.stderr)
        return _fail(output_file, f'unexpected {type(e).__name__} during classification', 'unexpected_error')

    return 0 if _write_json(output_file, results) else 1


if __name__ == '__main__':
    raise SystemExit(main())
