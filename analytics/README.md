# Analytics — the ML layer of the Naggasican NHS DSS

> **The model currently serving predictions is a LEGACY SYNTHETIC PROTOTYPE.**
> It was trained on 90 hand-typed numbers, not on learners. Its accuracy
> figures are not real-world accuracy. Nothing in this directory has ever
> been trained or validated against real student outcomes, because no
> authorized historical dataset exists yet.
>
> The architecture here is *ready* for that dataset. It has not been used on
> one.

---

## 1. What this module is for

One job: given a learner's academic evidence for one reporting period,
produce a **risk signal that informs a Principal's judgment**. It never
computes a grade, never applies a grading policy, never decides a DepEd
remark, and never creates or approves an intervention.

```
academic data
    -> GradingEngine (official grade, per DepEd policy)   [PHP, not ML]
    -> RiskFeatureExtractor (normalized academic features) [PHP]
    -> classify.py (the ACTIVE model)                      [Python, ML]
    -> ML PREDICTION
    -> rule-based academic checks                          [PHP, not ML]
    -> FINAL DSS RESULT (RiskResult)
    -> Principal review                                    [a person]
```

## 2. ML vs. rule-based logic — two layers, deliberately separate

| | Academic rules | ML |
|---|---|---|
| Owns | Grading policy resolution, WW/PT/Exam weights, transmutation, official remarks, the failing-subject safety net | Risk prediction only |
| Lives in | `GradingEngine`, `SubjectGroupWeight`, `TransmutationService`, `Adviser\ReportController::applyFailingSubjectOverride()` | `analytics/`, `RiskFeatureExtractor` |
| Changes via | A DepEd grading order | A validated retrain and an explicit promotion |

The ML layer receives **already-computed** academic outcomes (means, counts,
deltas) — never a weight, never a `subject_group`, never a transmutation
band. `tests/Feature/MlArchitectureBoundaryTest.php` asserts none of those
reach the payload.

The rule layer runs **after** the model and only ever escalates severity. All
three figures are stored separately and permanently:

| Column | Meaning |
|---|---|
| `ml_risk_level` | What the model said, untouched. |
| `risk_level` | The final DSS result after the rule layer. |
| `was_overridden` | Whether the rule layer changed the answer. |

Collapsing these into one figure would make "what did the model actually
say" unrecoverable. Don't.

## 3. Current status: a legacy synthetic prototype

| | |
|---|---|
| Artifact | `analytics/model_cache.pkl` (tracked in Git — production needs it) |
| Descriptor | `analytics/legacy/legacy_model.json` |
| How it was built | `analytics/legacy/prototype_model.py` |
| Features | one: `average_grade` |
| Classes | `low` / `moderate` / `high` |
| Trained on | 90 hand-typed values |
| Validated against real outcomes | **never** |

It is retained on purpose. Deleting it would take the DSS down for every
adviser and Principal using it today, and there is nothing to replace it
with yet. It is labelled honestly in its descriptor, in every prediction it
returns (`"dataset_type": "synthetic"`), and in this file.

### Why the previous prototype could be described as hardcoded

This is a factual description, not a criticism. The prototype did the job it
was built for.

Read `analytics/legacy/prototype_model.py::_synthetic_training_set()`. Every
training sample is a single number a human typed, and its class came from the
band the human typed it into:

- `average_grade >= 85` → `low`
- `75 – 84.9` → `moderate`
- below `75` → `high`

A RandomForest was then fitted to those labels. Four consequences follow, and
all four are verifiable rather than asserted:

1. **The training data was generated from predetermined grade ranges.** There
   is no data source in that function — only literals.
2. **The target labels were predetermined.** The label was a function of the
   band, decided before fitting; the model was not discovering a relationship,
   it was being handed one.
3. **`average_grade` was the only ML feature.** The fitted model reports
   `n_features_in_ = 1` and `feature_importances_ = [1.0]`.
4. **Therefore the classifier largely reproduces manually defined
   boundaries.** Its cross-validation accuracy is the expected arithmetic
   result of testing a one-feature model on perfectly separable, hand-placed
   points. A single `if grade >= 85` statement scores identically.

**What it was good for:** it gave the Laravel↔Python contract, the
`RiskResult` write path, the rule-based override and the Principal's screens
something real and deterministic to run against, years before any outcome
data existed. That is a legitimate and useful thing for a prototype to be.

**What it is not:** evidence of real-world predictive validity. Synthetic
accuracy is never real-world accuracy, production accuracy, or validated
school accuracy, and this codebase does not describe it as any of those.

## 4. Files

| File | Role |
|---|---|
| `schema.py` | **Single source of truth** for feature names, feature ORDER, the target, ranges and the missing-data policy. Run it (`python analytics/schema.py`) to print the contract. |
| `classify.py` | **Production inference only.** Loads the active model, validates the payload, predicts. Never trains. |
| `train_model.py` | **The authoritative training entry point.** Validates, splits, fits a candidate, evaluates, compares to a baseline, saves. Never promotes. |
| `dataset_validator.py` | Rejects an invalid dataset before any fitting. |
| `baseline.py` | The transparent rule baseline a candidate is measured against. |
| `model_registry.py` | Candidate / active / archived, promotion and rollback. |
| `legacy/prototype_model.py` | How the deployed prototype was built. Audit/reproducibility only. |
| `legacy/legacy_model.json` | Descriptor for `model_cache.pkl`. |
| `tests/fixtures/` | Clearly-labelled **synthetic** fixtures for pipeline testing. |
| `model_accuracy.txt` | **Generated report** on the legacy prototype. Git-ignored. No runtime code reads it. |

## 5. The canonical features

Nine, in this exact order — the order is positional and load-bearing:

| # | Feature | Blank allowed? |
|---|---|---|
| 1 | `ww_mean` | yes |
| 2 | `pt_mean` | yes |
| 3 | `exam_mean` | yes |
| 4 | `current_average` | **no** |
| 5 | `prev_period_average` | yes |
| 6 | `trend_delta` | yes |
| 7 | `failing_subject_count` | **no** |
| 8 | `weak_component_count` | **no** |
| 9 | `missing_assessment_count` | yes |

Full written definitions live in `schema.FEATURE_DEFINITIONS` — read them
there, not here, so there is only one copy. Two worth restating:

- **`weak_component_count` has exactly one definition:** the count of
  *available* components (of WW / PT / Exam) whose pooled percentage is
  strictly below 75. A blank component is excluded from the count entirely —
  neither weak nor strong — so a learner on a profile with no Examination can
  never be marked weak for a component they do not have.
- **`exam_mean` is BLANK, never 0, for a profile with no Examination
  component.** DO 015 Work Immersion, Research and Design and Innovation have
  no examination at all (`ex_weight` null). Laravel additionally sends
  `exam_component_applicable` as context so "no such component" and "not
  recorded yet" stay distinguishable.

Not features, and never will be: `student_id`, `anonymous_student_id`, and
everything in `schema.FORBIDDEN_COLUMNS`.

## 6. The target

```
outcome ∈ { no_intervention, intervention }
```

**It must be supplied by the school.** It is a verified historical fact —
whether that learner-period actually received a documented academic
intervention — and this pipeline will not compute, infer or guess it.

**It is never derived from `current_average` or any other feature.** Doing so
would recreate the prototype's defining flaw under a new name: the model would
learn the rule it was handed and report a high score for it. `dataset_validator.py`
rejects a dataset whose `outcome` column is missing, blank, or holds anything
other than the two valid values.

If the school later defines a different legitimate target, that is an explicit
`FEATURE_SCHEMA_VERSION` bump with a new `TARGET_DEFINITION` — never a quiet
change of meaning behind the same column name.

## 7. Required dataset format

One row = **one learner-period record**. See `TRAINING_DATA_CONTRACT.md` for
the full table and `analytics/tests/fixtures/synthetic_pipeline_fixture.csv`
for a readable (synthetic) example.

Context columns: `anonymous_student_id`, `school_year`, `grade_level`,
`curriculum`, `grading_policy`, `reporting_system`, `period_index`.
Then the nine features. Then `outcome`.

**Quarterly and three-term data are both supported, natively.**
`reporting_system` is `quarterly` or `three_term`, and `period_index` is
validated *against it* — 1–4 for quarterly, 1–3 for three-term. Q1–Q4 is
never force-mapped onto Term 1–3, and Q4 is never discarded; that would throw
away a quarter of every historical learner-year and pretend two different
reporting structures are the same shape.

Subjects can also differ between periods (see CLAUDE.md, term-specific subject
offerings). Period averages are comparable as period-level indicators, but a
different subject in a later period is not the same subject — anything that
wants same-subject comparison must use the same-subject signals, not assume
the mix held.

## 8. Privacy

`dataset_validator.py` **rejects the entire dataset** — it does not silently
strip columns — if any of these appear: `name`, `student_name`, `full_name`,
`first_name`, `last_name`, `middle_name`, `address`, `phone`, `mobile`,
`email`, `contact_number`, `parent_name`, `guardian_name`, `guardian_contact`,
`parent_contact`, `birthdate`, `birth_date`, `date_of_birth`, `lrn`.

Rejecting rather than stripping is deliberate: a file that still carries a
name column is a privacy problem to fix at source, not one to quietly work
around. (Birthdate is on that list twice over — it was removed from the
learner record entirely and is not an ML feature.)

`anonymous_student_id` is **required** (for de-duplication and for keeping one
learner on one side of a train/test split) and is **never a feature**.
`ModelMetadata.assert_no_identifiers()` refuses to save a model whose feature
or metric keys name an identifier, or whose descriptive fields carry something
shaped like an LRN, an email address or a mobile number.

## 9. Missing data

A blank means **"not applicable, or not recorded"** and is carried to the
model as `NaN`, handled natively by scikit-learn's tree splitter (≥1.4).

**Blanks are never imputed to 0, to a mean, or to anything else.** Zero is a
real and much worse academic statement:

| Blank | ≠ | Zero |
|---|---|---|
| grading profile has no Examination component | | scored 0 in the exam |
| no previous reporting period exists | | previously averaged 0 |
| no assessment record was entered | | the adviser entered a 0 |

That last row is why `missing_assessment_count` counts *absent rows*:
`assessment_scores.score` is `NOT NULL` and unique per (assessment, student),
so "a zero was entered" is a row that exists and "nothing was recorded" is a
row that does not.

The strategy is recorded in every model's metadata as
`missing_value_strategy: native_nan`.

## 10. Validation

```bash
python analytics/dataset_validator.py path/to/dataset.csv --label "Historical SHS 2023-2026"
```

Reports only — never trains, never modifies the file. Exit code 0 if
trainable, 1 if not.

**Blocking** (no model will be trained): missing required columns, prohibited
identifier columns, no valid records, fewer than two target classes, a class
with fewer than 10 records, a required feature blank in every row.

**Per-row rejections** (that row is dropped, the rest continue): unsupported
`reporting_system`, malformed `school_year`, `grade_level` not 11/12, a
`period_index` invalid for its reporting system, a non-numeric feature, an
out-of-range value, a blank required feature, a blank/invalid `outcome`, a
blank `anonymous_student_id`, a duplicate learner-period.

**Warnings** (reported, never auto-fixed — a human decides): severe class
imbalance, an optional feature blank in every row, a single feature that alone
perfectly separates the classes (possible leakage).

Nothing is coerced. A row that does not describe a valid learner-period is
reported with its row number and reason, not repaired into one.

## 11. Training a candidate

```bash
python analytics/train_model.py path/to/dataset.csv \
    --dataset-type real_historical \
    --label "Historical SHS 2023-2026"
```

`--dataset-type` is **required** (`synthetic` / `real_historical` / `mixed`).
There is no default: whether a dataset is real is the single most important
thing about any metric derived from it, so it must be stated.

**Split strategy**, chosen automatically and always recorded in metadata:

1. **`cohort_time_aware`** — train on older school years, test on the newest.
   Preferred, because it is the closest available analogue of predicting a
   future cohort. Needs ≥2 school years with both classes on each side.
2. **`grouped_random`** — one school year, but learners contribute several
   period rows. Split *by learner* so nobody appears on both sides; a
   row-level split would measure memorisation of a learner rather than
   generalisation to a new one.
3. **`stratified_random`** — one year, one row per learner. Nothing else to
   hold out.

`random_state` is fixed at 42 for reproducibility.

**Hyperparameters** (`n_estimators=200, max_depth=5, min_samples_split=2,
min_samples_leaf=1`) are ML settings, not academic rules, and are **not
claimed to be optimal** — no model-selection procedure has been run, because
there is no real dataset to run one against. They are chosen for
reproducibility and explainability. Tuning them later is a separate documented
exercise, and its result must be recorded as a tuned result rather than
presented as these defaults having been right all along.

## 12. Evaluating it

Every candidate reports, on the held-out set only:

accuracy · per-class precision · per-class recall · per-class F1 ·
confusion matrix · class distribution (overall, train, test) · train and test
counts · feature importances from the fitted model · a baseline comparison.

**Read recall and precision on `intervention` together.** Low recall means
learners who needed attention were missed — the costly error for a DSS. Low
precision means staff time spent on learners who did not need it, which is
exactly what optimising recall alone buys. Nothing here maximises one on its
own, and **no acceptance threshold is encoded**: the school has approved no
deployment criterion, so inventing one would put a number in front of a
decision that is theirs to make.

**The baseline comparison is the real test.** `baseline.py` scores the
school's existing academic rule (a failing subject, or an average below 75, or
two weak components → flag) on the *same* held-out records, with no fitting.
The question is not "is accuracy high" — an imbalanced cohort hands high
accuracy to a rule that flags nobody — but "does the model add value over what
the school already knows for free". **If it does not beat the baseline, the
honest conclusion is that ML adds no value here yet**, not that the dataset
needs adjusting until it does.

Feature importance comes from the fitted model, never hand-written. It
describes what the forest split on; it is **not evidence of causation**, and a
low-scoring feature is not proven irrelevant to a learner.

## 13. Inspecting and promoting

```bash
python analytics/model_registry.py list
python analytics/model_registry.py show <version>
python analytics/model_registry.py promote <version>    # explicit, human-run
```

Training produces a **candidate**. It never activates anything. Promotion is a
separate command a person runs after reading the metrics — never automatic,
and never triggered by accuracy alone.

`promote` **refuses a candidate tagged `synthetic`**, by design.

Promotion archives the outgoing active model rather than deleting it, which is
what makes rollback always available.

## 14. Rolling back

```bash
python analytics/model_registry.py rollback <archived-version>
python analytics/model_registry.py archive <version> --from active
```

Rollback restores an archived model to active and archives the current one.
Nothing in this module ever deletes a model file.

## 15. How Laravel calls inference

`Adviser\ReportController::runAnalytics()` runs:

```
<python_path> analytics/classify.py <input.json> <output.json>
```

1. Laravel builds the payload (`buildPythonPayload()` — the nine canonical
   features plus `student_id` and context).
2. Python validates the required feature names are present and numeric.
3. Python orders features **from the loaded model's own metadata**, never
   from JSON key order.
4. Python loads the active model — registry first, legacy prototype second,
   and a **controlled error** if neither exists.
5. Python predicts.
6. Python returns structured JSON:

```json
{
  "student_id": 12,
  "average_grade": 88.0,
  "risk_level": "low",
  "prediction": "low",
  "confidence": 96.5,
  "model_version": "legacy_synthetic_prototype",
  "feature_schema_version": "0.0.0-legacy",
  "dataset_type": "synthetic"
}
```

`student_id` is a **correlation identifier only**. It never enters the feature
matrix.

On failure the output file receives `{"error": {"code": ..., "message": ...}}`
and the process exits non-zero. **A Python traceback is never written to a file
Laravel reads.** Laravel's `classifierOutputIsValid()` then refuses the whole
result set rather than persisting part of it, and the adviser sees the existing
"risk analysis failed to generate" warning.

## 16. Version compatibility

Pinned in `requirements.txt` from the actual tested environment:

```
Python 3.14.6 · numpy 2.4.6 · scikit-learn 1.9.0 · joblib 1.5.3
```

At load, `classify.py` compares the versions a model was fitted under against
the versions unpickling it, and prints a warning to stderr on a difference —
flagged `MAJOR` when the major version differs, which is the case where a
silently-degraded unpickle is plausible. It warns rather than refuses: a minor
drift is usually harmless and taking the DSS down for it would be worse.

`classify.py` also **refuses** a model whose declared `feature_schema_version`
has a different major version than `schema.FEATURE_SCHEMA_VERSION`. Serving a
model across an incompatible schema change would feed features into the wrong
columns. The legacy prototype declares `0.0.0-legacy` and is explicitly exempt:
it predates the contract and reads one always-present column.

## 17. Known limitations

1. **No real model exists.** Everything here is ready for a dataset that has
   not arrived.
2. **The deployed prototype is synthetic and single-feature** — see §3.
   Because it only sees an average, it cannot see a failing subject hidden
   behind a high average; the PHP failing-subject override exists precisely to
   cover that blind spot.
3. **The candidate target is binary (`intervention`/`no_intervention`), while
   the DSS displays three levels (`low`/`moderate`/`high`).** These are
   different questions, and **`classify.py` refuses to serve a binary-target
   model through the risk-level contract** rather than inventing a mapping.
   Mapping `intervention → high` would fabricate a severity the model never
   predicted and erase `moderate` entirely. Resolving this is a deliberate
   design decision to take with the school — an explicit compatibility layer,
   or a change to what the UI shows — not a mapping added to make a test pass.
   **Until it is resolved, a real candidate cannot go to production**, even
   after it trains and evaluates well.
4. **The prototype's three risk levels are calibrated thresholds, not a
   learned signal** (see CLAUDE.md, "Known limitations").
5. **Attendance is not collected anywhere** in this system and is not a
   feature. `attendance_absences` is named in the schema only so a future
   authorized dataset can add it deliberately.
6. **No deployment acceptance threshold has been approved.** Model acceptance
   requires human review.
7. **Raw historical workbook parsing is not built.** A dataset must already be
   normalized to the CSV contract before `train_model.py` can use it.

## 18. What must happen before a real model can be trained

1. The school supplies **real historical academic records**, anonymized, in
   the CSV contract above.
2. The school supplies a **documented, verified outcome definition** and the
   per-learner-period outcomes themselves. *This is the hard blocker.* Without
   a defensible answer to "what actually happened to this learner", no amount
   of grade data produces a trainable dataset.
3. `dataset_validator.py` passes on it.
4. `train_model.py` produces a candidate; its metrics are reviewed against the
   baseline by a person.
5. Limitation 3 above (binary target vs. three-level UI) is resolved
   explicitly.
6. Someone runs `model_registry.py promote` deliberately.

Until all six, the legacy synthetic prototype stays active — and stays
labelled as exactly what it is.
