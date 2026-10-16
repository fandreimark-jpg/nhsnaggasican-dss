# Rule-based academic monitoring, ML cut-over and attendance retirement (2026-09-26/27)

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 3406-3763; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

---

## Rule-based academic monitoring (2026-09-26/27, Phases 2A-2E and the ML cut-over) — the system's monitoring

Branch `rule-based-academic-monitoring`. The DSS is RULE-BASED ONGOING
ACADEMIC MONITORING AND DECISION SUPPORT: it surfaces observable concerns
in recorded evidence and predicts nothing. `academic_concerns` is the
operational source of truth. The ML classifier is retired (see "The ML
cut-over" below); the Principal is the decision-maker.

- **Assessment learner status (2A).** Per learner per item: a score row →
  **Completed** (a recorded 0 is Completed); else an adviser's explicit
  marker in `assessment_learner_statuses` (`status = missing`) →
  **Missing**; else → **Not Yet Given / No Evidence Yet**. Completed is
  never stored. Blank is never zero and never Missing: an ECR blank or a
  blank form field writes nothing. A score and a marker never coexist
  (`AssessmentStatusService`, both models' hooks, `dss:check-integrity`
  4b). `GradingEngine` never reads markers — Missing is still "no
  evidence", never a 0. Unchanged on purpose: a repeat-ECR blank keeps an
  existing score; a blank on manual Edit clears it.
- **Academic concerns (2B).** `academic_concerns`, written only by
  `AcademicMonitoringService`. One concern per learner per rule context
  (for the Missing rule, per assessment item); `open_key` + a unique index
  allow one OPEN concern per context and any number of resolved ones.
  Concerns are never deleted: when the evidence no longer meets the rule
  they are **resolved** with `resolved_at` and a `resolution_reason`, and a
  recurrence opens a new row. Lifecycle timestamps are DATETIME, not
  TIMESTAMP — MariaDB 10.4 with `explicit_defaults_for_timestamp = OFF`
  rejects a second NOT NULL TIMESTAMP (error 1067);
  `MigrationTimestampPortabilityTest` guards every migration.
- **The enabled rules** are `config('dss.monitoring_rules')` =
  `MissingRequirementRule` (EXPLICIT_MISSING_REQUIREMENT: an adviser
  marked a required item Missing → For Review) and, since 2026-09-27,
  `OfficialFailingSubjectRule` (OFFICIAL_FAILING_SUBJECT_GRADE — see
  "Subject-first monitoring" below). The Attendance rule briefly enabled
  that day is RETIRED. No in-term component, count, decline or attendance
  threshold exists; one must be approved by the school before it is added. A concern never creates, approves or
  links an intervention.
- **Automatic synchronization (2C).** Adviser Add item, Edit item and
  ECR/file import call `AcademicMonitoringService::synchronizeAssessments()`
  AFTER their evidence transaction commits, for exactly the items they
  changed (`MonitoringRule::constrainOpenConcerns()` keeps a narrow
  evaluation from resolving anything outside its items). A failure never
  undoes saved evidence: it is `report()`ed, the adviser is told the
  concern status may not be current, and `dss:check-integrity` 4c lists
  the gap. `dss:evaluate-concerns {year} {term} [--dry-run]` is the manual
  re-evaluation; its dry run is the proof live sync is complete.
  Validated live 2026-09-26 (Quiz 1, Agila, Tests A-D).
- **Principal Academic Monitoring (2D).** `principal.academic-monitoring`
  (`Principal\AcademicMonitoringController`,
  `AcademicMonitoringOverviewService`), read-only, Principal-only. Default
  scope: the active year's open term, status For Review; filters School
  Year / Term / Grade Level / Section / Subject / Status, each validated
  against the selected year (a stale id is ignored, never widened). Track
  and Specialization are shown from the section, never offered as
  filters. Concerns are grouped by learner + subject for display only.
  Each concern shows its evidence and the item's CURRENT status (a resolved
  concern whose item now holds a 0 reads Completed). The dashboard's "For
  Review" card counts open concerns for its school year. What "Reviewed"
  means was decided in Phase 2E (below): the Principal inspected the
  evidence, nothing more — do not add Dismiss/Confirm semantics.

- **Principal review (2E).** `principal.academic-monitoring.review`: the
  Principal marks an OPEN concern Reviewed (`reviewed_by`/`reviewed_at`,
  optional plain-text `review_note`). That is ALL it records: never
  resolves, never creates an intervention; a resolved concern or a second
  review is refused and the first review is kept. Shown as
  **Open · Reviewed** vs **For Review**.
- **Intervention from a concern (2E).** The Record Intervention modal on
  Academic Monitoring posts to the existing `principal.interventions.store`
  with `academic_concern_id`, accepted only for an OPEN concern matching the
  learner, subject, term and active year. Nothing is pre-selected; the
  Principal chooses the type. The link never resolves the concern.
- **Delivery (2E).** Adviser acknowledges, then records delivery with a
  required note (`markDelivered()`), once: a second delivery is refused
  under a row lock and never overwrites who/when/what or logs twice.
  Delivery never resolves a concern. Academic Monitoring shows each linked
  intervention's adviser side (not yet acknowledged / acknowledged /
  delivered + note) and, for an open concern, says delivery does not close
  it. UAT-verified live 2026-09-26 (GALANG #5/#165: delivered at 22:29,
  concern stayed open; Quiz 1 = 16/20 recorded at 22:40:52 through the
  normal Edit form resolved #5 with "A score was recorded for "Quiz 1".",
  review and intervention history intact).
- **Evidence-driven resolution.** Only monitoring resolves: a real score
  (0 included) or removing the Missing marker. Review, intervention,
  acknowledgement and delivery never do.
- **Readiness ("ready for review/close") — decided, keep.**
  `isReadyForReview()` is false while the linked concern is OPEN or the
  subject has no scored item; otherwise the evidence must read On Track. It
  can be true on thin evidence (one Written Work item); kept deliberately
  (2026-09-27) — the page shows the item count and the Principal decides.
- **No Evidence Yet.** A learner/subject/term with 0 scored items reads
  `InTermStatusService::NO_EVIDENCE` ("No Evidence Yet") — never On Track,
  never a concern — with its own filter option on Adviser Assessments and
  Principal Students. A recorded 0 is evidence.
- **In-Term Status is DESCRIPTIVE (decided 2026-09-27).** The
  components-below-the-75-target rule stays as an evidence summary, never
  a concern or prediction. Stored/URL keys are unchanged ('At Risk',
  'Needs Attention'); ONLY the displayed words changed, via
  `InTermStatusService::LABELS`/`label()` ("Below Target: 2+ Components",
  "Below Target: 1 Component") and `COMPONENT_LABELS` (Meets/Below Target).
  Do not add a numeric concern rule from it without school approval.

### The ML cut-over (2026-09-27)

- **Removed:** Submit Report's classifier call (`runAnalytics`,
  `buildPythonPayload`, output validation, the failing-subject override),
  `RiskFeatureExtractor`, `InterventionRecommender` (its suggestions were
  built from risk rows and pre-selected the type), the risk halves of
  `DashboardAnalyticsService` (risk cards, charts, Recommendations panel,
  at-risk list, trend/decline/DSS-status helpers), the Principal Students
  Risk Level column, student detail's risk card/history/Term-over-Term,
  Subject Analysis' at-risk count, the Interventions Risk Level filter and
  Term-over-Term Progress column (`ProgressMonitoringService::compare()`),
  the stale-risk banner partial, Reports' Risk Level column, the
  `services.python_path` config, and the Python stage + model check in
  `Dockerfile.vercel` (NOT build-verified — no Docker on the dev box).
- **Submit Report now:** term open, previous term submitted, subjects
  configured, every expected grade encoded → records the submission and
  logs it. It cannot fail because Python is missing.
- **Subject Analysis** shows **Open Concerns** per subject (open
  `academic_concerns` in scope + learners covered), linking to Academic
  Monitoring. Intervention logs now read "…from academic concern #N" or
  "…from current assessment evidence".
- **Kept as LEGACY/ARCHIVED:** the `risk_results` table and `RiskResult`
  model/relations (history protection: `ProtectsAcademicHistory`, the
  Section delete guard, `SubjectApplicabilityService` history checks,
  `dss:check-integrity`'s "Legacy (archived) risk_results" finding,
  `AcademicTerm::staleRiskTerms()`), the nullable
  `interventions.risk_result_id` column (never set now), and the
  `analytics/` folder (since REMOVED from the working tree, Git history only). Their
  removal is a future, explicitly approved schema/cleanup decision.
- `NoOperationalMlDependencyTest` is the test of record: no app code
  launches a process or references the classifier; only history protection
  touches `risk_results`; no view shows classifier wording; a full
  Missing → concern → intervention → resolution cycle runs with no Python and
  writes no risk rows.
- **Open questions (not changed):** the Principal Students bulk dialog still
  pre-fills each row's type from its weakest component (evidence-based, not
  ML; the Principal reviews and confirms) — whether that counts as
  pre-selection is the school's call. `PAPER_UPDATE_CHECKLIST.md` lists the
  research-paper sections to revise.

### Attendance evidence and the Attendance Concern (2026-09-27) — RETIRED the same day

> Superseded by "Subject-first monitoring and the attendance retirement"
> below: the page, routes, controller, rule, JS helper and their tests
> were removed; the table and its rows remain as history. Kept for the
> reasoning it records.

- **`attendance_records`** (migration `2026_09_29_000001`, additive): one
  row per learner per school year per term (unique), `section_id` stored,
  FKs restrict. `school_days`/`days_present`/`days_absent`/`days_tardy` are
  NULLABLE — blank is "not recorded", never 0. `concern_flagged` +
  `concern_note` are the adviser's explicit statement. Protected history
  (`ProtectsAcademicHistory` for students, sections, users.recorded_by).
- **Adviser > Attendance** (`Adviser\AttendanceController`,
  `adviser.attendance`/`.store`): own section only (a foreign learner id is
  403), active year's open term only (`acceptsWrites()`), all-or-nothing
  validation (whole numbers 0-366; present/absent/tardy consistent with
  school days; tardy ≤ present; a note is required with the flag, ≤1000
  chars; unticking clears the note). Logged as `record_attendance`.
  Monitoring sync runs AFTER commit; a failure is `report()`ed and flashed
  as `monitoring_warning`, never undoing the save.
- **`AttendanceConcernRule`** (EXPLICIT_ATTENDANCE_CONCERN): a flagged
  record → one For Review concern per learner per term, `subject_id`
  NULL, `context_key` `attendance:{year}:{term}`, evidence = the day counts,
  note and who flagged it. **No threshold**: 35 absences without the flag
  create nothing. Resolves only when the flag is cleared ("The adviser
  cleared the attendance concern flag for this term."). An item-scoped
  (assessment) evaluation observes nothing AND reconciles against nothing
  (`whereRaw('1 = 0')`) — without that, every score save would resolve
  every attendance concern. `AcademicMonitoringService::
  synchronizeAttendance()` runs ONLY this rule. A learner can hold a
  Missing Requirement and an Attendance Concern at once, and can be For
  Review for attendance with every assessment On Track.
- **Principal**: Academic Monitoring has a **Concern Type** filter
  (`concern_type=missing|attendance`, never the raw rule key); attendance
  groups read "Attendance (not tied to a subject)" with their own evidence
  panel. Subject Analysis' Open Concerns count `whereNotNull('subject_id')`
  — attendance is never attributed to a subject. `interventions.store`
  accepts a NULL subject ONLY with an open attendance concern linked (and
  refuses a subject on one); the open-duplicate guard compares NULL with
  `whereNull`. Intervention lists show "Attendance" for such a row.
- **DSS Assessment Record** (`App\Services\DssAssessmentRecord`,
  `adviser.assessments.record`): the adviser downloads a ready workbook
  (roster, recorded items, HPS, scores; blank Missing cells highlighted,
  never a MISSING token) with a very-hidden `DSS INFO` identity sheet, and
  uploads it back through the normal detect → verify → preview → import
  path. Identity contradictions (year/term/section/subject) refuse detect,
  preview AND import; duplicate item headers refuse the file
  (`DuplicateAssessmentHeaderException`); metadata conflicts still refuse
  via `AssessmentItemConflictDetector`. Blank never changes a score; 0 is
  Completed. `DssAssessmentRecordTest` is the test of record.
- `analytics/` is DELETED from the working tree (Git history only).
- **Wording (2026-09-27, professor's framing):** the module is a **Term
  Attendance Summary** — summarized counts for the whole term, copied from
  the school's existing records; the DSS keeps NO daily attendance. Sidebar,
  page title, help ("No automatic attendance threshold is currently
  applied"), Principal evidence ("Late / Tardy", "Reason / Note", "Not tied
  to a subject", badge "For Review — Attendance Concern") all say so. Route
  names, classes, the table and columns are unchanged. School Days has an
  "Apply to All" helper (`public/js/adviser/attendance.js`, custom
  "Replace School Days?" modal, never saves; `tests/js/
  attendance-school-days.test.cjs`, run with `node --test`).
- **Intervention type wording has ONE map:** `Intervention::TYPE_LABELS` /
  `typeLabel()`. The stored `attendance_monitoring` value is unchanged and
  displays as **"Attendance Follow-up"**; `remediation` still displays as
  "Additional Practice and Re-teaching". No view carries its own copy.

### Assessment import is all-or-nothing (2026-09-27)

`AssessmentUploadService::blockingProblems()` is the one classification,
shared by `previewRows()` (Preview shows the list and offers NO Import
button) and `import()` (refused with `AssessmentImportBlockedException`
BEFORE its transaction opens; the controller's outer transaction means no
upload row survives either).

- **BLOCKING:** a score that is not a number or is negative; a score above
  the item's maximum; the same LRN on two rows; an LRN not enrolled in the
  section whose row carries any score. Also blocking, as before, at their
  own layers: unreadable file, a non-numeric/zero MAX row cell, duplicate
  item headers (`DuplicateAssessmentHeaderException`), contradictory DSS
  INFO or ECR identity, and changed protected metadata
  (`AssessmentItemConflictDetector`).
- **WARNING (imported, nothing lost):** an unenrolled LRN whose row is
  entirely blank — recorded in the upload's `error_count`.
- Blank cells are never an error, never 0, never Missing.
- Before this, the four blocking row/cell cases were SKIPPED and the rest of
  the file imported — a real result could silently go missing.
  `AssessmentImportAtomicityTest` is the test of record.

### Overall Monitoring is derived from open concerns — and is NOT academic performance (2026-09-27)

- **`App\Services\MonitoringClassificationService`** is THE one place a
  learner's current overall monitoring classification is derived
  (`App\Monitoring\MonitoringStatus`): 0 OPEN `academic_concerns` in the
  school year/term -> **No Current Concern**; 1+ -> **For Review**, with the
  open count and the concern types ("For Review — Attendance Concern",
  "For Review — 2 Open Concerns"). Nothing is stored; resolved concerns and
  legacy `risk_results` are never read. NO severity: the count never
  becomes Low/Moderate/High. `forStudents()` answers a whole roster in ONE
  query; `summarize()`/`summary()` give Learners For Review (distinct) and
  Open Concerns. Rendered only by `<x-ui.monitoring-status>`.
- **Academic Performance** is the renamed display of In-Term Status (On
  Track / Below Target / No Evidence Yet) — shown BESIDE Monitoring, never
  merged: a learner can be On Track and For Review (the GALANG UAT case: high
  grades, an adviser-flagged Attendance Concern).
- Used by: Adviser Assessments and Dashboard (Monitoring column; For Review
  learners listed first in the dashboard's top 10), Principal Students
  (Monitoring column), Student Detail (Overall Monitoring card + resolved
  history count), Principal Dashboard (Learners For Review + Open Concerns
  cards) and Academic Monitoring's cards. My Students and Submit Report show
  no status and were left alone. `OverallMonitoringClassificationTest` (A-Q)
  is the test of record, including one-query-per-page and the same reading
  on every page.
- The manual Add/Edit Examination Type gap this audit found is closed —
  see "Examination Type" below. The 19 pre-existing no-role Examination
  items were audited and deliberately NOT remapped (awaiting approval).

### Examination Type (ST1 / ST2 / Term Exam) on manual items (2026-09-27)

- **`App\Services\ExaminationRoles`** owns the values (`st1`, `st2`,
  `term_exam` — unchanged), the labels ("ST1 — Summative Test 1",
  "ST2 — Summative Test 2", "Term Exam", as the SSHS ECR words them) and
  per-subject applicability read from the grading profile the way
  `GradingEngine` weighs it: no Examination weight -> no Examination item;
  a catalog row's own role shares -> those roles (TE-only subjects);
  otherwise DO 015 -> all three; DO 8 (explicit k12_2013) -> none (one flat
  Examination). `GradingEngine` was NOT changed.
- **Add / Edit** (`Adviser\AssessmentController::examRoleProblem()`,
  server-side): only Examination carries a type; a DO 015 Examination needs
  one of its profile's roles; one REGULAR item per type per subject,
  section, term and year (an additional-support item may share a role — the
  engine sums it in). Edit now corrects Component and Examination Type IN
  PLACE (same id, never a second item); leaving Examination clears the
  type; the rules run only when the classification changes, so a legacy
  no-role item can still have its scores edited. The item name is never
  editable and never the identity.
- **Uploads:** `AssessmentItemConflictDetector::targets()` — a column lands
  on the item with its name, else (a regular Examination column) on the one
  regular item holding its role. A manual "Summative Test 1" and a Grade 12
  ECR "ST1" column are the same ST1. Two columns claiming one type, or two
  stored holders of it, are conflicts shown on Verify (`'slot' => true`).
  `import()` writes to exactly those targets.
- The Add modal's placeholder is "e.g. Quiz 1, Activity 1, Performance Task
  1" (no longer "Remedial Quiz 1"); the Assessment Record comments each
  typed exam header with its type. `ExaminationTypeTest` is the test of
  record.

### Subject-first monitoring and the attendance retirement (2026-09-27, professor's pre-final feedback)

- **Subject first, never the overall average.** `OfficialFailingSubjectRule`
  ("Subject Performance Concern") opens one concern per learner per subject
  per term whose OFFICIAL grade is Failing — exactly the existing rule
  (`Grade::scopeFailing()` / `InTermStatusService::isFailing()`: verified,
  not provisional, at or below `FAILING_THRESHOLD` = 74, DepEd's failing
  mark). No new threshold. `context_key` = `subject:{id}:term:{n}:{year}`.
  High grades in other subjects never hide or resolve it; it resolves only
  when that subject's official grade becomes passing or stops being
  official. An unverified/provisional grade, and an in-term Below Target
  reading, open nothing (in-term trigger: NEEDS POLICY CONFIRMATION).
- **Sync:** `Grade::booted()` queues `App\Monitoring\OfficialGradeSync` on
  every save/delete; after commit it runs `AcademicMonitoringService::
  synchronizeOfficialGrades()` (this rule only) once per section/term. A
  failure is `report()`ed, never undoes the grade. Live data: the 51
  existing official failing grades are NOT yet opened — `dss:check-
  integrity` 4c reports them and `dss:evaluate-concerns 2026-2027 1` opens
  them once approved (dry run: 51 open, 0 refresh, 0 resolve).
- **`App\Services\SubjectResultSummary::fromGrades()`** — Overall Average
  (official grades, summary only), Passed, Failed, not yet official, and
  the failed subjects. Used by Reports ("Subject Results" column) and
  Student Detail ("Official Subject Results"); Subject Analysis shows
  Failed/Passed per subject and open concerns split Subject Performance /
  Missing. `MonitoringStatus::$details` carries one evidence line per
  concern ("General Mathematics — official grade 72.00 (Failed)").
- **Attendance RETIRED:** routes `adviser.attendance*`, the controller,
  view, sidebar item, `AttendanceConcernRule`, the Apply-to-All JS and
  their tests are gone. History stays: `attendance_records` (12 rows) and
  stored attendance concerns are never deleted, but
  `AcademicConcern::scopeActiveRules()` (enabled rule keys only) keeps them
  out of every classification, count, list, Subject Analysis and
  intervention link. `attendance_monitoring` stays a stored value (one
  historic intervention) shown as "Attendance Follow-up (retired)"; new
  interventions choose from `Intervention::SELECTABLE_TYPES`, and a subject
  is required again. `SubjectFirstMonitoringTest` and
  `AttendanceRetirementTest` are the tests of record.

- **Adviser sees the same truth (demo-readiness pass, 2026-09-27).** Adviser
  > My Students gained a Term selector, an "Official Results" column
  (Overall Average, Passed/Failed, each failed subject — `SubjectResultSummary`)
  and a "Monitoring" column (`MonitoringClassificationService`, with evidence
  lines) plus "N of M For Review". View only: the adviser still cannot review
  a concern or record an intervention (Principal routes, server-side 403).
  `AdviserSubjectFirstVisibilityTest` proves the adviser's figures equal the
  Principal Student Detail's for the same learner. Live sync of the 51
  official failing grades was run 2026-09-27 (backup
  `C:\newxampp\db_backups\naggasican_dss_pre_subject_concern_sync_20260927_171433.sql`):
  51 Subject Performance Concerns (19 learners) + 1 Missing = 20 For Review.

- **Adviser reporting UX (2026-09-27) — supersedes the paragraph above on
  where the adviser sees results.** Adviser > My Students is a CONCISE,
  VIEW-ONLY roster: LRN, Last Name, First Name, M.I., Sex, Monitoring
  (`<x-ui.monitoring-status compact>` — "For Review · N concern(s)" + the
  concern types, no evidence lines), and "View details", which links to
  Submit Report `?period=N#learner-{id}`. No averages, Passed/Failed or
  evidence on the roster. **The adviser edit action is REMOVED** — route
  `adviser.students.update`, `Adviser\StudentController::update()`, the
  modal and its `modal.js` handler: learner master data is Admin-only
  (add, import, edit, remove). Submit Report gained **Official Term Results
  — Term N** (term selector, one row per learner, `id="learner-{id}"`):
  Overall Average, Passed, Failed, every failed subject as
  "Subject — grade — Failed", "N not yet official", and the full Monitoring
  evidence — `SubjectResultSummary::fromGrades()` and
  `MonitoringClassificationService::forStudents()`, the same calls the
  Principal's Student Detail makes; no formula in Blade. Its old unused
  `has_failing` (grade < 75 on ANY grade, verified or not) was deleted.
  Submitting a report is unchanged. `AdviserReportingUxTest` and
  `AdviserSubjectFirstVisibilityTest` are the tests of record.


