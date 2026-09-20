"""
Model versioning, promotion, archival and ROLLBACK.

A newly trained model never silently replaces the one production is
serving. Training writes a CANDIDATE; a human reads its metrics and runs an
explicit promotion; the model it displaces is archived, never deleted, so a
rollback is always available.

Directory layout (created on first use, under analytics/models/):

    models/
      active/     -- at most one model. classify.py loads THIS, and falls
                     back to the legacy prototype only when active/ is
                     empty (see analytics/legacy/).
      candidate/  -- newly trained, evaluated, awaiting a human decision
      archived/   -- superseded models, kept for audit/rollback, never
                     deleted by this module

Each model version is a pair of files sharing a version id:
    <version>.pkl   -- the joblib-dumped estimator
    <version>.json  -- its metadata (see ModelMetadata below)

Nothing in this module trains anything, and nothing here reads real student
data. Metadata NEVER contains a student identifier — see
ModelMetadata.assert_no_identifiers().

CLI:
    python model_registry.py list
    python model_registry.py show <version>
    python model_registry.py promote <version>
    python model_registry.py archive <version> [--from candidate|active]
    python model_registry.py rollback <version>
"""

from __future__ import annotations

import argparse
import json
import os
import platform
import re
import shutil
import sys
from dataclasses import asdict, dataclass, field

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import schema as schema_module


# DSS_MODELS_ROOT redirects the whole registry, which is what lets a test
# (or a dry run) exercise promotion and rollback in a subprocess without
# touching the real analytics/models/ tree. Production sets nothing and gets
# the default.
MODELS_ROOT = os.environ.get('DSS_MODELS_ROOT') or os.path.join(os.path.dirname(os.path.abspath(__file__)), 'models')
STATUSES = ('active', 'candidate', 'archived')


def runtime_versions() -> dict:
    """
    The exact interpreter/library versions a model was fitted under, read
    at runtime — never hard-coded. classify.py compares these against the
    versions it is running to warn about an unpickling mismatch instead of
    letting a silently-degraded model serve predictions.
    """
    versions = {'python': platform.python_version()}
    for name, module_name in (('numpy', 'numpy'), ('scikit-learn', 'sklearn'), ('joblib', 'joblib')):
        try:
            module = __import__(module_name)
            versions[name] = getattr(module, '__version__', 'unknown')
        except Exception:
            versions[name] = 'not installed'
    return versions


@dataclass
class ModelMetadata:
    """
    Everything needed to understand, reproduce, audit and safely serve a
    model — recorded at training time, because none of it can be recovered
    from a .pkl afterwards.

    NO STUDENT IDENTIFIER MAY APPEAR HERE. Counts, class distributions and
    school years are aggregate; an anonymous_student_id is still a
    per-learner identifier and does not belong in a model artifact either.
    """

    # --- identity -----------------------------------------------------
    version: str
    algorithm: str
    trained_at: str

    # --- contract -----------------------------------------------------
    feature_names: list                    # EXACT fitted order — positional, load-bearing
    target_definition: str
    feature_schema_version: str = schema_module.FEATURE_SCHEMA_VERSION
    target_classes: list = field(default_factory=lambda: list(schema_module.VALID_TARGET_VALUES))
    prediction_domain: str = 'binary_outcome'  # or 'risk_level' for the legacy 3-level prototype

    # --- dataset ------------------------------------------------------
    dataset_type: str = 'synthetic'        # one of schema.DATASET_TYPES
    dataset_label: str = ''
    dataset_hash: str = ''                 # sha256 of the source CSV bytes
    training_record_count: int = 0
    test_record_count: int = 0
    class_distribution: dict = field(default_factory=dict)          # whole validated dataset
    train_class_distribution: dict = field(default_factory=dict)
    test_class_distribution: dict = field(default_factory=dict)
    school_years_represented: list = field(default_factory=list)
    reporting_systems_represented: list = field(default_factory=list)

    # --- methodology --------------------------------------------------
    hyperparameters: dict = field(default_factory=dict)
    split_methodology: dict = field(default_factory=dict)
    missing_value_strategy: str = schema_module.MISSING_VALUE_STRATEGY
    missing_value_policy: str = schema_module.MISSING_VALUE_POLICY

    # --- evaluation ---------------------------------------------------
    accuracy: float = 0.0
    precision: dict = field(default_factory=dict)  # per-class
    recall: dict = field(default_factory=dict)     # per-class
    f1: dict = field(default_factory=dict)         # per-class
    confusion_matrix: list = field(default_factory=list)
    feature_importances: dict = field(default_factory=dict)
    baseline_comparison: dict = field(default_factory=dict)

    # --- environment / governance -------------------------------------
    runtime_versions: dict = field(default_factory=runtime_versions)
    validation_status: str = 'not_reviewed'
    status: str = 'candidate'
    limitations: list = field(default_factory=list)
    notes: str = ''

    def to_dict(self) -> dict:
        return asdict(self)

    # An identifier VALUE that a careless dataset label or note could carry
    # into an artifact that gets copied, emailed and attached to a paper.
    # Matching shapes rather than column names is deliberate: scanning prose
    # for the word "name" would fire on "the file name", which teaches
    # people to work around the check instead of reading it.
    _IDENTIFIER_VALUE_PATTERNS = (
        (re.compile(r'\b\d{12}\b'), 'what looks like a 12-digit LRN'),
        (re.compile(r'\b[\w.+-]+@[\w-]+\.[\w.]+\b'), 'what looks like an email address'),
        (re.compile(r'\b(?:\+63|0)9\d{9}\b'), 'what looks like a mobile number'),
    )

    # Fields whose job is to describe METHODOLOGY and contract. They quote
    # column names on purpose — `split_methodology` records that rows were
    # grouped by anonymous_student_id, which is a column's NAME, not a
    # learner's identifier, and is exactly what a reviewer needs to know.
    _METHODOLOGY_FIELDS = ('split_methodology', 'missing_value_policy', 'target_definition')

    def assert_no_identifiers(self) -> None:
        """
        A last line of defence, in two checks that guard two different
        things:

        1. NO IDENTIFIER IS A PREDICTOR. Nothing in `feature_names` (and so
           nothing in `feature_importances`, which is keyed by it, and
           nothing in any other structural key) may be a forbidden column
           or the grouping column. This is the real invariant — a model
           that split on an LRN is broken, not merely indiscreet.
        2. NO IDENTIFIER VALUE RIDES ALONG in the descriptive fields. A
           dataset label like "export for 110000000001" is a leak even
           though no model feature is involved.
        """
        banned = {c.lower() for c in schema_module.FORBIDDEN_COLUMNS}
        banned.add(schema_module.GROUPING_COLUMN.lower())

        structural_names = (
            list(self.feature_names)
            + list(self.feature_importances)
            + list(self.precision) + list(self.recall) + list(self.f1)
            + list(self.class_distribution)
        )
        for name in structural_names:
            if str(name).strip().lower() in banned:
                raise ValueError(
                    f"'{name}' appears in this model's feature/metric keys. "
                    f"An identifier must never be a model feature."
                )

        descriptive = {
            key: value for key, value in self.to_dict().items()
            if key not in self._METHODOLOGY_FIELDS
        }
        blob = json.dumps(descriptive)
        for pattern, description in self._IDENTIFIER_VALUE_PATTERNS:
            match = pattern.search(blob)
            if match:
                raise ValueError(
                    f"model metadata contains {description} ({match.group()!r}). "
                    f"A learner identifier must never be stored in a model artifact."
                )


def _ensure_dirs() -> None:
    for status in STATUSES:
        os.makedirs(os.path.join(MODELS_ROOT, status), exist_ok=True)


def _paths(status: str, version: str) -> tuple:
    base = os.path.join(MODELS_ROOT, status, version)
    return base + '.pkl', base + '.json'


def save_candidate(model, metadata: ModelMetadata) -> str:
    """
    Saves `model` and its metadata under models/candidate/. Never touches
    models/active/ — see promote_to_active() for the only way a model
    becomes active, which is always a separate, explicit call.
    """
    import joblib

    metadata.assert_no_identifiers()

    _ensure_dirs()
    metadata.status = 'candidate'
    pkl_path, json_path = _paths('candidate', metadata.version)

    joblib.dump(model, pkl_path)
    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(metadata.to_dict(), f, indent=2)

    return metadata.version


def list_models(status: str | None = None) -> list:
    """Returns metadata dicts (not the models themselves) for every version under the given status, or all statuses if None."""
    _ensure_dirs()
    statuses = [status] if status else list(STATUSES)
    results = []
    for s in statuses:
        directory = os.path.join(MODELS_ROOT, s)
        if not os.path.isdir(directory):
            continue
        for filename in sorted(os.listdir(directory)):
            if filename.endswith('.json'):
                with open(os.path.join(directory, filename), encoding='utf-8') as f:
                    results.append(json.load(f))
    return results


def get_active_model_metadata() -> dict | None:
    active = list_models('active')
    return active[0] if active else None


def load_active_model():
    """
    Returns (estimator, metadata_dict) for the currently active model, or
    (None, None) when nothing has been promoted.

    Deliberately returns None rather than falling back to anything:
    choosing what to do with an empty registry is classify.py's decision to
    make explicitly, not this module's to make silently.
    """
    import joblib

    metadata = get_active_model_metadata()
    if metadata is None:
        return None, None

    pkl_path, _ = _paths('active', metadata['version'])
    if not os.path.isfile(pkl_path):
        raise FileNotFoundError(
            f"active model metadata '{metadata['version']}.json' exists but its .pkl is missing — the registry is inconsistent"
        )
    return joblib.load(pkl_path), metadata


def promote_to_active(version: str) -> None:
    """
    Moves `version` from candidate/ to active/, after archiving whatever
    is currently active (never deleted — see module docstring). This is the
    ONLY function that changes what is active, and it is never called
    automatically by save_candidate() or train_model.py — a human decides,
    after reading the metrics.
    """
    _ensure_dirs()
    pkl_path, json_path = _paths('candidate', version)
    if not os.path.exists(pkl_path) or not os.path.exists(json_path):
        raise FileNotFoundError(f"'{version}' is not a candidate model — nothing to promote.")

    for meta in list_models('active'):
        archive(meta['version'], from_status='active')

    for src in (pkl_path, json_path):
        shutil.move(src, os.path.join(MODELS_ROOT, 'active', os.path.basename(src)))

    _set_status_in_place('active', version, 'active')


def archive(version: str, from_status: str = 'candidate') -> None:
    """Moves a model from `from_status` to archived/. Never deletes a file."""
    _ensure_dirs()
    pkl_path, json_path = _paths(from_status, version)
    if not os.path.exists(pkl_path):
        raise FileNotFoundError(f"'{version}' not found under '{from_status}'.")

    for src in (pkl_path, json_path):
        if os.path.exists(src):
            shutil.move(src, os.path.join(MODELS_ROOT, 'archived', os.path.basename(src)))

    _set_status_in_place('archived', version, 'archived')


def rollback_to(version: str) -> None:
    """
    Restores an ARCHIVED model to active, archiving whatever is active
    now. The mechanism a bad promotion is undone with: because
    promote_to_active() archives rather than deletes, the previous active
    model is always still on disk to come back to.

    Symmetrical with promote_to_active() on purpose — rolling back is an
    ordinary, supported operation, not a recovery procedure someone has to
    improvise under pressure.
    """
    _ensure_dirs()
    pkl_path, json_path = _paths('archived', version)
    if not os.path.exists(pkl_path) or not os.path.exists(json_path):
        raise FileNotFoundError(f"'{version}' is not an archived model — nothing to roll back to.")

    for meta in list_models('active'):
        if meta['version'] == version:
            return  # already active; rolling back to it is a no-op, not an error
        archive(meta['version'], from_status='active')

    for src in (pkl_path, json_path):
        shutil.move(src, os.path.join(MODELS_ROOT, 'active', os.path.basename(src)))

    _set_status_in_place('active', version, 'active')


def _set_status_in_place(status: str, version: str, new_status: str) -> None:
    _, json_path = _paths(status, version)
    if not os.path.exists(json_path):
        return
    with open(json_path, encoding='utf-8') as f:
        data = json.load(f)
    data['status'] = new_status
    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)


def _find(version: str) -> dict | None:
    for meta in list_models():
        if meta['version'] == version:
            return meta
    return None


def _print_summary(meta: dict) -> None:
    print(f"{meta['status']:>9}  {meta['version']}  "
          f"{meta.get('dataset_type', '?')}  "
          f"accuracy={meta.get('accuracy', '?')}%  "
          f"schema={meta.get('feature_schema_version', '?')}  "
          f"validation={meta.get('validation_status', '?')}")


def _use_utf8_stdout() -> None:
    """
    Windows consoles default to a legacy code page, which mangles the
    em-dashes and arrows in this module's explanatory output. Reconfigure
    rather than strip the punctuation: the report is meant to be read.
    """
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding='utf-8', errors='replace')
        except (AttributeError, OSError):
            pass


def main() -> None:
    _use_utf8_stdout()
    parser = argparse.ArgumentParser(description='Inspect and manage trained risk models. Promotion and rollback are always explicit.')
    sub = parser.add_subparsers(dest='command', required=True)

    p_list = sub.add_parser('list', help='List every known model version.')
    p_list.add_argument('--status', choices=STATUSES, default=None)

    p_show = sub.add_parser('show', help='Print one version\'s full metadata.')
    p_show.add_argument('version')

    p_promote = sub.add_parser('promote', help='Promote a CANDIDATE to active (archives the current active).')
    p_promote.add_argument('version')

    p_archive = sub.add_parser('archive', help='Archive a model. Never deletes.')
    p_archive.add_argument('version')
    p_archive.add_argument('--from', dest='from_status', choices=('candidate', 'active'), default='candidate')

    p_rollback = sub.add_parser('rollback', help='Restore an ARCHIVED model to active.')
    p_rollback.add_argument('version')

    args = parser.parse_args()

    if args.command == 'list':
        models = list_models(args.status)
        if not models:
            print('No models in the registry.')
            return
        for meta in models:
            _print_summary(meta)
        return

    if args.command == 'show':
        meta = _find(args.version)
        if meta is None:
            print(f"No model named '{args.version}'.", file=sys.stderr)
            raise SystemExit(1)
        print(json.dumps(meta, indent=2))
        return

    if args.command == 'promote':
        meta = _find(args.version)
        if meta is not None and meta.get('dataset_type') == 'synthetic':
            print('REFUSING: this candidate was trained on SYNTHETIC data. A synthetic model must never be '
                  'promoted to active — its metrics describe fabricated rows, not learners.', file=sys.stderr)
            raise SystemExit(2)
        promote_to_active(args.version)
        print(f"Promoted {args.version} to active. The previous active model was archived, not deleted.")
        return

    if args.command == 'archive':
        archive(args.version, from_status=args.from_status)
        print(f"Archived {args.version} (from {args.from_status}).")
        return

    if args.command == 'rollback':
        rollback_to(args.version)
        print(f"Rolled back: {args.version} is now active.")
        return


if __name__ == '__main__':
    main()
