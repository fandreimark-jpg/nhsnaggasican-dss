# ML Architecture — Naggasican NHS DSS

**The current risk model is a prototype trained using synthetic data and
must not be represented as a validated production learner-risk model.**
See `analytics/model_accuracy.txt`, regenerated every time the model trains,
which carries this same caveat directly attached to its metrics — not as a
separate document a reader might not see.

This document explains the architecture around that model: why it's built
the way it is, what would be required to replace it with something trained
on real data, and the boundaries that must not be crossed while doing so.

## 1. Academic grading rules vs. ML — two different systems, deliberately

| | Academic rules | ML |
|---|---|---|
| Owns | Curriculum/grading-policy resolution, WW/PT/Exam weights, transmutation, official remarks | Risk-level prediction only |
| Lives in | `GradingEngine`, `SubjectGroupWeight`, `TransmutationService` | `analytics/classify.py`, `RiskFeatureExtractor` |
| Produces | The official grade | A probability/prediction to inform a Principal's judgment |
| Changes | Only via a DepEd-sourced grading order (DO 8, DO 015, ...) | Only via a validated retrain |

The ML layer receives **already-computed** academic features (means,
counts, deltas) — never a WW/PT/Exam weight, never a subject's
`subject_group`, never a transmutation table. `analytics/classify.py`
contains no grading percentages or school-policy thresholds; this is
verified directly (`grep` finds no `ww_weight`/`pt_weight`/`ex_weight`/
`subject_group`/`core_academic` anywhere in that file) and is a boundary,
not an accident — do not add any of those to `classify.py` in a future
change.

The reverse direction is also a strict boundary: **ML never determines an
official grade, never applies a grading policy, and never decides a DepEd
grading remark.** See section 10 for the full production data flow.

## 2. Q1-Q4 vs. Term 1-Term 3 — normalized, never force-mapped

See `TRAINING_DATA_CONTRACT.md` in full. Summary: `reporting_system`
(`quarterly` / `three_term`) and `period_index` (1-4 or 1-3, validated
against each other) represent each historical row honestly, instead of
assuming Q1=Term 1, Q2=Term 2, Q3=Term 3, and silently dropping Q4.

## 3. Why periods are normalized rather than directly equated

A quarter and a term are not the same unit of academic evidence — different
duration, different number of periods per year, no guaranteed 1:1
correspondence. Normalizing (rather than force-mapping) means:

- No historical record is silently discarded (the Q4 problem).
- A future feature-engineering decision (e.g. "first-half average") is
  built deliberately on top of honest data, not baked into ingestion as an
  unexamined assumption.
- A dataset mixing both systems (a school that changed systems mid-history,
  which is exactly this school's situation) can be validated and reasoned
  about as what it actually is, not smoothed over.

## 4. Required historical client datasets

Not yet obtained. Required, per the contract in `TRAINING_DATA_CONTRACT.md`:

- Real historical student academic records — quarterly and/or three-term —
  with the identity columns anonymized (never a real LRN/name/address as a
  feature).
- A **school-approved, documented historical outcome/intervention
  definition** for the `outcome` target column. This is the hard blocker:
  without a real, defensible answer to "what actually happened to this
  student" (a genuine past intervention record, a documented at-risk
  determination made by the school at the time — not an outcome invented
  after the fact by this codebase), no real model can be trained, no
  matter how much grade data exists.

## 5. Required outcome/target — must not be invented

`analytics/dataset_validator.py` and `analytics/train_model.py` both refuse
to train against a dataset with no valid `outcome` column (one of
`intervention` / `no_intervention` per row, from a documented historical
record). This is enforced in code, not only in this document —
`ValidationSummary.is_trainable()` returns `False` whenever fewer than two
target classes are present, and `train_model.train_candidate()` calls
`sys.exit(1)` rather than proceeding.

## 6. Privacy / anonymization requirements

See `TRAINING_DATA_CONTRACT.md`'s "Privacy" and "Forbidden columns"
sections. `dataset_validator.py` rejects a dataset outright — not
silently strips it — if it contains any identifying column (`name`,
`address`, `phone`, `email`, `lrn`, etc.). `anonymous_student_id` is
explicitly not the real LRN.

## 7. Training workflow

```
UPLOAD -> DETECT FORMAT -> PARSE -> VALIDATE -> PREVIEW -> NORMALIZE
    -> BUILD DATASET -> TRAIN CANDIDATE -> EVALUATE -> ACTIVATE
```

- **DETECT FORMAT**: `App\Services\HistoricalEcrFormatDetector` (PHP) —
  structural detection (sheet names, marker cells), never by filename.
  Currently recognises the two real, already-supported workbook shapes
  (Strengthened SHS ECR, Grade 12 Class Record); `quarterly_ecr` and
  `three_term_ecr` are named, anticipated format identifiers with **no
  detector built yet** — no real Q1-Q4 workbook has been provided to build
  a structural signature from. An unrecognised file returns
  `unsupported_historical_ecr_format` and nothing is imported.
- **PARSE / NORMALIZE / BUILD DATASET**: not yet built for a raw historical
  workbook — out of scope until a real Q1-Q4 sample exists. Today, a
  dataset must already be normalized into the CSV shape
  `TRAINING_DATA_CONTRACT.md` describes before `train_model.py` can use it.
- **VALIDATE**: `analytics/dataset_validator.py`'s `validate_dataset()`.
- **PREVIEW**: `ValidationSummary.report()` — the human-readable summary,
  meant to be shown before any training starts.
- **TRAIN CANDIDATE**: `analytics/train_model.py`'s `train_candidate()` —
  never runs if `validate_dataset()` says the dataset isn't trainable.
- **EVALUATE**: metrics computed against a held-out test set — a **cohort
  split** (train on older school years, test on the newest represented
  year) when at least two school years are present, since that is a
  genuinely stronger test of "generalizes to an unseen year" than a blind
  random split; falls back to a stratified random split otherwise.
- **ACTIVATE**: never automatic. See section 9.

Training does not start automatically after upload — every step above is a
separate, inspectable call.

## 8. Evaluation metrics

Accuracy, per-class precision/recall/F1, and the confusion matrix — computed
on the held-out test set only (never on training data), stored in the
candidate model's metadata (`analytics/model_registry.py`'s
`ModelMetadata`).

## 9. Model activation / versioning

`analytics/model_registry.py` — three states, on disk under
`analytics/models/` (a directory entirely separate from
`analytics/model_cache.pkl`, which remains what `classify.py`'s
`get_model()` reads today):

- **Candidate** — a newly trained model + metadata, saved by
  `train_model.py`. Never active on save.
- **Active** — the one a future production integration would load. Moved
  there **only** by an explicit, manual call to
  `model_registry.promote_to_active(version)` — never by `train_model.py`
  itself, and never as a side effect of training or saving.
- **Archived** — a superseded model, kept (never deleted) when a new one is
  promoted to active.

Model metadata recorded: version, algorithm, `trained_at`, training record
count, school years represented, reporting systems represented, feature
list, target definition, accuracy, per-class precision/recall/F1, confusion
matrix, file status. Python hyperparameters are not exposed as an Admin
academic setting anywhere in the UI — this registry is a file-based,
developer/data-scientist-facing mechanism, not an Admin-facing control.

## 10. Current student prediction — the production flow, unchanged by this pass

```
Current ECR / assessments
    -> GradingEngine (official grade, per DepEd policy)
    -> RiskFeatureExtractor (normalized academic features)
    -> classify.py (active model: currently model_cache.pkl)
    -> prediction + probability
    -> RiskResult
    -> Principal/Adviser decision support
```

The ML result never automatically fails a learner, changes a grade, creates
an official academic remark, or imposes an intervention (see `CLAUDE.md`,
"Interventions have an origin" — every intervention is Principal-created).
It is decision support only.

## 11. Current synthetic model limitation

`analytics/classify.py`'s active model is trained on 90 hand-constructed,
perfectly-separable synthetic samples using a single feature
(`average_grade`). Its "100% cross-validation accuracy" is expected
arithmetic for a single-feature threshold problem with clean synthetic
boundaries — not evidence of real-world predictive validity. It has never
been trained or validated against real student outcomes. See
`analytics/classify.py`'s own top-of-file comment and
`analytics/model_accuracy.txt` (regenerated on every train, carries the
same caveat attached directly to its numbers) for the full detail.

`analytics/train_model.py` and `analytics/model_registry.py` (this pass)
exist so that once a real, school-approved historical dataset arrives,
producing and evaluating a real candidate model is a validated pipeline
run — not a rebuild — and it still requires a human to explicitly promote
that candidate to active after reviewing its real, held-out metrics.

**This pass did not train or activate any new production model. No
existing model, prediction, or student/grade data was modified.**
