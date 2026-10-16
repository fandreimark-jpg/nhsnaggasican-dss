# Subject Teacher, Class Record, Encode/Verify split and final audits (2026-09-28 to 2026-09-30)

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 3836-4218; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

---

## Subject Teacher and Class Record (2026-09-28) — standing conventions

Four roles now: Admin, Adviser, **Subject Teacher** (`users.role =
subject_teacher`, migration `2026_09_30_000001`), Principal. `User::ROLES`
is the one sign-in allowlist (`LoginRequest`, `EnsureAccountIsActive`);
`role:` middleware accepts several roles (`role:adviser,subject_teacher`).

- **Advisory and teaching are separate.** `sections.adviser_id` (the section
  row is year-specific) lets the adviser READ every applicable subject of
  that section. **`teaching_assignments`** (user, section, subject,
  school_year, grading_period; unique per section+subject+term — one teacher)
  is the ONLY thing that grants writing assessment evidence or typing a
  grade. `App\Services\TeachingAccess` is the one place this is decided
  (`canEdit`, `canRead`, `readableSections/Subjects`); every write path
  (Class Record save, item store/update, detect/preview/import, Encode
  Grades store/import) and every read path (Class Record, export, copy,
  Download Assessment Record, item history, learner report) calls it. An
  Adviser account can also hold assignments, in any section.
- **Nothing was backfilled.** The migration assigns nobody; Admin >
  Teaching Assignments lists "recorded evidence with no teaching
  assignment" (with who entered it, for reference only). On the live
  database after this pass NO assignment exists, so no one can edit scores
  until the Admin records them. Assignments of a year before the active
  one are not changed (historical).
- **Grade verification stays advisory** (unchanged): Verify / Verify All
  confirm the engine's computed grade and are still the adviser's action;
  only TYPED grades (Encode Grades store/import) need an assignment. Whether
  a subject teacher should verify their own subject is a school decision.
- **Class Record** (`teacher.class-record`, `Teacher\ClassRecordController`,
  `ClassRecordService`) is the score-entry workspace: learners × items,
  grouped WW/PT/EX, computed columns from `GradingEngine` only (no browser
  math). Save is one JSON batch, ALL OR NOTHING, validated inside a
  transaction with the items locked; every cell carries the value it was
  loaded with, and any cell whose stored value differs is a 409 conflict —
  never a silent overwrite. Cell grammar (`resources/js/class-record-core.js`,
  tested by `tests/js/class-record.test.mjs`): number (0 included) =
  Completed, empty = No Evidence Yet, `M`/`MISSING` = explicit Missing.
  Paste of a block is previewed and placed exactly or not at all.
- **Add/Edit Assessment are full pages** (`teacher.assessments.create/
  edit`) that post to the unchanged `adviser.assessments.item.store/
  update`; the modals are gone. Create is idempotent per form
  (`submission_token`, cached); Edit carries `loaded_fingerprint`
  (`ClassRecordService::itemFingerprint()`) and refuses a stale form;
  lowering the HPS below a kept score is refused. `assessments.
  assessment_date` is new, nullable, never backfilled.
- **Evidence history**: `assessment_evidence_audits` (no FKs, append-only),
  written by `AssessmentScore`/`AssessmentLearnerStatus` model events
  through `App\Services\EvidenceAudit` — every path, imports included.
  Score and marker deletes must be MODEL deletes (`->get()->each->delete()`),
  never query-builder deletes, or they escape the history. No reason is
  required (no correction policy requires one); a caller may pass one.
- **Exports**: "Download Excel Class Record" (`ClassRecordExport`) is a
  labelled SYSTEM export, not the DepEd ECR template — values only, no
  formulas, LRN as text, every text cell explicit STRING (formula-safe),
  Missing = text `MISSING` shaded. "Copy Scores" is TSV with the same
  tokens and an apostrophe before any text starting `= + - @`. Mapping into
  the official ECR workbook is PENDING (10 WW/10 PT/3 EX column limit and
  XLOOKUP round-trip unverified).
- **Advisory Overview** (`adviser.advisory-overview`) and the printable
  **learner report** (`teacher.learners.report`, historical section via
  `Student::sectionFor()`) read `LearnerResultsService`,
  `SubjectResultSummary` and `MonitoringClassificationService` — no new
  rule, threshold or severity. A subject teacher's report shows only their
  subjects; no internal notes appear in it.
- Legacy tests written on "the adviser encodes their own section" opt into
  `Tests\Concerns\AdvisersTeachTheirOwnSections` (records each section's
  adviser as its teacher before each request). Authorization tests never
  use it. `RoleAccessMatrixTest` now reads each route's own `role:`
  middleware and covers the `teacher/` prefix and the Subject Teacher.
- **Not verified in a browser** in this pass (no isolated served
  environment; see the artisan-serve note above). Backup before the
  migrations: `C:\newxampp\db_backups\naggasican_dss_pre_teacher_classrecord_20260928_125741.sql`.

## Class Record refinements (2026-09-28, later) — the FINAL assessment workflow

Supersedes, in the "Subject Teacher and Class Record" section above: the
teacher assessment upload, score entry on the Add Assessment page, and
"Remove" as the only assignment action.

**Workflow (Adviser AND Subject Teacher alike):** Admin assigns a teacher
to subject/section/year/term → the teacher opens the Class Record
(`teacher.class-record`; My Classes lists every assignment, including
sections outside an adviser's own) → **Add Assessment** (details only) →
the column appears → scores are typed/pasted into the grid → **Save
Scores** → Download Excel Class Record / Copy Scores.

- **Teacher/adviser assessment FILE UPLOAD is RETIRED.** Routes
  `adviser.assessments.detect/preview/import/record` and the score-carrying
  `adviser.assessments.item.store/update` are gone (a direct request or a
  file staged before the retirement gets 404/405 and writes nothing); the
  verify/preview views and every upload button/modal/text were removed.
  The ECR reader/upload SERVICES stay — Admin's "Import Learners from ECR"
  and `AssessmentUploadService` (still unit-tested) use them.
  `DssAssessmentRecord`, `EcrSubjectTermResolver` and
  `Grade12DiscrepancyChecker` are now unreferenced by any route; kept, not
  deleted (a later cleanup can remove them). Admin learner imports and
  Encode Grades' own grade import are separate functions and unchanged.
- **Add / Edit Assessment are DETAILS ONLY** (`Teacher\AssessmentFormController`,
  `teacher.assessments.store/update`): name, component, Examination Type,
  HPS, date, additional-support flag. A request carrying
  `scores`/`missing` is REFUSED. Creating a column writes no score, zero
  or Missing mark and opens no concern; the success message is
  "Assessment added. Enter scores in the Class Record." Create is
  idempotent per form token. Edit carries `loaded_fingerprint`
  (`ClassRecordService::definitionFingerprint()`, metadata only), locks the
  row, refuses an HPS below any recorded score, and refuses any change of
  subject/section/year/term. It never touches scores.
- **The legacy Adviser > Assessments page is RETIRED** (superseded by the
  "Class Record consolidation" section below): `GET /adviser/assessments`
  only redirects; verification moved to Encode & Verify Grades.
- **Class Record layout**: identification header (year/term, grade and
  section, subject, assigned teacher, configured grading profile); each
  component's Total / PS / WS sits beside its own items; then Initial,
  Transmuted, Official and an Evidence status. No item cap. Computed cells
  are text formatted by `ClassRecordService::displayRow()` — the page and
  the save response use the same strings; the browser never calculates.
  A Save/Discard/Cancel guard covers leaving, changing the class, Add
  Assessment, Download and Copy while edits are unsaved. Copy has two
  modes: full (header + HPS + LRN/name rows; LRN sent as `'0…` so Excel
  keeps it as text) and scores-only.
- **Drafts** (`resources/js/class-record-drafts.js`): key
  `cr-draft:{user}:{year}:{section}:{subject}:{term}`; other accounts'
  and >24 h drafts are purged whenever a Class Record opens; all drafts are
  purged on logout and on the sign-in page; a read-only view (assignment
  removed/replaced, term closed) and a 403/423 save delete that class's
  draft. The server re-authorizes every save regardless.
- **Teaching assignments**: Edit / Change Teacher (`admin.teaching-
  assignments.edit/update`) — reason required, active Adviser/Subject
  Teacher only, row locked and version-checked (a stale form is refused),
  atomic. Evidence, `uploaded_by` and audit actors are never rewritten;
  the new teacher gains access at once and the old one loses it (old tabs
  get 403). Section/subject/term can be corrected only while the class has
  NO assessment evidence; other terms are never altered. A closed term is
  not reopened. Remove requires a reason and never touches evidence.
  Every assign / replace / correct / remove is recorded in
  `teaching_assignment_history` (migration `2026_09_30_000005`, backfilled
  only from existing rows' own `assigned_by`/`created_at`). Past school
  years stay locked.
- **Browser testing is isolated by construction** (`tests/Browser/`):
  `php artisan serve` cannot be isolated here — it passes only an
  allow-list of variables to `php -S`, and with `Env::disablePutenv()`
  and `variables_order=GPCS` even those never reach Laravel, so `.env`
  (the WORKING database) always won. `tests/Browser/router.php` writes the
  overrides into `$_SERVER`/`$_ENV` before boot and refuses every request
  unless the booted app is on `storage/framework/browser-testing/
  browser.sqlite`; `prepare.php` runs `migrate:fresh` only after the same
  assertion. Run: `php tests/Browser/prepare.php`, then
  `php -S 127.0.0.1:8765 -t public tests/Browser/router.php`, then
  `node tests/Browser/run.mjs` (headless Chrome via CDP, no dependencies).
  Never point browser automation at Apache/XAMPP.
- Backup before this pass's migration:
  `C:\newxampp\db_backups\naggasican_dss_pre_classrecord_refinements_20260928_143036.sql`.

## Class Record consolidation (2026-09-28, final) — the Adviser "Assessments" page is retired

No schema or data change. The standalone Adviser "Assessments / Assessment
Evidence" page and its menu item are gone; each capability has one home:

| Old page capability | Now |
|---|---|
| Assessment list, scores, Missing marks | Class Record |
| Examination-Type warnings (untyped / duplicate type) | Class Record |
| Cross-subject evidence for advisory learners | Advisory Overview (with a link per subject to its read-only Class Record) |
| Per-learner component %, In-Term Status, Monitoring, Focus Area | Encode & Verify Grades → "Verify official grades" |
| **Verify & Use as Official / Verify All Remaining** | **Encode & Verify Grades** (`adviser.grades`, `#verify`) |
| Intervention "add a support assessment" link | `TeachingAccess::supportAssessmentLink()` — Add Assessment for the assigned teacher, else the read-only Class Record |

- **Why Encode & Verify Grades:** it is already the adviser-only
  official-grade area, lives in the `role:adviser` route group with
  `adviser.grades.verify` / `verify-all`, and verification is an ADVISORY
  act (the advisory section, `Section::forAdviser()`), not a teaching one.
  Putting Verify on the Class Record would have shown it on a page Subject
  Teachers use. The table moved unchanged into
  `adviser/partials/grade-verification.blade.php`, built by
  `App\Services\GradeVerificationBoard` (moved, not copied, from the old
  controller). Verification rules, prerequisites and audit logging did not
  change; Subject Teachers, Principal and Admin still get 403; nothing
  becomes official by adding an assessment, saving scores, downloading or
  reassigning a teacher.
- `GET /adviser/assessments` (`Adviser\AssessmentController`) only
  REDIRECTS to the Class Record, carrying a subject only if the adviser can
  read it in that section and term and a term only if it exists; never an
  arbitrary URL. The old `add_item` link goes to Add Assessment only for
  the assigned teacher in an open term. POST/PUT/DELETE to that URL are
  405; the retired upload and item-write routes stay gone.
- Encode & Verify Grades still has its separate FINAL-GRADE import
  (`adviser.grades.import`, assignment-scoped, imported grades are NOT
  official until verified). It is not an assessment-score upload.
- Tests of record: `ClassRecordConsolidationTest`; tests that asserted the
  verification table now read `adviser.grades` (same markup, same view
  variables).

## Class Record UI refinement (2026-09-28, last) — standing conventions

UI only: no schema, data, route, grading or authorization change. Tests of
record: `ClassRecordUiTest` (page contract) and `tests/Browser/run.mjs`
(98 checks, isolated server; `tests/Browser/shots.mjs` for screenshots).

- **Page order:** compact filters → context band (access-state badge
  `data-access-state` = editable | advisory | closed, full subject name,
  class, assigned teacher, grading profile) → read-only reason → ONE
  workspace card: toolbar (Add Assessment, Download Excel, Copy Scores +
  mode select, Help toggle) → legend → selected-cell bar → grid → save bar.
- **One Save Scores control**, in `#crSaveBar`: `position: sticky; bottom:
  0` INSIDE `<main>`'s scroller and in normal flow, so at the end of the
  page it sits below the grid's horizontal scrollbar and never covers a
  row. Status `#crStatus` (polite live region) carries `data-state` =
  idle | unsaved | saving | saved | invalid | offline | forbidden |
  conflict; "saved" only after a 2xx. Save and Discard are disabled with
  no changes or while saving; Discard asks first (`#crDiscardModal`). No
  autosave. Read-only views render no save bar and no cell actions.
- **Mark Missing / Clear Entry / Undo act on ONE selected editable cell**
  (`#crSelection`), named as learner · assessment · HPS · current value;
  they only change the pending value ("Pending change — click Save
  Scores."). Clear Entry = blank = No Evidence Yet, never 0. The buttons
  `preventDefault()` on mousedown so the cell keeps focus. No bulk
  actions. Typing M in a cell is the only M shortcut.
- **Focused cells stay visible:** `.cr-cell` scroll-margins clear the
  sticky header/name column, and `ensureVisible()` nudges `<main>` so the
  cell is above the save bar (and the on-screen keyboard via
  `visualViewport`). A 422 / client-side invalid focuses the first bad
  cell. A new column (`data-new-assessment`) is scrolled to and its first
  cell focused.
- **Unsaved and invalid cells carry a non-colour mark** (`td.cr-td-dirty`
  corner, `td.cr-td-invalid` "!"); Missing shows the letter M.
- **"—" explains itself:** `ClassRecordService::displayRow()` adds
  `…:why` keys (hover/assistive `title` via `data-cr-why`, refreshed by the
  save response). They are read from the engine's output and the items,
  never a second calculation — e.g. an Examination with typed items names
  the type with no score ("Needs a score in every Examination Type …").
  Headers: PS = Percentage Score, WS = Weighted Score; the HPS row's
  "All items N" is every item, the learner's Total is scored items only.
- **Wide layout opt-in:** a page can `@section('wide_content', '1')` to
  widen `layouts/app`'s container from `max-w-6xl`; only the Class Record
  uses it.
- Encode & Verify Grades now titles its two jobs separately ("Manual grade
  entry — Term N", not official; "Verify official grades"). Wording states
  that correcting an already-verified grade keeps it verified — that is
  what `GradeController::store()` does.
- `x-stat-card`'s `icon` is the name WITHOUT `bi-` (Advisory Overview had
  passed `bi-…` and rendered empty icon boxes).
- **Known, not changed:** the fixed mobile menu button (`layouts/app`)
  floats over the top-left of scrolled content at phone width on every
  page; real-device keyboard/screen-reader testing has not been done.

## Encode / Verify split (2026-09-28, last) — two adviser pages

- **Encode Grades** (`adviser.grades`, GET `/adviser/grades`): manual final-grade
  entry + the final-grade import only. Typed grades need a teaching assignment and
  are "Not yet official". Correcting an ALREADY-verified grade here keeps
  `is_verified = true` with the typed value (existing behaviour, unchanged — a policy
  question). Carries a narrow client-side redirect for old `#verify` bookmarks to a
  server-validated Verify Grades URL.
- **Verify Grades** (`adviser.verify-grades`, GET `/adviser/verify-grades`,
  `GradeController::verification()`): ONE workspace — filters (School Year / Section
  limited to sections this adviser advises, Term, Subject, Status), Open Class Record,
  counts, scoped bulk action, the Student Performance table with per-row state from
  `GradeVerificationBoard` (`verify_state`: eligible / verified / verified_changed /
  provisional / no_transmutation / incomplete + specific reason). Anything other than
  the adviser's current section in a writable term is read-only.
- POST endpoints unchanged (`adviser.grades.verify`, `.verify-all`, `.verify-all.preview`).
  Additions: a repeated individual verify with unchanged figures writes nothing and logs
  nothing ("Already verified … Nothing changed"), under a learner row lock; bulk locks
  the class's learners, re-classifies, and returns 409 (verifies nothing) when
  `expected_student_ids` from the confirmation differs from the current eligible set.
  Bulk scope is the WHOLE class, not the Status filter. Redirects return to Verify
  Grades with period/subject/status and `#learner-{id}`.
- `resources/js/grade-verify.js` and `adviser/partials/grade-verification.blade.php`
  were removed; individual verify posts through the neutral action-confirm modal.
- Tests of record: `EncodeVerifyGradesSplitTest` (10). Suite 1,206 (101 Unit + 393 /
  219 / 234 / 259 Feature) + 15 JS + 115 isolated browser checks.

## Mobile header, drawer and official-grade correction audit (2026-09-28, follow-up)

Verified: build OK; `node --test tests/js/*.mjs` 15/15; Laravel 1,207 (101 Unit +
393 / 220 / 234 / 259 Feature, 9,841 assertions); `git diff --check` clean;
`dss:check-integrity` clean, no pending migrations; isolated browser harness
160/160 (drawer keyboard/Escape/overlay/resize, a fixed-over-content audit at
top/middle/bottom on nine pages at 390/768/1366/200%, Class Record at 390, and
learner-report print-to-PDF). In the harness, a keyboard Enter must be sent
with `text: '\r'` or Chrome never clicks the focused button.

- **Mobile header (shared layout).** The old `md:hidden fixed top-4 left-4`
  floating menu button sat outside the layout and covered the top-left of
  scrolled content on every page. `layouts/app` now wraps the content column in
  `#appContent` (flex column): a `md:hidden` header (`[data-mobile-header]`, in
  normal flow, h-14) holds `#sidebarToggle` (aria-controls/aria-expanded), and
  `<main>` scrolls below it. The `pt-10 md:pt-0` spacer is gone. Desktop is
  unchanged (the `FixedSidebarLayoutTest` strings are kept).
- **Drawer behaviour** (`resources/js/modal.js`, `toggleSidebar`/`closeSidebar`
  kept): below md an open drawer is a modal dialog (role/aria-modal, focus moved
  in, `#appContent` inert, Escape/overlay close, focus returns to the trigger);
  a CLOSED drawer is inert so its off-screen links are not Tab stops; crossing
  the md breakpoint resets to a closed, non-inert state.
- **Correction of an already-official grade — existing policy unchanged**:
  Encode Grades (store and import) needs the adviser of the section who is also
  the subject's assigned teacher; no reason is asked; the row stays
  `is_verified`, `verified_at`/`computed_grade` untouched; concerns re-evaluate
  from the new value. ADDED: `Grade::logOfficialCorrection()` writes a
  `correct_official_grade` activity-log entry with both values (before: only
  "Encoded N grade(s)"). Encode Grades marks official values "Official".
  Verify Grades' Re-verify reason now distinguishes "the evidence changed" from
  "the official value was changed after verification" (it used to say the
  evidence changed in both cases). Whether a correction should stay official or
  need renewed verification, and whether a reason should be required, are the
  school's decisions.
- Learner report print CSS: cards may break across pages; rows never split;
  table headers repeat (`thead { display: table-header-group }`).

## Release-readiness pass (2026-09-28, final) — standing conventions

Materials: `RELEASE_READINESS_2026-09-28.md` (roles, rules, demo, UAT, policy
decisions, Railway checklist, backup/recovery). Suite 1,212 (101 Unit + 394 / 220 /
238 / 259 Feature, 9,946 assertions) + 15 JS + 160 isolated browser checks.

- **A role change may not strand current responsibilities.** `Admin\UserController::
  update()` refuses to move an account off `adviser` while it advises a section of the
  active year or later, and off a teaching role while it holds current teaching
  assignments; past years never block. Role changes are logged (`change_user_role`).
  `dss:check-integrity` 4d/4e report existing stranded sections/assignments (found live:
  Shakespeare's adviser account is a Subject Teacher) — reported, never auto-reassigned.
  `ReleaseReadinessGuardsTest`.
- `DemoFlowEndToEndTest` is the demo of record (steps A-O + replacement + closed term).
- `phpunit.xml` sets `LOG_CHANNEL=null`: error-path tests used to write `testing.ERROR`
  entries into the operational `storage/logs/laravel.log`.
- Security patch updates applied within existing constraints (maatwebsite/excel 3.1.70,
  league/commonmark 2.10.3, guzzle 7.15.5, psr7 2.13.1 + transitive patch bumps);
  `composer audit` clean, `npm audit --omit=dev` clean (1 low dev-only esbuild).
- Attendance is LEGACY/ARCHIVE: table + model + migration remain, no writer, no rule,
  0 working rows (the 12 historical rows were removed by the 2026-09-27 demo reset and
  are preserved in `naggasican_dss_pre_demo_reset_20260927_183317.sql`).
- Docker image NOT built (no Docker on the dev box). Recovery is restore-from-dump,
  never `migrate:rollback` (recent `down()`s drop real data tables).

## Final end-to-end audit (2026-09-28, last) — standing conventions

Report: `FINAL_AUDIT_2026-09-28.md`. Suite 1,218 (101 Unit + 394 / 226 / 238 / 259
Feature, 10,000 assertions) + 15 JS + 160 browser checks + `crawl.mjs` (232 URLs, 4
roles) + `netfail.mjs` 15/15.

- **A save is "Saved" only when the save endpoint confirms it.** `EnsureAccountIsActive`
  answers JSON requests with 401 JSON (never a redirect a fetch() would follow to a 200
  login page); `class-record.js` requires `response.ok && !response.redirected &&
  typeof data.changed === 'number'`; 401/419 say the session ended and keep entries.
- **Idempotent retry:** `ClassRecordService::saveBatch()` treats a cell that already
  holds the requested value as a retried request (not a conflict); a different value on
  a stale base is still 409.
- **Row locks must be the transaction's FIRST read** (MySQL REPEATABLE READ fixes the
  snapshot at the first plain read). Bulk verify reads its roster before the transaction;
  a unique-key collision is a 409 "changed", and no SQL text reaches users. Every other
  `lockForUpdate()` already locks first — keep it that way.
- **Isolated MySQL harness:** `BROWSER_ISOLATION=mysql` targets only
  `naggasican_dss_loadtest` (guard refuses anything else); `loadtest.mjs` (bounded load)
  and `verify-race.mjs` (concurrent verification) run against 4 `php -S` servers on
  8781-8784. SQLite remains the default for `run.mjs`/`netfail.mjs`/`crawl.mjs`.
- `.tbl-scroll` is a size container; an empty state inside a wide table cell is sticky
  to the visible width. Principal Interventions renders its empty state outside the table.
- Verify Grades filters are two per row below `xl`.
- `Grade12DiscrepancyChecker` was removed (zero references).

## Final demo audit (2026-09-30) — standing conventions

Report: `FINAL_DEMO_AUDIT_2026-09-30.md`. Suite 1,228 (101 Unit + 396 / 232 / 238 / 261
Feature) + 15 JS + 160 browser + `crawl.mjs` 218 URLs + `netfail.mjs` 15/15.

- **Every spreadsheet import reads through `ValidatesSpreadsheetUpload::
  importSpreadsheetOrRefuse()`**, never `Excel::import()` directly. Validation is by
  extension, so a corrupt `.xlsx` reaches the reader. Admin › Students › Import and
  Encode Grades › Import answered HTTP 500 until this pass (`UnreadableSpreadsheetImportTest`).
- **Every text cell written to a CSV/TSV/xlsx goes through `ClassRecordService::safeText()`**
  (or an explicit STRING cell). The grade template and the Admin rejected/draft roster
  CSVs used to write names raw. `GradesImport` reads back a `'`-escaped subject header
  (`CsvFormulaInjectionTest`).
- `IndependentGradingFixturesTest` is the grading oracle across profiles. Its expected
  values are hand-calculated and its transmuted grades are hardcoded from the DepEd
  tables — never read back from the engine or `transmutation_ranges`.
- **OPEN, policy (D-01):** a typed correction keeps a grade official. The only Failing
  rule is `<= 74.0`, so an official 74.01–74.99 counts as Passed and opens no concern,
  while Reports shade `< 75` red and `RecomputeGradesCommand` uses `>= 75`. Transmuted
  grades are integers, so only typed/imported corrections reach this. Needs the
  school's decision; not changed.
- Laravel-Excel leaves its temp copy in `storage/framework/cache/laravel-excel/` when a
  read throws (test runs included). Not web-exposed; not yet pruned.

