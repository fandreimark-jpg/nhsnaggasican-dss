# Retired ML risk classifier (SUPERSEDED 2026-09-27)

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 2193-2316 2327-2387; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

---

## The ML module: inference and training are separate, and the deployed model is a labelled prototype

> **SUPERSEDED 2026-09-27 (rule-based cut-over).** The classifier is
> retired: nothing launches Python, Submit Report writes no `risk_results`,
> and no page shows a Risk Level. `risk_results` (84 live rows) is kept as
> LEGACY/ARCHIVED history only — see "Rule-based academic monitoring" at the
> end of this file. Kept below for the reasoning it records.

**"ML architecture correction" pass, 2026-09-19.** `analytics/README.md` is
the operational reference; this is the standing shape and the decisions that
must not be silently reversed.

**`classify.py` is INFERENCE ONLY.** It contains no training data, no
`.fit()`, no cross-validation and no report writer — `analytics/
test_inference_contract.py` greps the file for each of those and fails if one
comes back. It resolves a model in one order: the registry's ACTIVE model,
then the legacy prototype (`model_cache.pkl`), then a CONTROLLED ERROR. It
never trains one. A classifier that quietly rebuilds itself from hand-typed
grade bands whenever its artifact is missing is a silent correctness failure
wearing the costume of resilience.

**`analytics/train_model.py` is the single authoritative training pipeline.**
The duplicate `train_from_real_data()` that lived in `classify.py` is gone —
it spoke a DIFFERENT schema (7 features, `low`/`moderate`/`high`) from the one
`train_model.py`/`schema.py` use (9 features, `intervention`/
`no_intervention`), so "the training contract" had two incompatible answers
depending on which file you opened. Training produces a CANDIDATE and never
activates anything; promotion and rollback are explicit commands a person
runs (`python analytics/model_registry.py promote|rollback <version>`), and
`promote` REFUSES a candidate tagged `dataset_type=synthetic`.

**`analytics/schema.py` is the single source of truth for feature names and
ORDER.** Order is positional and load-bearing: a fitted forest indexes its
splits by column, so reordering the tuple without bumping
`FEATURE_SCHEMA_VERSION` would feed `pt_mean` into the column the model
learned as `ww_mean`. Nothing may hard-code a feature list beside it, and
`schema.build_feature_vector()` is the only supported way to turn a payload
into a row — specifically so nothing ever depends on dict iteration order.
`classify.py` refuses to serve a model whose declared schema major version
differs from the running one.

**BLANK IS NOT ZERO, end to end.** A null feature crosses to Python as JSON
null and becomes NaN, handled natively by scikit-learn's tree splitter
(>=1.4; this project runs 1.9). It is never imputed to 0, a mean, or
anything else. Three distinct cases this protects, all of which a 0 would
corrupt: a grading profile with NO Examination component (DO 015 Work
Immersion/Research/Design and Innovation, `ex_weight` null) is not an exam
scored 0; no previous reporting period is not a previous average of 0; and an
assessment nobody recorded is not a recorded 0. That last one is why
`missing_assessment_count` counts ABSENT ROWS — `assessment_scores.score` is
NOT NULL and unique per (assessment, student), so an entered zero is a row
that exists and an unrecorded assessment is a row that does not.

**The binary target must not be silently mapped onto the three risk levels.**
The candidate pipeline predicts `intervention`/`no_intervention`; the DSS
stores and displays `low`/`moderate`/`high`. These are different questions,
and `classify.py` raises `prediction_domain_mismatch` rather than translating.
Mapping `intervention -> high` would fabricate a severity the model never
predicted and erase `moderate` outright. **This is a prerequisite, not a
detail: a real candidate cannot go to production until it is resolved with
the school, however well it trains.**

**The rule layer stays separate and stays after the model.**
`Adviser\ReportController::applyFailingSubjectOverride()` is PHP, is not part
of training, and only ever escalates severity. `ml_risk_level`, `risk_level`
and `was_overridden` remain three distinct stored figures; collapsing them
would make "what did the model actually say" unrecoverable.
`tests/Feature/MlArchitectureBoundaryTest.php` is the test of record, and also
asserts no grading weight, passing threshold or transmutation band ever
reaches the Python payload.

**Why the deployed prototype is retained rather than deleted.** It is what
every adviser and Principal is using today and there is nothing to replace it
with — no authorized historical dataset exists. It is labelled for exactly
what it is in its descriptor (`analytics/legacy/legacy_model.json`), in every
prediction it returns (`"dataset_type": "synthetic"`), and in
`analytics/README.md`. Its generator was MOVED, not deleted, to
`analytics/legacy/prototype_model.py`: keeping the code visible is what lets
"the labels were predetermined" be checked rather than taken on trust, and
that script cannot write `model_cache.pkl` — regenerating the deployed
artifact is a deliberate operator action with an explicit target.

**`analytics/model_accuracy.txt` is a GENERATED report.** No runtime code
reads it; it is git-ignored; regenerate with `python analytics/legacy/
prototype_model.py --report-only`. `model_cache.pkl` is deliberately NOT
ignored — a Git deployment needs it.

## The risk classifier's levels are calibrated thresholds, not a learned signal

> **SUPERSEDED 2026-09-27 (rule-based cut-over).** The classifier is
> retired: nothing launches Python, Submit Report writes no `risk_results`,
> and no page shows a Risk Level. `risk_results` (84 live rows) is kept as
> LEGACY/ARCHIVED history only — see "Rule-based academic monitoring" at the
> end of this file. Kept below for the reasoning it records.

Because the model is trained on a single feature, its risk levels are
effectively thresholds on average grade. Calibration determines the
boundaries between low, moderate, and high, and different cohorts may
require different boundaries. The system does not learn these boundaries
from outcomes.

As of the "correctness and interface pass," the boundaries are Low
85-100, Moderate 75-84.9, High 0-74.9 (`analytics/legacy/
prototype_model.py`'s `BANDS`, moved there from `classify.py` by the
2026-09-19 ML architecture pass) — recalibrated from the original 90/75/60
split, which put
nearly an entire passing cohort in one 15-point Moderate band (observed
live: 39 of 40 learners Moderate, 1 Low, 0 High). The new boundaries are
anchored to figures already used elsewhere in this codebase
(`PerformanceAnalysisService::DEFAULT_TARGET` = 75,
`InTermStatusService::FAILING_THRESHOLD` = 74) rather than percentiles of
any one section's snapshot, so they generalize instead of being tuned to
fit today's roster. `analytics/test_classify.py`'s
`TestRiskLevelDistribution` pins that a cohort with a genuine spread of
averages no longer lands almost entirely in one level.

**This asymmetry is why the component-based In-Term Status must not be
replaced by the Failing rule.** In-Term Status is computed against a flat 75%
target per component regardless of grade level and curriculum, so the Grade 12
learner above still surfaces as At Risk or Needs Attention from the evidence,
even though their reported grade is passing. Removing the component rule in
favour of a grade threshold would leave Grade 12 learners with materially
weaker early detection than Grade 11 learners in the same school year.


## Term-over-Term Progress depends on the order a Principal acted in, permanently

> **SUPERSEDED 2026-09-27 (rule-based cut-over).** The classifier is
> retired: nothing launches Python, Submit Report writes no `risk_results`,
> and no page shows a Risk Level. `risk_results` (84 live rows) is kept as
> LEGACY/ARCHIVED history only — see "Rule-based academic monitoring" at the
> end of this file. Kept below for the reasoning it records.

`Intervention::risk_result_id` is resolved once, at creation
(`Principal\InterventionController::store()`/`storeBulk()`), by looking up a
`RiskResult` for the same student/term/school year at that moment. It is
never re-resolved afterward — no job, no term-submission hook, nothing
revisits it. If no `RiskResult` existed yet when the intervention was
recorded, the column stays null forever, and
`ProgressMonitoringService::compare()` returns `'not_applicable'` for that
intervention permanently, no matter how many term reports get submitted
after the fact.

This is deliberate, not an oversight — the same "captured once, never
recomputed" choice this codebase already made for `focus_component`, and for
the same reason: a value that could silently change out from under a
Principal's original decision is worse than one that stays fixed to what the
Principal actually had in front of them when they acted. Re-resolving
`risk_result_id` at read time was considered and rejected: it would collapse
the distinction `ProgressMonitoringService`'s `'not_applicable'` status was
built to preserve (`Part 1: Term-over-Term Progress says why, not just "not
yet"`) — "recorded from in-term evidence, never had a baseline" versus
"recorded from a term report" — and would make the compared component
silently drift the same way `focus_component` was deliberately frozen to
prevent.

**The consequence a Principal cannot see:** recording an intervention from
in-term evidence *before* submitting that term's report costs term-over-term
comparison for that intervention forever — not "not yet," permanently. The
Interventions page's Term-over-Term Progress column says so plainly once you
look ("Term-over-term comparison applies only to interventions raised from a
submitted term report — this one was raised from in-term evidence"), but
nothing says so at the moment of recording, before the choice is made.

**A record-time warning in the record-intervention modal was considered and
rejected.** The real pilot data settles it: every intervention ever recorded
in this database — 29 of 29 as of the commit that built the honest-status
column, 6 of 6 recorded the night this gap was found — has a null
`risk_result_id`. Recording from in-term evidence, before that term's report
exists, isn't the edge case such a note would be warning about; it is
effectively the only case that happens in practice, since Submit Report is an
end-of-term action and interventions get recorded during the term precisely
because a Principal doesn't want to wait. A note that fires on ~100% of
recordings stops functioning as information — it becomes wallpaper the
Principal stops reading within the first few uses, and the genuinely rare
case where waiting costs nothing gets buried in the same noise as the normal
case. Worse, even carefully worded, it reads as a nudge toward delay, which
fights the system's own stance that in-term evidence is already enough
reason to act (see `InterventionController::store()`'s own docblock). If this
is revisited, the one version that might be defensible is conditional, not
constant — show it only when a `RiskResult` doesn't yet exist for this
student/term but an earlier term this school year already has one, so it
appears only for a Principal who has demonstrably had the option, not
universally. Not built; flagged so a future session finds the reasoning
already made rather than re-deriving it.


