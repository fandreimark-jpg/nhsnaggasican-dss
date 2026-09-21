# FINAL PRE-DEMO FULL-SYSTEM AUDIT — Naggasican NHS SHS Decision Support System

**Audit run:** 2026-09-21, 16:47–18:10 (Asia/Manila). **Auditor:** Claude Code (Opus 5), driven by the audit brief supplied by the project owner.
**Scope:** the live XAMPP demo installation at `C:\newxampp\htdocs\naggasican-dss` (HEAD `ec5ee00` at start), its frozen Term 1 dataset, the ML artifact, and the Apache hardening. Nothing in this document contains a password, a key, or a learner identifier.

Every figure below was measured in this run, not copied from earlier documents. Where a claim rests on code reading rather than execution, it says so.

---

## A. Executive summary

- **Two defects were found and fixed**, one of them serious for a demo on a shared machine:
  1. **P1 — intermittent HTTP 500 under concurrent requests** ("No application encryption key has been specified", logged under the `production` environment). Root cause: Apache loads PHP as a ZTS module (`php8apache2_4.dll`, threaded MPM) and Laravel's `Env` read `.env` through `putenv()`, which PHP unsets **process-wide** at each request's shutdown. Two overlapping requests raced and one booted with no `.env` at all — no APP_KEY, wrong environment, and a fall-through to `config/database.php`'s sqlite/`laravel` defaults. Reproduced deliberately: **28 failures in ~300 requests** with three concurrent clients (4 of 60 probe requests returned 500). Fixed with one line in `bootstrap/app.php` — `Env::disablePutenv()` — after which the identical load produced **0 failures in two runs of ~600 requests each**. Pinned by `tests/Feature/EnvPutenvDisabledTest.php` (3 tests; proven to fail against the unfixed bootstrap).
  2. **P4 — two toolbars clipped at phone widths** (Admin > Sections "Add Section"; Adviser > Assessments "Upload Assessment Form" / "Add Assessment Item" and the term tabs). Fixed with `flex-wrap md:flex-nowrap`, scoped so the ≥768 px layout is byte-identical to before (screenshot hash compared). Requires `npm run build` (done; `public/build` is git-ignored).
- **Everything else the brief asked for held up**: authentication, the full route × role matrix (GET and mutations, over real HTTP), CSRF, session regeneration, rate limiting, the Apache hardening on localhost/LAN/HTTPS, the 1,081-test PHP suite, the three Python suites (102 tests), the production build, migrations, `dss:check-integrity`, 40 independent read-only invariants, and the ML boundary.
- **Environmental exposures that are NOT application code and were NOT changed** (they need the operator's decision — see Section AD): MySQL `root` has no password, listens on `0.0.0.0:3306`, and the Windows firewall allows `mysqld` inbound on the Public profile; all five demo accounts still use the seeded default password that is committed in `DatabaseSeeder.php`.
- **One audit-caused incident, fully reversed**: an authorization probe sent with a real Admin session disabled adviser user #2 and closed/re-opened Term 1. The two rows were restored to their exact pre-audit values (approved by the owner); no academic table was touched. Three activity-log rows (`disable_user`, `close_term`, `open_term` at 16:54–16:55) and 21 `login` rows remain as an honest trace — see Section W.
- **Frozen Term 1 dataset: unchanged.** All nine per-table content hashes (assessment_scores, grades, risk_results, interventions, assessments, report_submissions, students, sections, subjects) are identical before and after; the three checksums match the freeze manifest. `model_cache.pkl` SHA-256 unchanged.

**Verdict: FINAL DEMO READINESS: READY WITH CAUTIONS** — see Section AE.

---

## B. Environment (discovered, not assumed)

| Item | Value |
|---|---|
| PHP | 8.2.12 ZTS (CLI and Apache module `php8apache2_4.dll`); `memory_limit` 512M; OPcache **off** |
| Laravel | 12.62.0 |
| Database | MariaDB 10.4.32, `naggasican_dss` @ 127.0.0.1:3306 (bound on `0.0.0.0`) |
| Node / npm | v24.14.0 / 11.9.0 |
| Python | 3.14.6 — scikit-learn 1.9.0, numpy 2.4.6, joblib 1.5.3 |
| `APP_ENV` / `APP_DEBUG` | `local` / `false` |
| Session / cache / queue / mail / filesystem | `database` / `database` / `database` / `log` / `local` |
| Session lifetime | 120 min; cookie `HttpOnly`, `SameSite=Lax`, not `Secure` (HTTP demo) |
| Vite | production build present (`public/build/assets/app-B6iIVwE2.css`, `app-zbVra3PO.js`); `public/hot` absent |
| Migrations | 70 ran, 0 pending |
| Routes | 94 — admin 47, adviser 23, principal 10, auth/profile/health 14 |
| Middleware | `auth` + `role:<role>` on every role-prefixed route; `EnsureAccountIsActive` + `PreventBackHistory` appended to the web group |
| Git at start | `main` @ `ec5ee00`, clean |
| Web root | Apache serves only `naggasican-dss/public` (`httpd-naggasican-dss.conf`) |

## C. Architecture verified

Routes → `RoleMiddleware` (redirect guests, 403 other roles) → controllers under `Admin/`, `Adviser/`, `Principal/` → 26 services (`GradingEngine`, `SubjectApplicabilityService`, `InTermStatusService`, `AssessmentUploadService`, `RiskFeatureExtractor`, …) → 24 models. The only external process is `analytics/classify.py`, launched from `Adviser\ReportController::runAnalytics()` via a Symfony `Process` argument array (no shell), 120 s timeout, structured stderr logging, temp files removed in `finally`, output validated (`classifierOutputIsValid()`) before any `RiskResult` write. Browser-side `fetch()` calls (grade verify, intervention delivery, verify-all preview, specialization-by-track, search filter) all target same-origin named routes with the CSRF token. No external URL, webhook, or API key is used anywhere in `app/`, `resources/`, or `config/` beyond Laravel's unused defaults.

## D. Authentication results (real HTTP against Apache)

| Check | Result |
|---|---|
| Valid Admin / Adviser / Principal login | 302 → own dashboard |
| Wrong password / nonexistent account | 302 → login, "credentials do not match" |
| Disabled account (user #12) | 302 → login, "account has been disabled" (checked only after password verification) |
| POST without CSRF token | **419** |
| Session id regenerated on login | yes (cookie value differs before/after) |
| Logout → revisit `/admin/dashboard` | 302 → login |
| 6 bad attempts | "Too many login attempts… 50 seconds" (RateLimiter, 5/min per email+IP) |
| Guest to any protected route | 302 → login |
| Open-redirect probes (`?redirect=`, `?next=`) | ignored; lands on own dashboard |
| Security headers | `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-store` |
| Passwords in HTML/JS/logs | none found (`grep` of built JS, views, and the log) |
| Forgot/reset password | routes present, guest-only, `MAIL_MAILER=log`; nothing links to them |

Finding (P2, environmental): all five live accounts still verify against the seeded default password (`DatabaseSeeder::PASSWORD`, committed). Change them from Admin > Users before presenting on any network other than the presenter's own machine.

## E. Authorization / role-access matrix (real HTTP)

**GET, 30 routes × 4 principals:** every `admin/*` route → Admin 200, Adviser 403, Principal 403, guest 302; every `adviser/*` → Adviser 200 (verify-all preview 302 without parameters), others 403; every `principal/*` → Principal 200, others 403. `/up` 200 for all (returns "OK" only).

**Mutations, 45 routes (POST/PUT/DELETE) × wrong roles and guest:** every wrong-role request → **403**; every guest → 419 (CSRF, before auth). Four routes answered 404 to wrong roles for a **nonexistent** id (`adviser/assessments/item/1`, `adviser/interventions/1/*`, `principal/interventions/1`) because route-model binding runs before the role check — an existence leak for ids only, no data; `RoleAccessMatrixTest` covers the existing-id cross-object cases (adviser B vs adviser A's learner/assessment/intervention; adviser/principal vs Admin mutations; unsigned local-disk serve routes).

Correct-role write routes were **not** exercised live against the frozen data (see Section W for the one lapse and its reversal); their validation, scoping and IDOR behaviour is covered by the isolated suite (`CrossSectionAccessTest`, `RoleAccessMatrixTest`, `TermSpecific…`, `AdminStudentManagementTest`, `PreDemoAuditRegressionsTest`, …).

## F. Admin workflow results

All 11 Admin pages (Dashboard, Users, Tracks, Specializations, Subjects ± type filter, Sections, Sections > Subjects ×3 terms ×2 sections, Students ×9 pages, Academic Terms ×2 years, Reports, Activity Logs ×99 pages) rendered 200 with no error marker, no console error and no failed sub-request in headless Chrome. Blank-payload POSTs to users/students/subjects/sections/tracks/specializations/academic-years all bounced with validation (302) and wrote nothing. Adviser cannot reach any `admin/students` create/import/enroll route (403). Principal cannot mutate Admin configuration (403 on all 26 Admin write routes). Destructive artisan commands (`dss:truncate`, `dss:remove-test-fixture-students`, `dss:fix-assessment-max`, `dss:recompute-grades`) all require interactive confirmation defaulting to *no*.

## G. Adviser workflow results

All Adviser pages (Dashboard, My Students, Encode Grades ×3 terms + template, Assessments ×3 subjects ×3 terms incl. add-item deep links, Submit Report, Interventions) rendered 200. Live `detect()` refusals, none of which write to the database (verified by reading the method and by the post-run baseline): Grade 12 (AGILA) workbook against a Grade 11 section → "Weight mismatch … file has WW=20%/PT=60%/EX=20%, the system resolves WW=20%/PT=50%/EX=30%" and refused; truncated `.xlsx` → "could not be read as a spreadsheet"; `.php` → "must be a file of type: xlsx, xls, csv, txt"; blank prescribed ECR → "No assessment columns were found"; temp directory left empty. Applicability, Terms Taught, closed-term refusal, repeat-upload metadata conflicts, blank-cell handling, exam roles, role-less EX items and partial-write rollback are held by the suite (`SubjectApplicabilityTest`, `RepeatUploadMetadataConflictTest`, `AssessmentUploadWorkflowTest`, `EcrHttpUploadValidationTest`, `PrescribedEcrMetadataValidationTest`, `AcademicHistoryArchitectureTest`, `AssessmentPerformancePageTest`). The live page shows the 3 role-less "Additional Practice EX" items per subject in the amber notice, as designed. **The frozen Term 1 report was not re-submitted and Verify All was not pressed.**

## H. Principal workflow results

Dashboard, Students (+ risk/section/term filters), Student detail, Reports, Subject Analysis (+ filters), Interventions (+ awaiting filter) all 200 at seven viewports. `RiskResult` has exactly one write site in the codebase (`Adviser\ReportController::runAnalytics()`); Principal controllers only read it. Intervention creation guards an open (student, subject, term) duplicate in `store()` and `storeBulk()`, and every recorded intervention carries `origin = principal`, a decider, and a delivery note (verified by query). Intervention access is scoped by school year and section.

## I. Forms / buttons / CRUD matrix

| Form | Server-side rule set | Evidence |
|---|---|---|
| Login | `LoginRequest` (required, email, throttle) | live |
| Admin Users / Students / Subjects / Sections / Tracks / Specializations / Academic Years | `validate()` in each controller; DB unique keys (`users.email`, `students.lrn`, `subjects(name,grade_level)`, `sections(name,grade_level,school_year)`) | live blank POST → 302; suite |
| Student import / ECR roster import | extension rule via `ValidatesSpreadsheetUpload`, 10 MB cap, row-level rejects downloadable | suite (`MimesFixSixMoreRoutesTest`, `AdminImportLearnersFromEcrTest`) |
| Assessment detect / preview / import | extension rule, identity check, term applicability, weight-conflict refusal, metadata-conflict refusal, transaction with rollback | live (detect) + suite |
| Grades store / import / verify / verify-all | range 0–100, term open, subject applicable | suite |
| Submit Report | expected-count check, active-year open-term, classifier output validation | suite |
| Intervention store / bulk / update / acknowledge / deliver | open-duplicate guard, required delivery note, group labelling | suite |

Every page that can receive a default-bag validation error renders `partials/validation-errors` (pinned by `PreDemoAuditRegressionsTest`). Old input is preserved by Laravel's redirect-with-input. Failed validation writes nothing (transactions listed in Section U).

## J. Broken-link / navigation audit

Crawler followed every `href` from each role's dashboard (245 Admin URLs incl. pagination, 30 Adviser, 30+ Principal): **0 broken links, 0 404s, 0 500s on an idle server, 0 error markers** ("Whoops", "SQLSTATE", "Undefined", "Vite manifest", dev-server host). The only non-200s were form `action` URLs fetched with GET (405, expected) and two Admin download routes that 404 when no file is queued in the session (expected). Headless Chrome reported no console exceptions and no failed asset requests on any page at any viewport.

## K. Database integrity

`php artisan dss:check-integrity` (verified read-only by inspection): **"No orphaned or inconsistent data found."**

40 independent read-only invariants (script kept out of the repo): 0 duplicate risk results / grades / scores / LRNs; 0 scores outside `[0, max]`; 0 grades outside 0–100; 0 scores or grades for a learner not enrolled in that section/year; 0 grades for a subject not taught in that term; 0 risk results whose section disagrees with the enrollment; 0 `ml_risk_level ≠ risk_level` without `was_overridden`; 0 interventions with a null `risk_result_id`, a mismatched learner, an undecided approval, a missing delivery note, or a delivery before decision; 0 additional-support scores before delivery or for a non-intervened learner; 0 Term 2/3 records; 1 active year, 1 open term, 1 active Principal; 0 `failed_jobs`. The 28 (learner, risk result) pairs with more than one intervention are distinct **(learner, subject)** pairs — 32 learners, 82 subject-level interventions — not duplicates. Every unique index the design relies on exists (listed in Section U). FK delete rules are as documented (restrict on academic history).

## L. Subject / term applicability

`SubjectApplicabilityService` is the only resolver; `Subject::forSection()` delegates to it and has 56 call sites. The only other `Subject::where(type/grade_level)` queries are the integrity command's diagnostics and `SectionElectiveStatus`'s "electives available in this track" question (a different question). No duplicate hand-written resolution found. Live: Term 1 resolves 3 subjects per section, Terms 2–3 resolve 4 (Philippine History and Society is Terms 2–3 only), matching `subject_terms` (20 rows).

## M. Grading-engine verification

Read-only. `GradingEngineTest` pins the documented 84.44/85/70 → 81.11 example and the component rules; `GradingPolicyResolutionTest` reads the official workbook as its oracle; `SubjectClassificationConsistencyTest` computes all seven DO 015 profiles end to end; `Do015BandsMatchOfficialEcrTest` pins the 41 bands; `TransmutationServiceTest` covers scheme inference by curriculum/year. Live configuration: 13 `subject_group_weights` rows (7 DO 015 + 6 DO 8), 3 `exam_role_shares` rows, 141 catalog rows, 82 transmutation bands. All 252 stored grades are verified and the checksum `SUM(grade×id) = 7,950,484.00` matches the freeze. No recompute was run.

## N. ECR / import verification

See Section G for the live refusals. The tracked SSHS ECR fixture carries no learner (0 male, 0 female rows) and the Grade 12 fixture is sanitized ("TEST DIVISION"). Upload storage uses a UUID filename; the read-back parameter is regex-validated (`^[a-f0-9\-]+\.(xlsx|xls|csv|txt)$`) — no path traversal.

## O. ML / Python pipeline

| Item | Verified |
|---|---|
| `analytics/model_cache.pkl` | SHA-256 `14b01918f3b210777bf3013d1f56bba79062b20f3b13e2fd05ff8314027807cd`, 171,041 bytes, mtime 2026-09-07 21:39:04 — **identical at start and end** |
| Artifact contents (joblib, read-only) | `RandomForestClassifier`, `n_features_in_ = 1` (`average_grade`), 3 classes, 200 trees |
| Descriptor | `legacy_synthetic_prototype`, `feature_names = ['average_grade']`, `dataset_type = synthetic` |
| Registry | `models/active`, `candidate`, `archived` all empty before and after the training-pipeline tests |
| `classify.py` | no `.fit(`, `cross_val`, `train_test_split`, `GridSearch` (grep + `test_inference_contract`) |
| Scratch inference | 92 → low, 78.5 → moderate, 71 → high, ~4 s (library import cost); malformed input → exit 1 + `{"error": {"code": "bad_input_file"}}` |
| Trigger | only Submit Report launches Python; uploads, grades and interventions never do |
| Persistence / re-submission | `RiskResult` unique per (student, term, year); a re-submit **overwrites** (no history) — documented limitation |
| Failing-subject floor | `applyFailingSubjectOverride()` PHP-side, escalation only; live `was_overridden = 0` for all 84 |

**Software/pipeline validation: passes. Real-world predictive validity: NOT established** — the deployed model is a synthetic prototype whose levels are calibrated thresholds on average grade; nothing in this audit changes that. An observation: a `null` average reaches the forest as NaN and returns `moderate` at 62.5 % confidence; unreachable in practice because Submit Report refuses when expected grades are missing, but recorded here.

## P. API / process calls

Exactly one external process (Section C). No `Http::`, `curl`, `shell_exec`, `exec(` string form, or third-party endpoint. Browser `fetch()` calls: 6, all same-origin. `services.php` carries Laravel's unused Postmark/SES/Resend/Slack env keys (all empty). Nothing transmits learner data off the machine.

## Q. Security findings

| # | Severity | Finding | Status |
|---|---|---|---|
| S1 | **P1** (env) | MySQL `root` has **no password**, listens on `0.0.0.0:3306`, Windows firewall allows `mysqld` inbound on the Public profile; the app connects as root. Anyone on the demo Wi-Fi could read or alter the frozen dataset directly. | Not changed (machine config + `.env` secret). Fix in AD. |
| S2 | **P2** (env) | All five accounts use the seeded default password, committed in `DatabaseSeeder.php`. | Not changed. Fix in AD. |
| S3 | **P1** (app) | Concurrent-request boot race → HTTP 500 / wrong environment (Section A). | **Fixed**, regression-tested. |
| S4 | INFO | phpMyAdmin (`auth_type=config`, root, no password) answers on the LAN IP from the server itself; `Require local` should refuse other hosts but this could not be verified from a second device. | Verify from another device or stop phpMyAdmin for the demo. |
| S5 | INFO | Session cookie lacks `Secure` (HTTP demo); no CSP/HSTS. | Acceptable for a localhost demo. |
| S6 | INFO | Nonexistent-id probes on four routes answer 404 before the role check. | No data exposure. |
| — | — | SQLi: no interpolated raw SQL; XSS: zero `{!! !!}`, `XssEscapingAcrossRolePagesTest`; CSRF: 419 verified; mass assignment: no `$guarded = []`, no `->all()` into create/update; file upload: extension rule + UUID + regex read-back; `.env`, `.git`, logs, backups, dumps, CSV, model, `vendor/`, `tests/`, `database/`, `composer.json`, `CLAUDE.md`, `public/.htaccess`, `public/../.env`, `%2e%2e` → **403 on localhost, 172.16.0.2 and https://localhost**; directory listing off; `APP_DEBUG=false`; `robots.txt` disallows all. | Clean |

## R. Sensitive-data / privacy findings

| Item | Classification |
|---|---|
| `.env` | IGNORED BUT LOCAL, 403 over HTTP, never committed (git history checked) |
| `backups/` (39 dumps, 19 MB) | IGNORED BUT LOCAL, 403; authoritative copies outside the web root in `C:\newxampp\db_backups\` |
| `term1_intervention_remedial_mapping.csv` (root, synthetic LRNs+names) | IGNORED BUT LOCAL, 403 — NEEDS ACTION: delete when no longer needed |
| `storage/logs/laravel.log` (17.8 MB) | IGNORED BUT LOCAL, 403; contains student **ids** and stack traces, no names/passwords found |
| `database/database.sqlite` | IGNORED BUT LOCAL, 403; only Laravel base tables (an artifact of the now-fixed race) |
| `analytics/model_cache.pkl` | TRACKED IN GIT on purpose; 403 over HTTP |
| Tracked fixtures (`tests/Fixtures/*`, `database/seeders/*.csv`) | SAFE — blank/sanitized/catalog data; 12-digit LRN-like strings in tests are synthetic `9901…` |
| `DatabaseSeeder.php` default password constant | TRACKED IN GIT — NEEDS ACTION only insofar as S2 (rotate the live passwords) |
| Old project ZIPs | already moved out of `htdocs` on 2026-09-21 (Phase 1) |

## S. Responsive / mobile results (headless Chrome, real login, 7 viewports)

All 24 major pages across the three roles at 1920×1080, 1366×768, 1024×768, 768×1024, 430×932, 390×844, 360×800: **no document-level horizontal overflow, sidebar shown ≥768 px and collapsed behind the hamburger below, no console errors, no failed requests**. Tables scroll horizontally inside `.tbl-scroll` as designed. Two toolbars were clipped at ≤390 px (Sections "Add Section"; Assessments upload/add buttons and term tabs) — **fixed** (Section A), desktop pixel-identical. Remaining cosmetic notes (not fixed): a few narrow `<select>`s on Principal > Students at 360 px overlap their chevron; touch targets under 24 px are mostly icon-only row buttons (Edit pencils) — usable but small.

## T. Slow-network / failure-state results

Not testable end to end without browser throttling tools. What the code and suite establish: every submitting form disables its button and relabels it (`confirm.js`, `data-loading`); assessment import, roster import, grade verify, intervention delivery and academic-year activation run inside `DB::transaction()` (Section U); the classifier has a 120 s timeout and a failed/missing/invalid result leaves the report submitted and shows the adviser the "risk analysis failed to generate" warning (`MlArchitectureBoundaryTest`, `PerformanceBatchEquivalenceTest`); a corrupt upload is a controlled refusal (live). Under the pre-fix race a slow client could see a 500 with no partial write (the request never reached the app); after the fix, 0 in ~1,200 requests.

## U. Multi-user / concurrency results

- **Reads:** Admin + Adviser + Principal sessions interleaved for ~1,200 requests (three loops + probe) after the fix: 0 errors. Before the fix: 28 (Section A).
- **Writes (by construction, isolated suite, not the live DB):** unique keys make duplicate submits idempotent — `grades(student,subject,term,year)`, `assessments(subject,section,term,year,name)`, `assessment_scores(assessment,student)`, `risk_results(student,term,year)`, `report_submissions(section,term,year)`, `student_enrollments(student,year)`, `section_subjects(section,subject,term)`, `students.lrn`, `subjects(name,grade)`, `sections(name,grade,year)`, `users.email`, `users.role_singleton_key`. `AcademicYear::activate()` locks the table.
- **Finding U1 (P3, code inspection):** `Principal\InterventionController::store()`/`storeBulk()` check for an open duplicate and then insert, without a transaction/lock and with no unique key (a status-dependent rule MySQL cannot index). Two requests within the same few milliseconds could record two open interventions for one learner/subject/term. Mitigated by the client-side disabled button and a single Principal user; reversible (delete one). Recommended fix: wrap in `DB::transaction()` with `lockForUpdate()` on the learner's open rows. Not implemented here (frozen `interventions` table; behaviour change).

## V. Performance / loading findings

In-process per page (queries / duplicate-SQL-text / ms / peak MB): Admin dashboard 47/14/343/26; Principal dashboard 107/55/742/50; Principal interventions 55/21/513/62; Adviser dashboard 44/18/223/32; every other page ≤ 41 queries and ≤ 300 ms. Over Apache (OPcache off): 0.3–1.7 s per page; Principal dashboard 1.7 s, Admin dashboard 1.5 s. No runaway N+1 at this data size. `admin/academic-terms` repeats the same query text 62 times across 6 terms (P4, 207 ms). Python launch ≈ 4 s, once per Submit Report. Built assets: 136 KB CSS + 79 KB JS (gzip 21 + 26 KB). Log file 17.8 MB — rotate after the demo. OPcache remains off (operator php.ini change, not made).

## W. Log / error findings

Today's `laravel.log` classes: **76 × "No application encryption key" + 1 × "Unknown database 'laravel'"** — all between 17:10 and 17:21, all produced by this audit's concurrent crawlers/load runs, all the S3 race (fixed; 0 new since); `testing.ERROR` entries (`source_filename`, `zip member`, `Analytics process failed`) — deliberate test-suite failures written to the live log because tests inherit `LOG_CHANNEL` (INFO: the suite's log noise lands in the production file); `local.ERROR` `Undefined array key "id"`, `Attempt to read property`, `Non-static method` — earlier sessions' scratchpad scripts, not the app; `--columns option does not exist` — this audit's own artisan call; `SQLSTATE 1052` — this audit's own invariant script. No unexplained current error remains.

**Audit trail of the incident (Section A):** activity_logs ids > 1963 added by this audit: 21 × `login`, 1 × `disable_user` (16:54:36), 1 × `close_term` (16:55:04), 1 × `open_term` (16:55:06). `users.id=2` restored to `is_active = 1, updated_at = 2026-09-20 15:30:17`; `academic_terms.id=1` restored to `opened_at = updated_at = 2026-09-19 22:57:17`, `is_open = 1` — both verified against the pre-audit dump. The three non-login rows were deliberately **left in place** rather than deleted.

## X. Dead / redundant code / files

| Candidate | Verdict |
|---|---|
| `dd(`/`dump(`/`var_dump(`/`console.log(` in app code | none found |
| `TODO`/`FIXME` | 4 comment cross-references to `TransmutationRangesSeeder`'s documented TODO — KEEP |
| `resources/views/errors/*` (no explicit reference) | KEEP — resolved by Laravel's exception handler; `ErrorPagesTest` |
| Auth controllers (not in `web.php`) | KEEP — registered in `routes/auth.php` |
| `dss:truncate`, `dss:remove-test-fixture-students`, `dss:fix-assessment-max` | KEEP — CLI-only, confirmation-gated; `dss:check-integrity` references `dss:truncate` |
| `database/database.sqlite`, `backups/test_inference_contract.py.before_20260921`, `analytics/model_accuracy.txt` | IGNORED BUT LOCAL — harmless; delete at leisure |
| `term1_intervention_remedial_mapping.csv` | SENSITIVE — IGNORED, 403; delete when no longer needed |
| Three `_audit_*.php` scripts this audit created at the project root | REMOVED before commit |

Nothing was deleted from the repository.

## Y. Automated test results

| Suite | Before fixes | After fixes |
|---|---|---|
| `php artisan test` (sqlite `:memory:`, guard in `tests/TestCase.php` verified) | **1,081 passed, 0 failed, 0 skipped, 5,331 assertions, 552.9 s** | **1,084 passed, 0 failed, 0 skipped, 5,336 assertions, 445.9 s** |
| `analytics/test_inference_contract.py` | 34 / 34 | 34 / 34 |
| `analytics/test_classify.py` | 6 / 6 | 6 / 6 |
| `analytics/test_training_pipeline.py` | 62 / 62 (candidate cleaned up; `promote` refuses synthetic by design) | 62 / 62 |
| `npm run build` | exit 0, assets byte-identical to the checked-in build | exit 0, new CSS hash `app-B6iIVwE2.css` (+1 utility) |
| `php artisan migrate:status` | 70 ran / 0 pending | 70 ran / 0 pending |
| `php artisan dss:check-integrity` | clean | clean |

§Y-2 — final PHP run: **1,084 passed, 0 failed, 0 skipped, 5,336 assertions, 445.9 s** (1,081 + the 3 new `EnvPutenvDisabledTest` tests).

## Z. Frozen-dataset before / after

| Figure | Pre-audit (16:47) | Post-audit | Freeze manifest |
|---|---|---|---|
| regular scores / support scores / total | 2,268 / 246 / 2,514 | same | same |
| grades (verified) | 252 (252) | same | 252 |
| risk_results (L/M/H) | 84 (36/32/16) | same | same |
| report_submissions / interventions | 2 / 82 | same | same |
| `SUM(score×id)` / `SUM(grade×id)` / `SUM(avg×id)` | 550,356,481.00 / 7,950,484.00 / 885,647.06 | same | same |
| content MD5 of assessment_scores, grades, risk_results, interventions, assessments, report_submissions, students, sections, subjects | 9 hashes | **all identical** | — |
| last write to any academic table | 14:02:40 | 14:02:40 | 14:02:40 |
| non-frozen tables that moved | — | `activity_logs` +24 (21 login + 3 probe rows), `sessions` (guest/curl sessions; expire) | — |

## AA. `model_cache.pkl` before / after

`14b01918f3b210777bf3013d1f56bba79062b20f3b13e2fd05ff8314027807cd` — 171,041 bytes — mtime 2026-09-07 21:39:04 — **unchanged** (checked at start, after the PHP suite, after the Python suites, and at the end). Registry directories empty throughout.

## AB. Git changes / commits

Working-tree changes made by this audit (committed as one audit commit — see the chat summary for the hash):

- `bootstrap/app.php` — `Env::disablePutenv()` with the reasoning (11 lines).
- `tests/Feature/EnvPutenvDisabledTest.php` — new, 3 tests.
- `resources/views/admin/sections.blade.php`, `resources/views/adviser/assessments.blade.php` — `flex-wrap md:flex-nowrap` on three toolbar rows.
- `FINAL_PRE_DEMO_AUDIT.md` — this report; `CLAUDE.md` — a short standing-conventions entry for the putenv rule and the new suite baseline.

Nothing under `database/`, `analytics/`, `app/`, or `config/` changed. `public/build` is git-ignored and was rebuilt locally.

## AC. Remaining known limitations (verified, still true)

- Deployed ML is the synthetic prototype; feature = `average_grade`; levels are calibrated thresholds; labels follow grade bands; **no real-world predictive validity is claimed or justified**.
- Intervention/remedial UAT evidence is synthetic (246 support scores, 92–97 %); observed +12 to +30 pp improvement is a before/after observation, not causation.
- No RiskResult history: a re-submission overwrites (`unique_risk_per_period`).
- Terms 2 and 3 hold no records; Philippine History and Society is configured Terms 2–3 only.
- 18 role-less additional-support Examination items are, by design, not counted in the Examination component; the Adviser page says so.
- Apache hardening and the putenv fix's *necessity* are machine-specific (XAMPP, ZTS module); Docker/Railway serve `public/` and env via `$_SERVER`, unaffected.
- OPcache off; ~0.3 s per request floor.
- Formal DepEd SRC/RCM/RFG remediation, subject teachers, per-learner electives, catalog linking of the seven live subjects — unchanged future work.

## AD. Demo cautions — actions for the presenter

1. **Do not** press Verify All, re-submit either Term 1 report, rerun analytics, upload Term 1 files, retrain/promote the model, or touch `analytics/models/`.
2. **Before presenting on any network:** either put the demo laptop on a private hotspot, or (recommended) set a MySQL root password and update `DB_PASSWORD` in `.env`, and add `bind-address=127.0.0.1` under `[mysqld]` in `C:\newxampp\mysql\bin\my.ini` (restart MySQL by hand — it is not a service). Alternatively disable the `mysqld` inbound firewall rule for the Public profile.
3. **Change the five account passwords** from Admin > Users (they still equal the seeded default that is committed to Git).
4. Re-check `curl -sI http://localhost/naggasican-dss/.env` → 403 and that `public/hot` does not exist.
5. Expect to see this audit's 21 `login` rows and the `disable_user` / `close_term` / `open_term` triplet (16:54–16:55) on Admin > Activity Logs; they are explained in Section W.
6. `php artisan optimize:clear` is **not** needed; config is deliberately not cached. Restart Apache from the XAMPP control panel if `bootstrap/app.php` was edited while it was running (PHP module re-reads files per request; no restart was needed here).
7. Consider stopping phpMyAdmin (or confirming it 403s from a second device) and deleting `term1_intervention_remedial_mapping.csv` when it is no longer needed.

## AE. Final verdict

**FINAL DEMO READINESS: READY WITH CAUTIONS.**

Evidence: all critical demo workflows render and behave correctly for all three roles over real HTTP and in a real browser at seven viewports; authorization is enforced server-side on every route and method; the one application defect that could visibly disrupt a demo (concurrent-request 500s) is fixed, reproduced before and after, and regression-tested; the frozen Term 1 dataset and the ML artifact are provably unchanged; integrity is clean; 1,081 (+3) PHP tests and 102 Python tests pass; the build is production. The cautions are environmental — an unauthenticated, network-reachable MySQL root and default account passwords — which do not affect the demo's function but are a real exposure on a shared network and require the operator's action (AD.2–3).

---

## Final issue table

| ID | Severity | Area | Issue | Evidence | User impact | Fixed? | Retested? | Demo blocker? |
|---|---|---|---|---|---|---|---|---|
| S3 | P1 | Runtime / Apache | Concurrent requests race on `putenv()`-backed `.env` → 500 "No application encryption key", env=production | 28 failures / ~300 req before; 0 / ~1,200 after; log 17:10–17:21 | Random 500s when two people browse at once | **Yes** (`Env::disablePutenv()`) | Yes (load ×2, 3 new tests, full suite) | No (fixed) |
| S1 | P1 (env) | MySQL / firewall | root no password, bound 0.0.0.0:3306, firewall allows inbound | `mysql -uroot` connects; `netstat`; `netsh` rule "mysqld" Public Allow | Anyone on the LAN can alter the frozen data | No (operator) | — | **Yes if demoed on a shared network; no on a private machine** |
| S2 | P2 (env) | Accounts | All 5 accounts use the committed seeded default password | `Hash::check` on live rows | Trivial login by anyone who reads the repo | No (operator) | — | No (caution) |
| U1 | P3 | Principal interventions | Check-then-insert duplicate guard without lock | Code: `InterventionController::store()/storeBulk()` | Rare duplicate open intervention on a double-fire | No (design change, frozen table) | — | No |
| R1 | P4 | UI (phone) | Toolbars clipped at ≤390 px on Sections and Assessments | Screenshots 360×800 | Buttons unreachable on a phone | **Yes** (`flex-wrap md:flex-nowrap`) | Yes (screenshots, desktop hash identical) | No |
| V1 | P4 | Performance | `admin/academic-terms` repeats one query text 62× | In-process log: 87 queries, 207 ms | None visible | No | — | No |
| W1 | INFO | Logging | Test-suite errors are written to the live `laravel.log` | `testing.ERROR` entries | Log noise | No | — | No |
| S4 | INFO | phpMyAdmin | Config-auth root, `Require local` not verified from a second device | curl from server on LAN IP → 200 | Possible DB UI exposure | No (operator) | — | No |
| O1 | INFO | ML | Null average → forest returns `moderate` @ 62.5 % | Scratch run | Unreachable in the real flow | No | — | No |
| W2 | INFO | Audit trail | 3 probe rows + 21 logins in `activity_logs` | ids > 1963 | Visible on Activity Logs | Rows restored; log kept | Yes | No |
| X1 | INFO | Files | Root mapping CSV, sqlite artifact, 17.8 MB log | git-ignored, 403 | None | No | — | No |
