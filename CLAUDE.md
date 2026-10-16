# SHS Decision Support System — standing instructions

An EXISTING Laravel Senior High School DSS. Develop incrementally; never rebuild
from scratch; preserve existing functionality and data. The DSS is rule-based
academic monitoring and decision support: it surfaces evidence and recommends;
it predicts nothing, and the Principal makes the final decision.

This file holds standing rules only. History, audits and detailed reference live
in `docs/claude-archive/` (index below). When this file, a work order and the
code disagree, the code is the answer and the disagreement is worth reporting —
do not silently change a requirement to match code, or code to match a stale doc.

## Roles and authorization

Four roles (`User::ROLES`): `admin`, `adviser`, `subject_teacher`, `principal`.
`admin` and `principal` are singletons. `role:` middleware accepts several roles.

- **Admin** — master data: users, tracks, specializations, subjects (incl. Terms
  Taught and Subject Group), sections, learners (add/import/edit/remove — Admin
  only), school years, terms, teaching assignments.
- **Adviser** — advises one section per year (`sections.adviser_id`). Reads
  every applicable subject of that section (Advisory Overview, read-only Class
  Record), verifies official grades, submits the term report, acknowledges and
  delivers interventions. My Students is view-only.
- **Subject Teacher** — edits evidence only where assigned.
- **Principal** — Dashboard (incl. Performance by Grade and Section,
  `SectionPerformanceService`), Students, Academic Monitoring (review concerns),
  Subject Analysis, Interventions (decide), Reports.

**Teaching assignments are the ONLY write grant for evidence.**
`teaching_assignments` (user, section, subject, school_year, grading_period;
one teacher per section+subject+term). `App\Services\TeachingAccess` decides
every read/write (Class Record, export, copy, item add/edit, Encode Grades,
learner report). Advisory alone never grants writing. An Adviser account may also
hold assignments. Nothing was backfilled; never auto-assign. A role change may not
strand current sections/assignments (`Admin\UserController::update()`).

Authorization is enforced server-side (middleware, `TeachingAccess`, query
scoping), never by hiding UI. Test direct URLs and wrong roles.
`RoleAccessMatrixTest` reads the live route table — do not hand-list routes.

Writes require the active year's open term: `AcademicTerm::acceptsWrites()`.
Closed terms and past years are read-only even by direct POST.

## Assessment evidence workflow (current)

Admin assigns teacher → teacher opens **Class Record** (`teacher.class-record`)
→ **Add Assessment** (details only) → type/paste scores in the grid → **Save
Scores** (one all-or-nothing batch) → Download Excel / Copy Scores.

- **Teacher/adviser assessment FILE UPLOAD is RETIRED** (detect/preview/import/
  record routes gone). Do not reintroduce it without a decision. ECR reader
  services remain for Admin "Import Learners from ECR". Encode Grades keeps its
  separate final-grade import (not official until verified).
- Add/Edit Assessment (`teacher.assessments.*`) never carry scores; Edit is
  version-checked and refuses an HPS below a recorded score.
- Saves: every cell carries its loaded value; a changed stored value is a 409,
  never a silent overwrite; a retry with the identical value is not a conflict.
  "Saved" only after the server confirms. No autosave.
- Save scope (2026-10-04): the assessments with a changed cell. The page sends
  every changed cell plus the still-blank cells of those assessments; a SENT cell
  with no score is saved as Missing (audit source `class_record_auto_missing`).
  A cell not sent (other assessment, unloaded learner) is never read as blank.
  No per-learner Mark Missing control. An all-blank assessment is put in the
  save explicitly by the assessment-scoped "Save this assessment's blanks as
  Missing" action (selected cell's assessment; `planSave(cells, extraScope)`).
- Grid follows the SSHS E-Class Record "Term 1" sheet: WW/PT slots 1–10 (more
  items continue 11, 12…; empty slots are placeholders, never assessments),
  ST 1 / ST 2 / TE + WS ST1/ST2/TE, PS, WS, Initial Grade, Term Grade; learners
  grouped MALE/FEMALE by recorded gender (`ClassRecordService::templateColumns()`).
- Live preview `teacher.class-record.preview`: same auth as save, READ ONLY —
  draft values overlaid on a fresh `GradingEngine` (`overlayScores()`), nothing
  written. The browser never calculates.
- Examination Type (ST1/ST2/Term Exam) per `App\Services\ExaminationRoles`; one
  regular item per type per class/term.
- Evidence history: score/marker changes are audited through model events — use
  model deletes, never query-builder deletes, on scores and markers.
- `GET /adviser/assessments` only redirects to the Class Record.

## Blank, Missing and zero — never conflate

- A score row (including **0**) = **Completed**. A recorded 0 is evidence.
- A marker (`assessment_learner_statuses`, `M`) = **Missing** — set when a blank
  is SAVED with its assessment (automatic since 2026-10-04) or by other paths.
- Blank (unsaved, or in an assessment never saved) = **No Evidence Yet**. Blank is
  never 0.
- A score and a Missing marker never coexist. `GradingEngine` never reads markers.
- A subject with zero scored items reads "No Evidence Yet", never On Track, and is
  excluded from overall status (missing means incomplete, not zero).
- Imports are all-or-nothing (`AssessmentUploadService::blockingProblems()`).

## Grading

**Workbook parity (D1–D3, implementation decision 2026-10-04 — a project
requirement, NOT a recorded school approval):** the engine follows the client
SSHS E-Class Record "Term 1" row-17 formulas, verified cell by cell against the
workbook's own formulas in `WorkbookParityTest` (`tests/Support/WorkbookOracle`).
- WW/PT: PS = ROUND(numeric earned / HPS of EVERY item in the component × 100, 2);
  blank and Missing earn 0 but keep their HPS; no numeric score → no PS (never 0).
  WS = ROUND(PS × weight/100, 2). Missing stays a marker, never a stored 0.
- Exam: per type WS = ROUND(score / HPS × share, 2); PS = Σ type WS ÷ Σ CONFIGURED
  shares × 100 (unrounded; shown 2 dp); WS = ROUND(PS × weight/100, 2). Never
  rescaled over the types given. One REGULAR item per type column
  (`ExaminationRoles::workbookColumns()`, enforced on Add/Edit). Two regular
  items of one type (legacy/race) BLOCK the class's Examination figure (no grade,
  not verifiable, correction message; `dss:check-integrity` lists them) — never
  "first wins", never deleted. Untyped items beside typed ones are not counted
  (warned). No configured shares (DO 8): the old equal split. No typed item: pooled.
- Additional-support items (2026-10-06, HANDOFF "additive"): apply only to the
  learners given them (a numeric score) — points AND HPS added for them, absent
  for everyone else; the flag never changes how a scored item counts. A support
  blank is "not given": a save never turns it into Missing.
- Transmutation (DO 015) follows the workbook's AM17 XLOOKUP over the STORED
  HELPER maxima: 69.99 → 75 and 71.17 → 76 (bands 65.34–69.98 / 69.99–71.16 /
  71.17–72.35; migration `2026_10_04_000001`). Verified against Microsoft Excel for
  all 10,001 2-dp values (`docs/excel-validation/`). Project decision, NOT a school
  approval; it diverges from the printed table at 69.99 (pass/fail).
- An "Other elective / special curricular program" profile (`teacher_supplied`
  catalog row) is UNCONFIGURED: clear message, no figure, no official grade,
  never another profile's weights. Evidence can still be recorded.
- `complete` (computed_grade, verification) still needs a score in every exam
  type GIVEN; `workbook_complete` = the workbook's AL17 would be filled.
Weights are DATA, never hardcoded. Never average the three percentages.

`GradingEngine::resolveWeightProfile(Section, Subject)` is THE resolver
(Admin > Subjects uses `resolveSubjectProfile()`, which calls it). Order:
linked `deped_subject_catalog` row → scheme's `subject_group_weights` row →
scheme default. Do not call `SubjectGroupWeight::resolve()` for a grading figure
elsewhere.

- Scheme (`TransmutationService::schemeFor()`): explicit `sshs` → DO 015, s. 2026;
  explicit `k12_2013` → DO 8, s. 2015; NULL → SY 2026-2027 onward DO 015 (both
  grade levels), earlier DO 8. Grade level never decides the scheme.
- DO 8 test example (25/50/25): WW 84.44 / PT 85 / Exam 70 → 21.11 + 42.50 +
  17.50 = 81.11. Keep it as an automated test; never hard-code it.
- DO 015: weights per subject group/catalog row; some groups have no Examination
  (null means absent, never zero). Exam role shares are per catalog row; 30/30/40
  in `exam_role_shares` is only the fallback.
- Running Initial Grade (Class Record display only, 2026-10-04): `running_grade` =
  sum of the WS present — weights NOT renormalised (equals AL17 once every WS
  exists). `computed_grade` stays null until complete, so status, concerns
  and verification never see a running grade. Term Grade only when complete.
- After the D1–D3 change no official grade was rewritten. A verified grade whose
  computed figure differs reads `verified_changed` (Re-verify); one whose
  evidence is no longer complete reads `verified_stale`; the Class Record, the
  learner results (Advisory Overview, learner report) and every
  `SubjectResultSummary` report say "evidence changed — re-verify" — one rule,
  `ClassRecordService::evidenceChanged()`. Working-data impact:
  `WORKBOOK_PARITY_FOLLOWUP_REPORT_2026-10-06.md`.
- Export (`ClassRecordExport`) is a client-style SYSTEM export, never labelled the
  official workbook (no formulas, no INPUT DATA/HELPER, no school header fields).
- An uploaded/teacher-typed weight never overrides the configured profile.
- `do8_*` rows are computed from section track + subject type; never selectable.
- Subject Group is required for Grade 11 and 12 and must match type
  (`SubjectGroupWeight::classificationError()`); no silent `core_academic` default.
- Applicability: `Subject::forSection($section, $term)` →
  `SubjectApplicabilityService` (grade level + core/track/section elective choice
  + Terms Taught). `section_subjects` = elective choices only.
- Performance: evidence is loaded once per (section, term, year) in
  `GradingEngine::evidenceFor()`. No query inside `computeGrade()`'s path. A write
  that bypasses model events must call `GradingEngine::invalidateEvidence()`.
- Workbook reads load named sheets only (`loadSheets()`), never a full `load()`.

## Official grades and verification

- **Encode Grades** (`adviser.grades`): manual final-grade entry + final-grade
  import; needs a teaching assignment; a typed grade is "Not yet official".
- **Verify Grades** (`adviser.verify-grades`): separate page; the advisory
  adviser verifies (individual or whole-class bulk, row-locked, 409 if the
  eligible set changed). Verification is advisory, not a teaching act. Subject
  Teachers, Principal and Admin get 403.
- Nothing becomes official by adding an assessment, saving scores, downloading,
  or reassigning a teacher.
- Failing = verified, not provisional, grade `<= 74.0`
  (`InTermStatusService::FAILING_THRESHOLD`, `Grade::scopeFailing()`). One rule;
  do not add another threshold.
- The 75 in-term target is a system reference point, not a DepEd rule; no
  monitoring rule reads it. In-Term labels are descriptive ("Below Target").

## Term reports (submission boundary, 2026-10-07)

The Adviser's Submit Report page is a PRIVATE draft preview of live grades.
Recipients (Principal/Admin Reports, dashboard submission counts) see a term
only after an explicit Submit, and then the frozen snapshot in its latest
`report_submission_versions` row (`ReportSnapshotService`), never live grades.
Submit: `expected_version` required (double click / retry / stale tab submit
nothing), section row lock + adviser re-checked inside the transaction,
snapshot built inside it (failure = no row), log after commit; author/time are
server-side. Re-submit adds a version; earlier versions are never rewritten.
Readers use `ReportSubmission::submitted()`. Legacy rows without a version show
"contents not preserved" — never reconstructed from current grades. There are
no report attachments or editable report fields. Migration
`2026_10_07_000001` (additive); until applied, submission is refused with a
message (`ReportSubmission::versioningAvailable()`).

## Monitoring and interventions

`academic_concerns` is the operational source of truth, written only by
`AcademicMonitoringService`. Enabled rules (`config('dss.monitoring_rules')`):

1. `MissingRequirementRule` — an explicit Missing marker on a required item.
2. `OfficialFailingSubjectRule` — one concern per learner per subject per term
   whose OFFICIAL grade is Failing. Other subjects' grades never hide or resolve it.

No other numeric, decline, count or attendance rule exists; adding one needs
school approval. Concerns are never deleted; they resolve only from evidence
(a score recorded, a marker removed, the official grade passing). Review,
intervention, acknowledgement and delivery never resolve a concern. Sync runs
after the evidence transaction commits; a sync failure is reported, never undoes
evidence. `MonitoringClassificationService` is the one place overall
monitoring (No Current Concern / For Review + count) is derived — no severity.

Interventions: the DSS recommends; the Principal decides; never auto-approve.
Principal "Reviewed" means inspected, nothing more. Lifecycle: Principal records
(optionally from an open concern; subject required; type from
`Intervention::SELECTABLE_TYPES`, never pre-selected) → decides → Adviser
acknowledges → Adviser records delivery once with a required note (per learner or
a genuine group, labelled as group). Open-duplicate check runs under a learner row
lock. Progress comparison uses neutral language and never claims causation.

**Scheduling (2026-10-06, `INTERVENTION_SCHEDULING_REPORT_2026-10-06.md`):** every
schedule/completion/expiry/cancel transition goes through
`InterventionScheduleService` (row lock + `schedule_version`; history in
`intervention_events`, unique dedupe key). Lifecycle is DERIVED
(`Intervention::lifecycle()`); `expired`/`cancelled` are status values outside
`OPEN_STATUSES`, never set by the Principal's status dropdown
(`EDITABLE_STATUSES`). Completion = responsible adviser only (eligible = the
section's active, unambiguous adviser), server time ≤ deadline (deadline inclusive);
reuses `delivered_at/by/notes` + `completed_at`. Scheduled interventions are
never delivered by advisers. Expiry: `dss:expire-interventions` (everyMinute);
pages show overdue as Expired without writing. Nothing here touches scores,
grades or concerns.
**Responsibility (2026-10-07, adviser auto-assignment):** the responsible staff is
ALWAYS the section adviser of `interventions.section_id` (the learner's enrollment
section for the intervention's year), resolved on the server by
`InterventionScheduleService::resolveAdviser()`/`adviserFor()` — no staff picker, a
submitted id is ignored, no fallback account. No/disabled/non-adviser/ambiguous
adviser → recording and approval are refused with an "Admin › Sections" message.
Recording also requires the subject to be taught to that section in that term.
Changes move only through `transferToSectionAdviser()` (locked, version bump,
`reassigned` event): Principal "Transfer to <adviser>", Admin changing a section's
adviser (same transaction), `dss:reconcile-intervention-staff` (dry run; `--apply`).
Completed/cancelled keep historical responsibility. SUBJECT TEACHERS HAVE NO
INTERVENTION ACCESS (no nav item, no routes, omitted from their learner report);
adviser writes are `adviser.interventions.*`, old `/teacher/interventions` GETs are
adviser-only redirects. Viewing: the section's adviser or the responsible adviser.
**Schedule authority (2026-10-07, report §14):** the Principal decides, cancels,
and reviews on-time-completion corrections. The RESPONSIBLE adviser sets,
reschedule (reason), extend (reason; later than current deadline and now; start
kept) and reopen an expired one (new start+deadline+reason; records a pending
expiry first) DIRECTLY — no approval step. Others in scope: view only. The
Principal's old schedule/reopen POSTs are retired (refuse, 410 JSON); legacy
schedule/extension requests are kept, never approved, superseded by the next
direct change. Staff start may not be backdated (my choice, unconfirmed).
`InterventionScheduleService::availability()` gives the shown "why not" reason.
Guarded by `Intervention::schedulingAvailable()`; migration applied to the working DB
2026-10-06. The "no other open" guard must stay a LOCKING read (REPEATABLE READ race).
UI: ONE "Interventions" item for Principal and Adviser (none for Subject Teacher);
the record's page is principal/adviser `interventions/{id}`. Shown schedule status = `Intervention::scheduleState()` (legacy
unscheduled delivery stays "Schedule not set" + "Delivered").

**Retired — do not reintroduce without an explicit decision:**
- ML classifier/Python: nothing launches a process. `risk_results` and
  `interventions.risk_result_id` are LEGACY history only.
- Attendance monitoring: no page, route, rule or new intervention type.
  `attendance_records` and old attendance concerns are dormant history, filtered
  out by `AcademicConcern::scopeActiveRules()`.

## Data and history protection

- NEVER run `migrate:fresh`, `db:wipe`, `DROP DATABASE`, or `migrate:rollback` as
  recovery, unless explicitly authorized. Recovery is restore-from-dump.
- Before a DB change: mysqldump; inspect migrations and schema; smallest new
  migration; never edit a migration that has run; verify status after.
- `school_year` string is the join key; records store their own context
  (section, year). `students.section_id` is the current pointer only;
  `student_enrollments` is history — use `Student::enrolledIn()` /
  `Section::enrolledStudents()` for rosters.
- `ProtectsAcademicHistory`: records with academic references cannot be deleted.
  No delete routes for years or terms.
- Spreadsheet uploads validate by EXTENSION (`ValidatesSpreadsheetUpload`), never
  `mimes:`; every import reads through `importSpreadsheetOrRefuse()`.
- Every text cell written to CSV/TSV/xlsx goes through
  `ClassRecordService::safeText()` (formula-injection safe).
- No `{!! !!}` in views; no comma inside a Blade `@json(...)` argument.
- No login password is committed (`SEED_ACCOUNT_PASSWORD`).

## Test and environment isolation

- Run tests ONLY as `php artisan test` or `vendor/bin/phpunit -c phpunit.xml`.
  `--no-configuration` once wiped the live database. `tests/TestCase.php` refuses
  anything but sqlite `:memory:` — never remove that guard.
- `phpunit.xml` pins `APP_URL`, `LOG_CHANNEL=null`, proxy and seed-password vars.
- Run the suite in foreground chunks (memory): `tests/Unit`, then
  `--testsuite Feature --filter 'Feature.[A-D]'`, `[E-L]`, `[M-R]`, `[S-Z]`
  (Git Bash: `MSYS_NO_PATHCONV=1`). JS: `node --test tests/js/*.mjs`.
- Browser checks only on the isolated harness (`tests/Browser/`: `prepare.php`,
  `php -S … tests/Browser/router.php`, `node tests/Browser/run.mjs`).
  `php artisan serve` with env overrides still hits the WORKING database. Never
  point automation at Apache/XAMPP or send correct-role POSTs at the live DB.
- `bootstrap/app.php` must keep `Env::disablePutenv()`.
- Last RECORDED baseline (2026-10-07, report submission boundary): 1,351 PHP
  tests, all passed (the intermittent `SeederCredentialsTest` symbol-mangling
  failure did not occur), 19 JS, 165 browser checks (`run.mjs`) + 30
  (`concern-flow.mjs`, needs a fresh prepare) + 17 (`report-flow.mjs`, fresh
  prepare) on isolated SQLite (MySQL isolation last run at an earlier
  baseline); `intervention-flow.mjs` 38 (SQLite, fresh prepare; wait a moment
  between back-to-back runs or Chrome's debug port collides);
  `verify-race.mjs` (12 runs) / `loadtest.mjs` (4/8/16 VUs) run 2026-10-06. The count goes
  down only when a feature is removed with its tests, and the replacement
  coverage is named.

## Working rules

Loop: inspect → plan → implement → run → test → diagnose → fix → retest →
regression check → verify → document. Never stop after generating code.

Forbidden routes to green: deleting/skipping/commenting out a failing test;
weakening an assertion; mocking over a real failure; swallowing an exception;
editing a run migration; deleting rows to pass a count; guessing an answer a human
was asked for. Same error after three attempts → stop and report. A fix that needs
a forbidden change → stop and ask.

No false completion: never say done/completed/successfully implemented without
verification; otherwise report `STATUS: INCOMPLETE` with the exact problem. Do not
present historical results as newly run.

Duplicate computations drift: before adding a figure, find the existing one-place
service (`InTermStatusService::overallStatusForSection()`,
`Intervention::scopeUndecided()`, `SectionElectiveStatus::expectedGradeCount()`,
`SubjectResultSummary`, `MonitoringClassificationService`). Grep
`resources/views/` too.

UI: use `resources/css/app.css` component classes and `.tbl`; `status-*` colours
only for the four DSS states; non-destructive confirmations use the neutral
action modal, never the Delete modal. Details: archive file 06.

## Unresolved school-policy decisions — do NOT decide or "fix"

- **D-01 official-grade correction:** correcting an already-verified grade via
  Encode Grades keeps it verified with the typed value; no reason is required
  (logged as `correct_official_grade`). Whether it should need re-verification
  or a reason is the school's call.
- **74–75 inconsistency:** Failing is `<= 74.0`, so an official 74.01–74.99
  counts as Passed and opens no concern, while Reports/Submit Report shade `< 75`
  red and `RecomputeGradesCommand` uses `>= 75`. Only typed/imported grades can
  land there. Unresolved.
- Whether a typed (unverified) grade counts toward Submit Report's "every
  expected grade encoded" vs. requiring verified grades.
- How within-term additional-support items count for the learners given them
  (additive today; replacement or capped averaging not chosen). Formal
  SRC/RCM/RFG is not implemented.
- **Binary-sum transmutation (confirmed in Microsoft Excel 16.0.20430,
  2026-10-06):** the workbook's AL17 = SUM(P17,AC17,AK17) is a double; when it
  lands a hair ABOVE an exactly-stored band maximum, XLOOKUP gives the next band
  (0 + 46 + 28.71 = 74.710000000000008 → 79). The system adds exact hundredths
  (74.71 → 78). Only Initial Grades exactly on one of 38 band maxima, +1 Term
  Grade, never pass/fail. Whether to reproduce it is undecided; do not add an
  epsilon or float emulation without a decision.
- Whether 69.99 → 75 / 71.17 → 76 (workbook lookup, implemented) should yield to
  the printed DO 015 bands (69.99 → 74) — the school's confirmation is pending.
- "Other elective / special curricular program": the workbook takes the
  teacher's own weights (INPUT DATA!F43:F48, empty in the template); the system
  has no source for them, so it is unsupported (no grade) until the school says
  who sets them and what they are.
- Q1: SSHS electives chosen per section or per learner (per-section today).
- Whether Grade 12 grading should read `subject_group` (keyword stopgap today);
  whether any Grade 12 section is truly `k12_2013`; DO 8 row figures (secondary
  sources); DO 015 transmutation vs. the signed order (matches the ECR workbook).
- Whether in-term Below Target should ever open a concern; whether a subject
  teacher may verify their own subject; Principal bulk dialog's type pre-fill.
- Section/roster names (Shakespeare, Curie, ABM/HUMSS/STEM counts) are NOT
  confirmed roster data.

## Known gaps (recorded, not fixed)

- ECR roster reconciliation reports file-only learners, not enrolled learners
  absent from the file.
- New specializations get `curriculum = NULL` with no warning.
- Laravel-Excel temp copies left in `storage/framework/cache/laravel-excel/` on a
  read error.
- Dockerfile/Railway not build-verified; the local Apache/MySQL hardening is
  machine config outside the repo (re-check before demos).

## Reference index

- `docs/claude-archive/README.md` — index of the archived former CLAUDE.md
  (verbatim, split by topic; historical, do not import wholesale).
- `HANDOFF.md` — design decisions that may not change without asking.
- `ECR_ALIGNMENT_WORK_ORDER.md`, `WORK_ORDER.md` — work orders (partly landed).
- `FINAL_DEMO_AUDIT_2026-10-06.md` (latest), `FINAL_DEMO_AUDIT_2026-09-30.md`, `FINAL_AUDIT_2026-09-28.md`,
  `RELEASE_READINESS_2026-09-28.md` — latest audits, demo/UAT, deployment.
- `ML_ARCHITECTURE.md`, `TRAINING_DATA_CONTRACT.md` — RETIRED, historical.
