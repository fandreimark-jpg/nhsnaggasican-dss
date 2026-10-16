# Audits, hardening, performance and deployment notes (2026-09-17 to 2026-09-27)

> **HISTORICAL ARCHIVE — not standing instructions.** Moved verbatim from the root
> `CLAUDE.md` on 2026-10-04 (source lines: 2671-2738 2861-3188 3294-3405 3764-3835; byte-identical backup:
> `backups/claude-md/CLAUDE.md.bak_20261004_110603`). Text below is unedited, including passages it marks itself as
> SUPERSEDED, old test counts, and old audit results — none of those were re-run
> for this archive. Where anything here disagrees with the root `CLAUDE.md` or the
> code, the root `CLAUDE.md` and the code win; report the disagreement.

---

## Pre-demo audit pass (2026-09-17) — standing conventions it added

Full findings and evidence are in `PRE_DEMO_FINAL_AUDIT.md`; these are the
rules that stay true after it.

- **One favicon source: `<x-app-favicon />`** (`resources/views/components/
  app-favicon.blade.php`). Every layout — `layouts/app`, `layouts/guest`,
  `auth/login`, `errors/layout` — includes it; none carries its own
  `<link rel="icon">`. The icon files (`public/images/favicon-32.png`,
  `favicon-192.png`, `apple-touch-icon.png`, and a real 32x32 PNG-in-ICO
  `public/favicon.ico`) are derived from `public/images/nagga-logo.png`;
  regenerate all four from that file if the logo ever changes. Before this
  pass only the login page declared an icon and `favicon.ico` was a
  zero-byte placeholder, so the tab icon vanished on sign-in.
  `FaviconConsistencyTest` pins one identical declaration on every layout.
- **Error pages live in `resources/views/errors/`** (`403`, `404`, `419`,
  `500`, `503`), all extending `errors/layout` — a self-contained shell
  that deliberately does NOT extend `layouts/app` (which needs
  `auth()->user()` for the sidebar). Add a new HTTP error page there, never
  by reaching for the app layout. `ErrorPagesTest` is the test of record.
- **Edit-modal form actions are route-derived, never hardcoded.** Every
  Edit form carries `data-update-url="{{ route('x.update', ['id' =>
  '__ID__']) }}"` and `resources/js/modal.js` resolves it through
  `window.updateUrlFor(form, id)`. The old ``form.action = `/admin/users/${id}` ``
  pattern posts to the wrong host root under the XAMPP sub-directory
  deployment (`http://localhost/naggasican-dss/public/`) and must not come
  back — `EditFormActionsAreRouteDerivedTest` greps `modal.js` for it.
- **Abandoned temp uploads are pruned on the next upload.**
  `App\Services\TempUploadPruner::prune($dir)` runs at the start of
  `Adviser\AssessmentController::detect()` and `Admin\StudentController::
  importFromEcrPreview()`, removing files older than 24 h from that flow's
  own `storage/app/private/temp_*` directory. No scheduler is involved on
  purpose (none exists in this deployment). A third multi-step upload flow
  must call it too.
- **`tests/TestCase.php` fakes the `local` disk for every test.** Upload
  tests used to write real files into `storage/app/private/temp_*` on each
  run (830 of them had accumulated). A test that genuinely needs the real
  disk must opt out explicitly and say why.
- **Classifier output is validated as a whole before any `RiskResult` is
  written** — `Adviser\ReportController::classifierOutputIsValid()`
  (public, like `buildPythonPayload()`/`applyFailingSubjectOverride()`,
  so it is unit-testable without a fake interpreter). `confidence`, when
  present, must be numeric in 0–100; any defect fails the whole analysis
  and the adviser sees the existing "risk analysis failed to generate"
  warning. Never persist a partial result set.
- **`exam_role_shares` 30/30/40 is the FALLBACK, not "provisional data
  nobody checked."** The per-subject shares live on `deped_subject_catalog`
  (from DepEd's own ECR workbook) and win first; the table is reached only
  for a `do015_2026` subject with no catalog link. The seeder's docblock
  now says exactly this — the older "PROVISIONAL" wording in git history
  is superseded. The remaining, honest limitation is that neither source
  has been checked against the signed PDF of DO 015, s. 2026.
- **No `{!! !!}` in `resources/views`** — the last one (Admin Data Health
  panel, static strings only) was converted to `{{ }}`; keep it at zero.
- **`public/robots.txt` disallows everything.** Nothing here is public
  content; error pages also carry `<meta name="robots" content="noindex">`.
- **Every long-running form names its own loading label** via
  `data-loading="…"` (Signing in…, Importing sections…, Validating E-Class
  Record…, Recording intervention…); the generic "Processing..." fallback
  is for forms nobody has thought about yet, not a target state.
- Removed as verified-dead (zero `<x-…>` usages anywhere in `resources/`,
  `app/`, `tests/`): the Breeze leftovers `components/{danger-button,
  dropdown, dropdown-link, modal, nav-link, responsive-nav-link,
  secondary-button, application-logo}.blade.php` and
  `app/View/Components/AppLayout.php` (every page uses
  `@extends('layouts.app')`, never `<x-app-layout>`). `GuestLayout.php`
  stays — four auth views use `<x-guest-layout>`.


## Pre-demo full-system audit (2026-09-20) — standing conventions it added

Findings and evidence are in the audit report delivered with the pass;
these are the rules that stay true afterwards.

- **`RoleAccessMatrixTest` is the authorization test of record.** It walks
  the live route table: every GET route under `admin/`, `adviser/` or
  `principal/` must answer its own role (200/302/404), 403 to the other
  two, and redirect a guest AND a disabled session to login; a second
  test probes cross-object mutations (adviser B against adviser A's
  learner, assessment, intervention; adviser/principal against Admin
  mutations; unsigned access to Laravel's local-disk serve routes). A new
  role-prefixed route that forgets its middleware fails here, so do not
  hand-list routes in it — it reads `Route::getRoutes()`.
- **Every page that can receive a default-bag validation error renders
  `partials/validation-errors`** (Adviser Assessments/Grades, Admin
  Tracks/Specializations, Principal Interventions; Admin Subjects has its
  own equivalent). Before this, a rejected upload type, duplicate track
  or out-of-range grade bounced back with no message — the layout renders
  only the `deletion` key. A new page with a form includes the partial;
  named bags (`addItem`, `editItem`) stay with their modals.
- **An unreadable spreadsheet is a controlled refusal.** `spreadsheetFileRule`
  validates by extension on purpose (see the `mimes:` section), so a
  corrupt/truncated `.xlsx` reaches the reader; `Adviser\AssessmentController::
  detect()` catches `PhpOffice\PhpSpreadsheet\Exception` (and `ValueError`)
  around the identity check and column detection, deletes the temp file
  and flashes "could not be read as a spreadsheet". It used to be a 500.
- **Two invariants gained DB constraints** (`2026_09_20_000002`): unique
  `subjects (name, grade_level)` and unique `sections (name, grade_level,
  school_year)`; `Admin\SectionController` now validates the latter too
  (no rule existed — a duplicate "Curie" in one year was accepted). The
  migration refuses to run if duplicates exist rather than failing
  half-way. `tracks` keeps its form-only rule: `TrackFactory` uses a
  constant name/code and tests create several.
- **Breeze's email-verification, confirm-password and `PUT /password`
  routes are gone** (controllers, views, the two orphan
  `profile/partials/*` and their tests). `User` never implemented
  `MustVerifyEmail`; the profile modal (`profile.update`,
  `profile.password.update`) is the only account-editing surface. The
  forgot-password routes remain (guest-only, `MAIL_MAILER=log` locally —
  nothing links to them; an Admin resets a password from Users).
- **`PreventBackHistory` also sets `X-Frame-Options: SAMEORIGIN`,
  `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin`.**
  The app embeds no frames.
- **Vite: `public/hot` must not exist when the demo runs.** With it
  present every page loads assets from the dev server (`[::1]:5173`) and
  renders unstyled if that server is down. Stop `npm run dev` and delete
  the file; `public/build` is what the demo serves.
- **The live Term 1 reports of 2026-09-19 had no risk results** — both
  Submit Report runs that evening logged `Analytics process failed
  {"exit_code":1}` while the ML refactor was in progress (the failure was
  logged before stderr capture existed). Re-submitted through the real
  workflow on 2026-09-20 after a backup: 84 rows. `RiskResult` being
  empty while `report_submissions` exist is the signature to look for.

## Final pre-demo audit (2026-09-20, evening) — standing conventions it added

Second full-system pass, run over real HTTP against Apache and the live
database (backup `backups/backup_20260920_final_audit_pre.sql` first).
These are the rules that stay true afterwards.

- **The test suite refuses to run against anything but sqlite `:memory:`.**
  `tests/TestCase::setUpTraits()` checks the connection BEFORE
  `RefreshDatabase` fires and exits 255 otherwise. Reason: a
  `vendor/bin/phpunit --no-configuration` invocation during this audit
  skipped `phpunit.xml`'s `<env>` block and `migrate:fresh` emptied the
  LIVE `naggasican_dss` database (restored losslessly from the backup
  above — every academic table matched the snapshot). Always run tests
  as `php artisan test` or `vendor/bin/phpunit -c phpunit.xml`, take a
  mysqldump first, and never remove that guard.
- **`profile/_modal.blade.php` reads NAMED error bags only** (`profile`,
  `profilePassword`; `ProfileController` uses `validateWithBag()`). It is
  included on every page and used to open on ANY default-bag error and
  call `$errors->only()` — not a `MessageBag` method — so a rejected
  Admin > Users or Admin > Students form (keys `last_name`/`first_name`/
  `email`/`password`) was a 500 (64 log entries since 09-08). `ProfileTest`
  pins both behaviours. A new shared partial must never key its
  open-state off the default bag.
- **A Blade `@json(...)` argument must not contain a comma.** Blade splits
  the directive on commas, so `@json($section->load(["track",
  "specialization"]))` compiled to `json_encode(..., 512)` — no
  `JSON_HEX_APOS`/`HEX_TAG` — and a section name with a quote broke out
  of the `onclick='...'` attribute (a real stored XSS on Admin > Sections;
  the other ten `onclick='fn(@json($x))'` sites are comma-free and safe).
  Eager-load in the controller and pass one variable.
  `XssEscapingAcrossRolePagesTest` renders a markup + quote-breakout
  payload on 19 role pages and statically rejects any comma-bearing
  `@json`.
- **A no-role Examination item is named, not silently dropped.**
  `GradingEngine::examinationPercentage()` excludes an item with no
  `exam_role` once any item carries one (unchanged — DO 015's Examination
  is ST1/ST2/TE). The live demo held 18 "Additional Practice EX" items
  whose scores counted for nothing; Adviser > Assessments now lists them
  in an amber notice (`$unroledExamItemNames`,
  `AssessmentPerformancePageTest`). Grading did not change.
- **A repeat upload says which items already exist.** `import()` was
  already idempotent (item matched by name, scores replaced, blank cells
  keep the old score); the Preview now names the existing items and says
  scores will be replaced (`$existingItemNames`,
  `AssessmentUploadWorkflowTest`).
- **Adviser > My Students renders `partials/validation-errors`** — the one
  form page the 2026-09-20 morning pass missed (a rejected Edit Student
  bounced back silently). `PreDemoAuditRegressionsTest`.
- **`LoginRequest`'s disabled-account refusal redirects to `route('login')`
  explicitly** — `session()->invalidate()` wipes the previous URL, so a
  plain `back()` depended on the browser's Referer header to land on
  /login with the message intact.
- The Admin dashboard's "Import Subjects" quick action (an upload removed
  with the applicability refactor) is now "Manage Subjects".

**Recorded, deliberately NOT changed (business-rule / data questions):**

- `RiskFeatureExtractor::missingAssessmentCount()` counts EVERY item in
  the section/term, including additional-support items only a subset of
  learners were meant to take — every non-remediated Curie learner reads
  27 "missing". The active single-feature prototype ignores it, so no
  live result is affected; a real candidate model would not. Whether an
  additional-support item is "expected" for a learner outside the
  intervention is the school's call. Feature definitions stay frozen
  (`PerformanceBatchEquivalenceTest`).
- The operator created "Philippine History and Society" (Grade 11 core,
  all three terms) at 15:33 on 2026-09-20, AFTER both Term 1 reports were
  submitted. Every Grade 11 section now expects 168 grades for Term 1,
  has 126, and Submit Report refuses a re-submit ("42 grade(s)
  remaining") — correct behaviour, wrong demo state. Upload its ECR for
  both sections, or narrow/remove the subject (it has zero records),
  before the demo. Its catalog title is "Pag-aaral ng Kasaysayan at
  Lipunang Pilipino", so it is not catalog-linked either.
- The two live Grade 11 sections carry `specialization_id` (HUMSS/STEM)
  with `curriculum = NULL`. SSHS has no strands; harmless today (no
  Grade 11 electives exist, and `usesTrackElectives()` only matters for
  electives) but it should be cleared or the curriculum set explicitly.

## Pre-demo hardening, Phase 1 (2026-09-21) — the local Apache serves ONLY `public/`

The Term 1 experiment is frozen (authoritative backup
`C:\newxampp\db_backups\naggasican_dss_FINAL_TERM1_FROZEN_PASS_20260921_143408.sql`,
outside the web tree; the copy in `backups/` is byte-identical). This pass
touched no database row, no model artifact and no seeder.

- **Before:** XAMPP's `DocumentRoot` is `C:/newxampp/htdocs` with `Options
  Indexes` and `AllowOverride All`, no vhost, no project-root `.htaccess`.
  Verified with curl against the running server: `/naggasican-dss/.env`
  (the whole file), `storage/logs/laravel.log` (17.7 MB), every
  `backups/*.sql` including the frozen dump, the learner mapping CSV,
  `.git/config`, `analytics/model_cache.pkl`, and directory listings of
  every folder all answered **200** — on localhost, on the LAN address
  and over HTTPS (443 is also listening).
- **After:** `C:\newxampp\apache\conf\extra\httpd-naggasican-dss.conf`,
  included from `httpd.conf` (three added lines, right after the
  `httpd-xampp.conf` include; the prior file is
  `httpd.conf.bak_20260921_pre_dss_hardening`). It denies the whole
  project directory at the Apache level (`Require all denied`,
  `AllowOverride None`, `-Indexes`) and re-grants only
  `naggasican-dss/public` (`AllowOverride All` so Laravel's own
  `public/.htaccess` rewrite still works). `/naggasican-dss/` 302s to
  `/naggasican-dss/public/`. It also denies `.zip/.rar/.7z/.sql/.bak/.env`
  anywhere under htdocs, because eleven `naggasican-dss*.zip` archives of
  earlier project copies — each holding a `.env` and up to ~40 SQL dumps —
  sit beside the project and were downloadable. Every sensitive path
  above now answers **403**; the app, its built assets and the XAMPP
  dashboard are unaffected.
- **This is the demo box's configuration, not the repository's.** It is
  lost if XAMPP is reinstalled or `httpd.conf` is replaced. Re-check with
  `curl -sI http://localhost/naggasican-dss/.env` (expect 403) before any
  demo. The Docker/Railway deployment already serves `public/` as its root
  and needs none of this.
- Apache here runs as a console process (no `Apache2.4` service), so
  `httpd -k restart` does nothing; a graceful restart is the XAMPP control
  panel, or signalling the parent's `ap<pid>_restart` event.
- `.gitignore` now excludes root-level `*.csv`: the intervention/remedial
  mapping export carries LRNs and names and must never be committed.
  Fixture CSVs live under `database/seeders/` and `tests/Fixtures/`.

## Pre-demo hardening, Phase 2 (2026-09-21) — a repeat upload can no longer rewrite an item's classification silently

**Root cause.** `AssessmentUploadService::import()` matches an item by
(subject, section, grading period, school year, name) and
`updateOrCreate()`s it with the confirmed mapping — and `component`,
`exam_role`, `is_additional_support` and `max_score` were all in the
UPDATE array. That is what makes a corrected E-Class Record re-uploadable
(scores replaced in place, no duplicate items), but it also meant a
second upload that classified "Summative Test 1" as ST2, or moved an item
from Written Work to Performance Task, or changed its max, rewrote the
stored item with nothing on screen saying so — every recorded score under
it silently re-weighted. The Preview named which items already existed;
it never compared what they were. (`storeItem()`, the manual path, was
never affected: it refuses any same-name item outright.)

**The rule now — `App\Services\AssessmentItemConflictDetector`.** One
place. For each column in the confirmed mapping whose name matches an
existing item in the SAME scope (name compared case-insensitively — the
live MySQL collation is case-insensitive, so that IS the identity rule
`updateOrCreate()` resolves to), the four PROTECTED fields are normalised
(trimmed lower-case strings; a real boolean; max score rounded to the two
decimals the column stores; a non-Examination column carries no role)
and compared. Any difference is a conflict, reported with the item name,
the field, and BOTH values in words (`Written Work`, `Summative Test 1`,
`Term Exam`, `Yes`/`No`, `50.00`) — never a raw enum. Identical metadata
is not a conflict; a new item is not a conflict; an identically named
item under another subject/section/term/year is never loaded. A
component conflict is reported once — the exam-role difference it implies
is not a second finding. Nobody's side wins automatically: not the stored
item, not the file.

**Enforced three times, on purpose.** (1) `preview()` refuses the mapping
and re-renders the VERIFY screen (`verifyScreenWithConflicts()`) from the
same stored file with the adviser's own selections restored — component,
role, max, checkbox — the conflicting rows highlighted and a red,
non-dismissible notice listing every conflict; correcting and pressing
Preview again is the whole retry. (2) `import()` re-runs the check before
`AssessmentUpload::create()` and flashes the same wording
(`metadataConflictRefusal()`) — the crafted-request / stale-preview
path. (3) `AssessmentUploadService::import()` re-runs it INSIDE its
transaction, before the first `updateOrCreate()`, and throws
`App\Exceptions\AssessmentMetadataConflictException`; the controller now
wraps upload-record creation + import + status update in ONE outer
transaction (the service's nests as a savepoint), so a refused import
leaves no item, no score and no `pending_review` upload row behind.
`columnMappingFromRequest()` is the one builder both `preview()` and
`import()` use — `preview()` used to build its mapping without
`is_additional_support`, so the dry run and the write could disagree.

**What did not change.** Grading, `GradingEngine`, the ML payload, the
score-replacement semantics of a legitimate re-upload (identical metadata
→ scores replaced, blank cells keep the old score, `import_batch_id`/
`uploaded_by` move to the new upload), and every existing safeguard
around it. `RepeatUploadMetadataConflictTest` is the test of record (10
tests: the allowed cases, each protected field, the un-previewed import,
the service-level throw with no partial writes, scope isolation, the
detector's normalisation and wording). Suite: 1,081 (1,071 + 10).

## Performance audit (2026-09-19) — standing conventions it added

Measured first (an in-process HTTP harness over a throwaway copy of the
live database, counting SQL, duplicate SQL, memory and Python launches per
request), then fixed. Full figures are in the pass's report; these are the
rules that stay true afterwards.

- **`GradingEngine` reads evidence ONCE per (section, term, school year)
  per instance, never once per `computeGrade()` call.** `evidenceFor()`
  loads every assessment item in that scope and every score against them
  (two queries) and casts `score`/`max_score` to float once at load time;
  `componentPercentage()`/`examinationPercentage()` filter that in memory
  with the exact predicates the per-call queries used. Before this,
  `computeGrade()` ran ~7 queries per call and the Principal dashboard
  made 1,076 calls — 7,715 queries and 14.9 s per page load; after, 67
  queries and 0.18 s. **Do not add a query inside `computeGrade()`'s call
  path.** A new evidence read belongs in `evidenceFor()`'s two loads and a
  new accessor beside `scoredItemCount()` / `scoredItems()`.
- **Staleness rule.** The caches (engine evidence, engine reference memo,
  `TransmutationService`'s per-scheme bands) are instance-scoped and
  checked against one process-wide generation counter. Every Eloquent
  save/delete on `Assessment`, `AssessmentScore`, `SubjectGroupWeight`,
  `ExamRoleShare`, `TransmutationRange` and `DepedSubjectCatalog` bumps it
  (`AppServiceProvider::boot()`). **A write to those tables that bypasses
  model events (query-builder `update`/`delete`/`insert`, raw SQL) must call
  `GradingEngine::invalidateEvidence()` itself** — the one such write in
  the app (`Adviser\AssessmentController::updateItem()`'s delete) does.
  `PerformanceBatchEquivalenceTest` proves a same-instance write is seen.
- **`PerformanceAnalysisService::analyzeStudent()` carries `item_count`**
  (from the engine's evidence); `InTermStatusService::fromAnalysis()` reads
  it instead of running its own per-row count, and still runs the query
  for a hand-built analysis array without it. `ProgressMonitoringService`
  reads its as-of (`created_at <= delivered_at`) rows through
  `GradingEngine::scoredItems()` on the SAME engine
  (`PerformanceAnalysisService::engine()`), so the interventions pages
  share one evidence load.
- **`RiskFeatureExtractor` answers every per-learner lookup from a
  per-batch map** (expected item count, recorded score counts grouped by
  student, the previous period's `risk_results.average_grade`, both
  periods' per-subject grades) filled by one query per scope — Submit
  Report went from 193 to 63 queries for 42 learners. Feature definitions
  are unchanged; `PerformanceBatchEquivalenceTest::test_risk_features_
  match_per_learner_queries` holds the original queries as its oracle.
  `runAnalytics()` now logs the classifier's structured error and stderr
  when the process fails — the two live failures earlier that day had left
  only `exit_code: 1` in the log, which was not diagnosable.
- **Python is launched exactly once per Submit Report** (it already was;
  now measured: `py=1`). Its ~4 s is `scikit-learn`'s own import chain
  (scipy.stats, pandas via `sklearn.utils.fixes`) triggered by unpickling
  the forest — library cost, not application code, and not per learner.
  Predicting 42 rows takes 45 ms. The only ways below that are a resident
  worker or a different artifact, both behaviour/architecture changes that
  were deliberately not made.
- **The prescribed ECR is never loaded whole.** A full seven-sheet
  `load()` of the 434 KB instrument costs ~1.7 s and tens of MB; `HELPER`
  alone is ~0.8 s. `EcrReaderService::toFlatRows()` loads `INPUT DATA` +
  the one term sheet it reads (~0.3 s), `checkWeightMismatch()` and
  `extractDraftRoster()` load `INPUT DATA` only (~50 ms), and
  `EcrProfileDetector` reads its two marker cells through a read filter
  and memoises the verdict by file content (md5), so the three services
  that ask per request share one answer. Values read are unchanged — every
  read is a raw `getValue()`, never a calculated one, so which other sheets
  are in memory cannot change a value. detect/preview/import went from
  8.7 s / 5.3 s / 6.5 s (177 MB peak) to ~0.5 s / 0.4 s / 0.5 s (18 MB).
  **When adding a workbook read, name the sheets it reads via
  `loadSheets()`; do not reach for `load()`.** The flat CSV/XLSX fallback
  in `AssessmentUploadService::readRows()` is untouched on purpose: its
  `toArray(..., formatData: true)` depends on cell styles, so
  `setReadDataOnly(true)` there would change values.
- **Imports preload the rows `updateOrCreate()` would look up one at a
  time** (`AssessmentUploadService::import()` for scores, `ReportController::
  runAnalytics()` for risk results) and then do exactly what
  `updateOrCreate()` did per row — `fill()` + `save()` on the existing
  model or `create()` — so model events, timestamps and the unique keys
  are unchanged. Writes still happen one row at a time and inside the same
  transaction; bulk `insert()` was rejected because it skips events and
  `created_at`.
- **Indexes are added only against an EXPLAIN'd pattern.**
  `2026_09_19_000001_add_indexes_to_activity_logs_table` adds
  `(created_at)` and `(action, user_id)` on the fastest-growing table,
  whose two live reads (`ORDER BY created_at DESC` on the log page and
  dashboard; `WHERE action = 'login'` in Data Health) were full scans with
  filesort. Every other hot query already used an index. Rehearsed
  forward/rollback/forward on the copy before running live; backup
  `backups/backup_20260919_pre_perf_indexes.sql`.
- **The remaining per-page cost is environmental, not application.** With
  OPcache disabled (XAMPP's `php.ini` ships `;zend_extension=opcache`
  commented out), every request compiles ~590 PHP files: a page that does
  26 ms of work in-process (Admin > Users) takes ~300 ms over HTTP, and
  the same code on PHP's built-in server measured ~360 ms without OPcache
  vs ~45 ms with it. `php artisan optimize` measured no gain here (cached
  config/routes are just more PHP to compile) and `config:cache` breaks
  `phpunit.xml`'s env overrides — leave it cleared in this checkout.
  Enabling OPcache is a php.ini change for the operator, recorded as a
  recommendation, not made by this pass.


## Final pre-demo full-system audit (2026-09-21, evening) — standing conventions it added

Full findings and evidence are in `FINAL_PRE_DEMO_AUDIT.md`; these are
the rules that stay true afterwards.

- **`bootstrap/app.php` calls `Env::disablePutenv()` before
  `Application::configure()`, and must keep doing so.** The demo box
  serves this app as a PHP ZTS module under Apache's threaded MPM.
  Laravel's default `Env` repository reads and writes `.env` values
  through `putenv()`, and PHP unsets a request's `putenv()` variables
  PROCESS-WIDE at that request's shutdown — so two overlapping requests
  raced and one booted with no `.env` at all (`production.ERROR: No
  application encryption key has been specified`, HTTP 500, and a fall
  through to `config/database.php`'s sqlite/`laravel` defaults).
  Reproduced at 28 failures in ~300 requests with three concurrent
  clients; 0 in ~1,200 after the change. `$_ENV`/`$_SERVER` are
  per-request, and `variables_order = GPCS` puts a container's process
  environment into `$_SERVER`, so Docker/Railway are unaffected. Nothing
  in `app/`, `config/`, `routes/` or the seeders reads `getenv()`
  directly — do not start. `EnvPutenvDisabledTest` pins the behaviour
  and fails against the unfixed bootstrap.
- **Live authorization probes must never carry the matching role's
  session.** A wrong-role request is refused by `RoleMiddleware` before
  any controller runs, so that half of the matrix is safe to send at
  the live database; a correct-role POST to an action route (`users/{id}/
  disable`, `academic-terms/{id}/close`) IS the action. This audit sent
  one such batch by mistake, disabled the adviser account and cycled
  Term 1 closed→open; both rows were restored to their exact prior
  values from the pre-audit dump. Correct-role write behaviour belongs
  in the isolated suite (`RoleAccessMatrixTest`, `CrossSectionAccessTest`).
- **Phone-width toolbars wrap: `flex-wrap md:flex-nowrap`.** The
  `flex items-center gap-3` search-plus-button rows (Admin > Sections,
  Adviser > Assessments' subject/upload row and term tabs) clipped their
  right-most button inside the `h-screen overflow-hidden` shell at
  ≤390 px. The `md:` scope keeps the ≥768 px layout byte-identical (the
  1366×768 screenshot hash matched before and after). A new toolbar
  should carry the same pair. `md:flex-nowrap` is a new utility, so the
  change needs `npm run build`; `public/build` is git-ignored.
- **Environmental exposures recorded, deliberately not changed here**:
  MySQL `root` has no password, listens on `0.0.0.0:3306`, and the
  Windows firewall allows `mysqld` inbound on the Public profile; all
  five live accounts still use `DatabaseSeeder::PASSWORD`. Both are the
  operator's to fix (`.env` secret + `my.ini` + a MySQL restart by hand;
  Admin > Users). See the audit report, Section AD.
- **Recorded, not fixed (P3, code inspection):** `Principal  InterventionController::store()`/`storeBulk()` check for an open
  duplicate and then insert with no transaction or lock, and MySQL
  cannot index the status-dependent rule; a double-fire within the same
  few milliseconds could record two open interventions for one
  learner/subject/term. Reversible; single Principal user; client-side
  disabled button. The fix is `DB::transaction()` + `lockForUpdate()` —
  a behaviour change on the frozen table, so it waits.

## Railway readiness and test-environment isolation (2026-09-22) — standing conventions it added

A "many failures across unrelated modules" report had ONE cause, found by
reading the first 404 rather than the count: the checkout's `.env` carried
`APP_URL=http://localhost/naggasican-dss/public`, and Laravel's
`MakesHttpRequests` prepends `APP_URL`'s path to every test URI, so the
router saw `/naggasican-dss/public/login` and answered 404 for every route.

- **`phpunit.xml` pins `APP_URL=http://localhost`, `TRUSTED_PROXIES=` and the
  two `RAILWAY_*` variables.** The suite must not depend on the developer's
  `.env`; the sub-directory `APP_URL` is harmless for the running app (the
  URL generator reads the request root) and only the tests were affected.
  A test that starts failing with a 404 on every route is this, not the app.
- **Proxy trust is env/platform-driven; the headers are fixed.**
  `bootstrap/app.php` trusts `X-Forwarded-For/-Proto/-Port` and NEVER
  `X-Forwarded-Host`; `config/trustedproxy.php` decides WHICH proxies: an
  explicit `TRUSTED_PROXIES` wins (`*` or a list), else a service carrying
  Railway's own `RAILWAY_ENVIRONMENT_NAME`/`RAILWAY_PUBLIC_DOMAIN` trusts its
  ingress, else nothing (local XAMPP). An uncommitted `trustProxies(at: '*',
  headers: ...HOST...)` was reverted: on the LAN box it let any client
  spoof the IP the login throttle keys on and choose the host in
  password-reset links. `DeploymentProxyTest` + `TrustedProxyConfigTest`
  are the tests of record. The unstyled-login symptom on Railway is this
  rule unmet: with no trusted proxy, `asset()` emits `http://` URLs on an
  `https://` page and the browser blocks them as mixed content.
- **Railway builds from `Dockerfile.vercel` via `railway.json`.** Railpack's
  PHP auto-builder installs Node and runs the Vite build but NO Python, so
  `classify.py` could never run there. The Dockerfile installs the pinned
  `analytics/requirements.txt` and refuses to build if `model_cache.pkl`
  cannot be loaded. The entrypoint recreates the `storage/` tree on every
  start because an attached volume mounts EMPTY over it ("View path not
  found"; www-data unable to write sessions/logs/uploads). Neither file
  affects XAMPP or the test suite. Railway service variables still needed by
  hand: `APP_KEY`, `APP_URL=https://<public domain>`, the `DB_*` set,
  `SESSION_SECURE_COOKIE=true`. Sessions and cache live in MySQL
  (`SESSION_DRIVER=database`) and survive a redeploy; `storage/app/private`
  uploads and `storage/logs` do not unless a volume is attached at
  `/var/www/html/storage`.

## MySQL / shared-network hardening (2026-09-21, evening) — machine configuration, not repository code

`C:\newxampp\mysql\bin\my.ini` (backup `my.ini.bak_20260921_180744_pre_
network_hardening`) had carried `skip-grant-tables` since 2026-06-24 —
authentication OFF for every client on every interface, with the listener
on `0.0.0.0:3306` and a Public-profile firewall allow. Now: `skip-grant-
tables` removed (grant tables verified healthy first; `root@localhost`
blank password still works for Laravel and phpMyAdmin), `bind-address=
127.0.0.1`, and — after nine identical InnoDB "Failing assertion: slot"
crashes in 30 days plus a shutdown hang on a lost async checkpoint write —
`innodb_use_native_aio=0`, `innodb_buffer_pool_load_at_startup=0`,
`innodb_buffer_pool_dump_at_shutdown=0`. A clean stop now takes ~3 s.
Details and evidence: `FINAL_PRE_DEMO_AUDIT.md`, Addendum. This is lost on
a XAMPP reinstall, like the Apache hardening; before a demo check
`mysql -uroot -h127.0.0.1 -e "select @@bind_address, @@innodb_use_native_aio"`
(expect `127.0.0.1`, `0`). MariaDB is not a Windows service here: start it
from the XAMPP Control Panel; a process started from a tool shell can die
with that shell, and a hung one must be force-stopped (InnoDB is
crash-safe; redo is flushed at commit).



## Final pre-demo / pre-deployment audit (2026-09-27, evening) — standing conventions it added

Full findings are in `FINAL_PRE_DEPLOYMENT_AUDIT_2026-09-27.md`.

- **Isolated HTTP testing: `php artisan serve` is NOT isolated by an env
  override.** Its child `php -S` process drops non-allow-listed variables,
  and on this Windows box it did not honour `APP_ENV` either — two attempts
  with `DB_DATABASE=…`/`.env.audit` served the LIVE database (only guest
  session rows and expired throttle keys reached it). Plain `php script.php`
  and `artisan tinker`/`migrate` DO honour an inline `DB_DATABASE`. The
  audit harness therefore sends real requests in-process through the HTTP
  kernel (routing, sessions, CSRF, auth, role middleware) from plain PHP
  processes that assert the connection is `naggasican_dss_audit` and exit
  otherwise; parallel processes give real database-level concurrency. The
  isolated copy is `naggasican_dss_audit` (synthetic data; safe to drop).
- **Add Item is create-only.** `Adviser\AssessmentController::storeItem()`
  used `updateOrCreate()` after a non-atomic same-name check; 6 concurrent
  submissions produced 1 item + 5 HTTP 500s, and an unlucky interleaving
  could have landed a second Add on the first's item and overwritten it.
  It now `create()`s and turns `UniqueConstraintViolationException`
  (`unique_assessment_per_period`) into the ordinary "already exists"
  refusal; the transaction (upload row included) rolls back. 8 concurrent
  submissions on MariaDB: 1 item, 0 errors.
- **Interventions: the open-duplicate check runs under a learner row lock.**
  `Principal\InterventionController::store()` and `storeBulk()` check
  "open intervention for this learner/subject/term?" and insert inside one
  `DB::transaction()` after `Student::whereKey()->lockForUpdate()`. Before,
  6 concurrent submissions created 6 open interventions (reproduced on the
  audit database — the P3 race the 2026-09-21 audit recorded as theory).
  After: 8 concurrent submissions → 1. MySQL cannot index the
  status-dependent rule, which is why it is a lock, not a unique index.
- `FinalAuditScenariosTest` walks the mandatory business scenarios through
  the real HTTP workflows (A=18/B=0/C=blank/D=Missing; the professor
  scenario incl. a higher other subject NOT resolving, and correction via
  Encode Grades resolving; two failures = one learner/two concerns;
  intervention lifecycle never resolving; a closed term refusing every
  adviser write; failed-login messages + throttle; the raced Add Item).

**Recorded, deliberately NOT changed (policy):** Encode Grades
(`adviser.grades.store`) and the grade import write `grades` rows WITHOUT
`is_verified`, so a manually typed grade is "not yet official": it never
opens a Subject Performance Concern and reads as not-official on Submit
Report — yet Submit Report counts it toward "every expected grade encoded".
Whether a manually encoded grade is official, or Submit Report should
require verified grades, is the school's decision. An EXISTING verified
grade corrected through Encode Grades stays verified (that is the path the
correction scenario uses).

## Final release pass (2026-09-27, late) — standing conventions it added

- **No login password is committed.** `DatabaseSeeder` creates accounts
  with `config('dss.seed_account_password')` (`SEED_ACCOUNT_PASSWORD`, min
  12 chars, never the word `password`), only for accounts that do not exist
  yet. Unset in production -> the seeder throws BEFORE writing anything;
  unset locally/testing -> a one-time random password, shown once on the
  console. Existing accounts are never touched (no silent rotation).
  `phpunit.xml` pins it empty. `SeederCredentialsTest` is the test of
  record. The 5 working accounts still carry the OLD default password and
  must be changed by the operator before any deployment.
- **Within-Term Progress:** the change is "percentage points", never
  "points" (it sat under raw earned/possible points). Scores are edited in
  place with no history, so a pre-delivery score changed after delivery is
  counted in "Before" at its current value — `compareWithinTerm()` returns
  `before_scores_changed_since_delivery` and the partial says so in amber.
  The true at-delivery value is NOT reconstructed (no invented history).
- **Encode Grades uses the one Failing rule** (`InTermStatusService::
  isFailing()`); a typed, unverified grade is labelled "Not yet official"
  and never tinted as a failure. Whether it BECOMES official is still the
  open policy question recorded above.
- The 75 in-term target is labelled on screen as "a reference point set in
  this system, not a DepEd grading rule"; no monitoring rule reads it.


