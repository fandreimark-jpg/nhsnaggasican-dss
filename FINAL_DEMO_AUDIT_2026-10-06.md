# Final demo audit — 2026-10-06

**Verdict: CONDITIONALLY READY.** The planned demo flows pass on the isolated fixtures and load on the real working data. The working data itself is not yet configured for every role and section; see §8 and the rehearsal checklist. Passing tests do not mean "zero errors" or production readiness.

Branch `rule-based-academic-monitoring` at `76e2306`. There are 285+ uncommitted paths from this and earlier sessions, all preserved. Nothing was committed, pushed or deployed.

## 1. Environment (as audited)

| Item | Value |
|---|---|
| App | Laravel **12.69.3** (was 12.62.0, patched in this audit), PHP 8.2.12 (ZTS, XAMPP), Node 24.14 |
| Database | MariaDB 10.4.32 (XAMPP), database `naggasican_dss`; 81 migrations ran, **0 pending** |
| Web server | Apache 2.4.58 (console mode), serving `public/` only; repo files return 403 |
| Key `.env` flags | `APP_DEBUG=false`, `APP_ENV=local`, `LOG_LEVEL=error`, sessions/cache/queue on the database, `MAIL_MAILER=log`. No queue worker runs and the jobs table is empty. |
| Routes | 107, after removing the 2 unused `storage/{path}` routes. Grouped by role: admin 52, adviser 19, adviser+subject_teacher 12, principal 12, any signed-in user 4, public 8 (login, password reset, `/`, `/up`). |
| Roles | `admin`, `adviser`, `subject_teacher`, `principal`, confirmed in `User::ROLES` and in the route middleware |
| Assets | `npm run build` succeeded. The JS bundle went from 104.5 kB to 58.7 kB after removing unused axios. |

## 2. Baseline (before any fix)

`php artisan test tests/Unit` and the four Feature chunks, plus `node --test tests/js/*.mjs`:
- Unit 101 passed.
- Feature `[A-D]` 436, `[E-L]` 232, `[M-R]` 240 + **1 failed**, `[S-Z]` 281.
- JS 19 passed.

The single failure, `RoleAccessMatrixTest > cross object and cross role mutations are refused`, was caused by **my own** mid-run change: I had switched off the storage routes, so the route now returned 404 instead of 403 (§4, F-5). It is not a pre-existing defect. There were no other pre-existing failures.

## 3. Final results (after all fixes, actually run)

| Check | Result |
|---|---|
| PHPUnit (`tests/Unit` + 4 Feature chunks) | **1,293 passed**: 101 + 438 + 232 + 241 + 281. 6 tests are new. |
| JS (`node --test tests/js/*.mjs`) | **19/19** |
| `composer audit` | **No advisories** (2 low before) |
| `npm audit` | 9 remaining, all in build tooling (§6). axios advisory removed. |
| Browser `run.mjs`, isolated SQLite | **165/165** in the final rehearsal. One audit-time run hit "Chrome did not start" (a harness startup issue). |
| Browser `concern-flow.mjs` | **30/30**, three times |
| Browser `netfail.mjs` (slow, offline, lost response, conflict) | **15/15** |
| Browser `crawl.mjs` (every reachable GET per role) | admin 36, adviser 90, subject_teacher 2, principal 90 URLs; all 200; 0 broken links, error text or console errors |
| `verify-race.mjs` (4 isolated MySQL servers) | 12 runs after the fix with no "database error" and no duplicate grades. Concerns equal failing verified grades in the 6 runs checked. Before the fix: 2 of 4 runs failed (F-2). |
| `loadtest.mjs`, isolated MySQL, 4 single-threaded `php -S`, 30 s per level | See §7 |
| Auth probes, isolated server (Node) | Missing CSRF token → 419. 6 wrong passwords then the correct one → refused with "too many" shown. Logout, then dashboard → redirect to login. Principal POSTs to save/verify and GETs an admin page → 403 ×3. |
| Working-database checks, read-only | 46 role pages → 200 with checksums unchanged (nothing written); `dss:check-integrity` reports nothing; roster checks in §8 |

## 4. Findings by severity

| ID | Severity | Role / workflow | Status |
|---|---|---|---|
| F-1 | **High (data/ops)** | All: MySQL **and** Apache were found stopped during the audit | Restarted; data verified (below) |
| F-2 | Medium | Adviser: concurrent Verify Grades | **Fixed** |
| F-3 | Medium | Admin: master-data deletes left no audit trail | **Fixed** |
| F-4 | Medium | Admin: Import Students, long or padded cells | **Fixed** |
| F-5 | Low (security hardening) | Public `storage/{path}` GET/PUT routes, unused | **Fixed** (removed) |
| F-6 | Low (dependencies) | Laravel < 12.69 debug-page XSS; flysystem ≤ 3.35.2 path check; axios advisories | **Fixed** (patched / removed) |
| F-7 | Low (test harness) | `run.mjs` intermittently 164/165 at "Copy (scores only)" | **Fixed** (harness race) |
| F-8 | Info | XAMPP `/dashboard/` page reachable; `Server` / `X-Powered-By` headers disclose versions | Not changed: machine config outside the repo |

**F-1: MySQL and Apache stopped.**
- *Seen:* during the final load test, every virtual user failed and `mysql` returned `ERROR 2002 … (10061)`.
- *Facts:* `mysqld` and `httpd` were both gone. The MariaDB error log has no shutdown or crash entry after its 06:52 start, and the restart at 13:28 ran *InnoDB crash recovery*, which means the stop was not clean.
- *Likely cause:* both run as console processes here, so closing or ending those consoles (or the session) stops both. Not proven.
- *Action:* started both with the XAMPP commands (`mysqld --defaults-file=mysql\bin\my.ini --standalone`, `apache\bin\httpd.exe`).
- *Verified afterwards:*
  - `CHECK TABLE` OK on students, enrollments, assessments, activity_logs and concerns.
  - 50 learners, 50 distinct LRNs and 50 enrollments; activity_logs still 2,335, the same as before.
  - `dss:check-integrity` clean; 46/46 pages 200; login 200; `.env` 403.
- *Before the demo:* check that both consoles are running and leave them open.

**F-2: Verify Grades deadlock surfaced as an error and left concerns unsynced.**
- *Reproduce:* `verify-race.mjs`. Two bulk "Verify all eligible" requests and two individual verifies of the same section run at once on isolated MySQL.
- *Expected:* each request is applied, refused as changed (409) or reported unchanged.
- *Actual (2 of 4 runs):* HTTP 422 "Verification stopped … because of a database error". Server logs showed `1213 Deadlock found` between the batch's `SELECT … FOR UPDATE` on the roster and a simultaneous `INSERT INTO academic_concerns`.
  - A second deadlock site, `OfficialGradeSync` → `AcademicMonitoringService::evaluateScope`, was reported and **swallowed**. That section's failing-grade concern then stayed unsynced until the next sync.
- *Root cause:* every transaction ran once (`DB::transaction` attempts = 1), so InnoDB's deadlock victim was never retried.
- *Fix:* `DB::transaction(…, 3)` on both verify endpoints and the monitoring sync.
  - Laravel then rolls back and re-runs only on a concurrency error.
  - The accumulators are reset at the start of each attempt.
  - Files: `app/Http/Controllers/Adviser/GradeController.php`, `app/Services/AcademicMonitoringService.php`.
- *Verified:* 12 race runs after the fix with no "database error" response. In the 6 runs where I checked, open subject-performance concerns equalled verified failing grades, with 0 duplicate grades. The full suite also passes.
- *Limit:* the deadlock cannot be forced on demand, so this rests on repeated runs, not a deterministic test.

**F-3: Admin deletes were not logged.**
- *Seen:* a working-data subject (General Science) disappeared with no log entry.
- *Cause:* `destroy()` in the Subject, Section, Specialization, Track and User controllers never called `LogActivity`. Every other admin mutation does.
- *Fix:* each successful delete now logs `delete_<type>` with the actor and the record id. A refused delete logs nothing.
- *Test:* `tests/Feature/AdminDeleteAuditLogTest.php` (2 tests).

**F-4: Import Students edge cases.**
- *Cause:*
  - `middle_name` had no validation rule. On MySQL in strict mode, a value over 255 characters fails the whole 200-row insert batch. SQLite silently accepted it.
  - `middle_name` was not trimmed, and a blank one was not stored as null.
  - Stray spaces in `lrn` or `gender` (`"Female "`) rejected otherwise valid rows.
- *Fix:* added `'*.middle_name' => nullable|string|max:255`; `prepareForValidation()` trims every string cell; `middle_name` is trimmed and a blank becomes null. File: `app/Imports/StudentsImport.php`.
- *Test:* `tests/Feature/StudentImportEdgeCasesTest.php` (4 tests):
  - an over-long name is a rejected row and the valid row is still imported;
  - Unicode, apostrophes and padding are kept but trimmed;
  - a numeric 12-digit LRN cell from an .xlsx is accepted;
  - re-uploading the same file creates no duplicate learner or enrollment.

**F-5: Unused public storage routes.**
- *Seen:* `GET`/`PUT /storage/{path}` were public. They answered 403 to unsigned requests, but nothing in the app uses signed URLs.
- *Fix:* `config/filesystems.php` local disk `'serve' => false`, which removes both routes.
- `RoleAccessMatrixTest` now asserts that no `storage/` route exists and that both probes return 404.

**F-6: Dependencies.**
- `composer update laravel/framework league/flysystem league/flysystem-local --with-dependencies`, within the existing `^12` constraint.
  - Result: laravel/framework 12.62.0 → 12.69.3, flysystem 3.34.0 → 3.36.0, plus Symfony 7.4.x, Carbon and Monolog patch/minor updates.
  - The old lock file is saved in the session scratchpad.
- axios was bundled but never used: `resources/js/bootstrap.js` only set `window.axios`, and nothing read it. I removed it (`npm uninstall axios`) and deleted that file and its import.
- `npm audit fix` without `--force` changed one package (source-map-js 1.2.1 → 1.2.2).
- The full suite, JS tests, build and browser suites passed afterwards.

**F-7: Harness race.**
- `run.mjs` read the clipboard after a fixed `sleep(600)`, sometimes before the second (scores-only) copy request finished. This was the "unexplained 164/165" seen earlier.
- It now waits for the scores-only status message. App behaviour was already correct: that status is set only after the clipboard write.

## 5. Workflow and policy coverage

**Automated (PHPUnit, 1,293 tests).** Covered:
- the role matrix, read from the live route table;
- object-level refusals;
- open-term and active-year write locks;
- teaching-assignment-only writes, including advisory without an assignment;
- Class Record save scope and auto-Missing;
- a 0 score stays 0; unsent cells untouched;
- all-or-nothing saves, 409 on conflict, idempotent retry;
- read-only preview;
- additional-support applicability;
- duplicate exam blockers;
- unconfigured "Other elective" profiles;
- verification, stale and changed states;
- concern lifecycle;
- intervention lifecycle;
- imports and exports (formula-safe text);
- workbook parity: the oracle, 9 profiles × 30 cases, and the Excel-verified CSVs.

**Browser (isolated).** Covered:
- live preview, with late responses ignored;
- drafts across reload, per user and class;
- Clear, Undo, Discard;
- paste and keyboard entry;
- offline save, then retry;
- two-tab conflict;
- copy and download;
- 390 / 768 / 1366 / 1920 px widths and 200% zoom;
- concern flow: Missing → concern → score → resolved, with history kept;
- slow network, lost response and session expiry (`netfail`).

**Grading and workbook.**
- **Excel-verified (Microsoft Excel 16.0.20430):** all 10,001 direct Initial Grades from 0.00 to 100.00 match the system, including 69.98→74, 69.99→75, 70.00→75, 71.16→75, 71.17→76, 71.18→76, 74.70→78, 74.71→78 and 74.72→79.
- **Component sums** (16 Excel cases) match **except** `0 + 46 + 28.71`:
  - Excel's SUM gives 74.710000000000008, and its XLOOKUP returns **79**;
  - the system adds exact hundredths and gives **78**.
  - This was not concealed and is pinned by an explicit test.
- **Evaluator-only** (PhpSpreadsheet, not Excel): the 9-profile sweep.
- Files: `docs/excel-validation/`, `WORKBOOK_PARITY_FOLLOWUP_REPORT_2026-10-06.md`.

**Open school-policy questions (not decided here):**
1. Should the 74.71-type binary-sum result (Excel 79) be reproduced? It is +1 Term Grade, only exactly on a band maximum, and never changes pass/fail.
2. 69.99 → 75 follows the workbook formula; the printed DO 015 table says 74.
3. "Other elective" weights: who sets them, and what are they? Unsupported until decided.
4. D-01 corrections to official grades, and the 74.01–74.99 shading.
5. Additional-support counting for recipients: additive, replacement or capped.

## 6. Security review

- **Static checks:**
  - no `{!! !!}` in views;
  - every raw SQL call is constant or uses bindings;
  - no whole-request mass assignment;
  - no secrets in tracked files (`git ls-files` / `git grep`).
- **Exposure:** repo files return 403 through Apache (`.env`, logs, `composer.json`, tests, docs, maintenance script). `public/` holds only build output, images, js, vendor assets and `index.php`.
- **CSRF and throttling:** live on the isolated server (§3).
- **Not run:** stored-XSS probes against the live database.
- **Remaining npm advisories** (all build-time only, nothing shipped to browsers): braces, chokidar, micromatch, fast-glob, postcss-nested, postcss-selector-parser via `tailwindcss` 3. The fix is a **major** upgrade to Tailwind 4, so it was not applied.
- **Not changed:** the XAMPP `/dashboard/` page and server version headers (machine config).

## 7. Performance (isolated MySQL, local machine)

Workload: real HTTP sessions with CSRF login. Teachers save their own row of a 45-learner Class Record; advisers and the principal read Verify Grades, Advisory Overview, Academic Monitoring and the Dashboard. Each level ran for 30 s against 4 single-threaded `php -S` servers with OPcache off.

| Virtual users | Requests | Statuses | Saves | Errors | Slowest p95 |
|---|---|---|---|---|---|
| 4 | 128 | 128 × 200 | 28 | 0 | Advisory Overview 1.70 s |
| 8 (after fixes) | 146 | 146 × 200 | 43 | 0 | Principal dashboard 2.79 s |
| 16 (after fixes) | 144 | 144 × 200 | 41 | 0 | Principal dashboard 5.03 s |

After the runs: 0 duplicate score rows and 0 cells with both a score and a Missing marker.

This is a local development-server measurement, not a production capacity figure. With one-thread servers, latency grows roughly linearly with the number of users.

## 8. Working-data readiness (read-only)

- **Learners: imported.** 4 sections, all 2026-2027:

  | Section | Grade | Learners |
  |---|---|---|
  | Curie | 11 | 13 |
  | Agila | 12 | 13 |
  | Shakespeare | 11 | 13 |
  | Steve | 12 | 11 |

  - 50 learners, 50 distinct 12-digit LRNs, no padded names.
  - Exactly one enrollment each, matching the learner's section.
  - One last+first name pair repeats; their LRNs differ, so treat it as two people unless told otherwise.
- **Accounts:** 1 admin, 1 principal, 4 advisers (one per section). **No `subject_teacher` account exists**: Bosco's role was changed to adviser at 09:50. The Subject Teacher role can only be shown with the isolated fixtures unless the Admin creates one.
- **Teaching assignments:**
  - Curie › Effective Communication T1 → Bosco.
  - Agila › Community Engagement T1–T3 → Cruz.
  - **Shakespeare has no assignment**, so nobody can encode its Effective Communication.
  - **Steve has no applicable subject in Term 1.** Its STEM specialization doesn't match the only Grade 12 elective (HUMSS), and General Science was deleted.
- **Evidence:** 1 assessment (Agila "Quiz 1"), 0 scores, 0 grades, 0 concerns.

These are configuration choices for the Admin, not code defects. I changed none of them.

## 9. Files changed in this audit

- **App:**
  - `app/Imports/StudentsImport.php`
  - `app/Http/Controllers/Adviser/GradeController.php`
  - `app/Services/AcademicMonitoringService.php`
  - `app/Http/Controllers/Admin/{Subject,Section,Specialization,Track,User}Controller.php`
  - `config/filesystems.php`
  - `resources/js/app.js`
- **Removed:** `resources/js/bootstrap.js`. Evidence: its only content set `window.axios`, and a grep of `resources/`, `public/js` and the tests found no reader.
- **Dependencies:** `composer.lock`, `package.json`, `package-lock.json`; `public/build/` rebuilt.
- **Tests:**
  - New: `tests/Feature/StudentImportEdgeCasesTest.php`, `tests/Feature/AdminDeleteAuditLogTest.php`.
  - Updated: `tests/Feature/RoleAccessMatrixTest.php` (storage routes gone, not weakened), `tests/Browser/run.mjs` (copy wait).

**Not removed (usage uncertain or needed):** 3 temp upload files in `storage/app/private/temp_*` (left over from the retired upload feature; not web-served); all backups, rehearsal databases, migrations, fixtures and reports.

## 10. Not tested / limitations

- No real phones or screen readers. Mobile layout was checked only with Chrome emulation at 390/768/1366/1920 px and 200% zoom.
- No Excel check for profiles other than core 20/50/30 (those were checked with the evaluator only).
- No production or hosting environment (Railway, Docker) and no HTTPS.
- No stored-XSS or object-ID probes against the working database; those ran only against the isolated fixtures and tests.
- The deadlock fix rests on repeated races, not a deterministic test.
- The cause of the MySQL/Apache stop is not established.

## Demo rehearsal checklist

1. Confirm the MySQL and Apache consoles are running. `http://localhost/naggasican-dss/public/login` should return the login page.
2. Admin: decide whether to (a) create a Subject Teacher account and assign Shakespeare › Effective Communication, and (b) give Steve an applicable Grade 12 subject, or else leave those sections out of the demo.
3. Admin: log in, then check the Dashboard counts (50 learners, 4 sections), Students, Sections and Teaching Assignments.
4. Teacher, using the assigned account (e.g. Agila adviser Cruz for Community Engagement):
   1. Class Record → Add Assessment.
   2. Type scores and watch the running grade update.
   3. Save. Blanks become Missing; a 0 stays 0.
   4. Copy and Download Excel.
5. Adviser: Advisory Overview, then Verify Grades. A learner is only eligible once every component and every exam type given has a score.
6. Principal: Academic Monitoring shows the Missing concern. Record an intervention and decide it. Then, as teacher, replace the Missing with a score; the concern resolves and its history is kept.
7. Before showing the Term Grade, have an answer ready on the 69.99 and 74.71 policy questions (§5).
