"""
"ML architecture preparation" pass, Phase 8 — model versioning so a newly
trained model never silently replaces the model currently in production
use.

Entirely separate from `classify.py`'s `MODEL_PATH`
(`analytics/model_cache.pkl`) and its `get_model()` — that file/function
are UNCHANGED by this module and remain exactly what
`Adviser\\ReportController::runAnalytics()` calls today. This registry is
where a FUTURE model trained by `train_model.py` lives while it is being
evaluated, and only moves into being "the" model an admin/principal-facing
prediction reads from via an explicit, manual promotion — never
automatically after training.

Directory layout (created on first use, under analytics/models/):

    models/
      active/     -- at most one model; if present, this is the one a
                     future production integration would load (NOT
                     classify.py's get_model(), which still reads
                     model_cache.pkl directly — wiring that switch-over is
                     a deliberate future step, not implied by this file
                     existing)
      candidate/  -- newly trained, evaluated, awaiting a human decision
      archived/   -- superseded models, kept for audit/rollback, never
                     deleted by this module

Each model version is a pair of files sharing a version id:
    <version>.pkl   -- the joblib-dumped model
    <version>.json  -- its metadata (see ModelMetadata below)

Nothing in this module trains anything or reads real student data.
"""

from __future__ import annotations

import json
import os
import shutil
from dataclasses import asdict, dataclass, field
from datetime import datetime


MODELS_ROOT = os.path.join(os.path.dirname(__file__), 'models')
STATUSES = ('active', 'candidate', 'archived')


@dataclass
class ModelMetadata:
    version: str
    algorithm: str
    trained_at: str
    training_record_count: int
    school_years_represented: list
    reporting_systems_represented: list
    feature_list: list
    target_definition: str
    accuracy: float
    precision: dict  # per-class
    recall: dict  # per-class
    f1: dict  # per-class
    confusion_matrix: list
    status: str = 'candidate'
    notes: str = ''

    def to_dict(self) -> dict:
        return asdict(self)


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

    _ensure_dirs()
    metadata.status = 'candidate'
    pkl_path, json_path = _paths('candidate', metadata.version)

    joblib.dump(model, pkl_path)
    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(metadata.to_dict(), f, indent=2)

    return metadata.version


def list_models(status: str | None = None) -> list[dict]:
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


def promote_to_active(version: str) -> None:
    """
    Moves `version` from candidate/ to active/, after archiving whatever
    is currently active (never deleted — see class docblock). This is the
    ONLY function that changes what is active, and it is never called
    automatically by save_candidate() or train_model.py — a human decides.

    Raises FileNotFoundError if `version` is not currently a candidate.
    """
    _ensure_dirs()
    pkl_path, json_path = _paths('candidate', version)
    if not os.path.exists(pkl_path) or not os.path.exists(json_path):
        raise FileNotFoundError(f"'{version}' is not a candidate model — nothing to promote.")

    current_active = list_models('active')
    for meta in current_active:
        archive(meta['version'], from_status='active')

    for src, dst_dir in ((pkl_path, 'active'), (json_path, 'active')):
        shutil.move(src, os.path.join(MODELS_ROOT, dst_dir, os.path.basename(src)))

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


def _set_status_in_place(status: str, version: str, new_status: str) -> None:
    _, json_path = _paths(status, version)
    if not os.path.exists(json_path):
        return
    with open(json_path, encoding='utf-8') as f:
        data = json.load(f)
    data['status'] = new_status
    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)
