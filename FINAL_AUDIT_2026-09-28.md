# Final end-to-end audit — 2026-09-28

Scope: every user-facing module of the Naggasican NHS Learner Academic Performance DSS in the
current working checkout (uncommitted, branch `rule-based-academic-monitoring`). Companion
document: `RELEASE_READINESS_2026-09-28.md` (roles, rules, demo walkthrough, deployment and
recovery). No credentials or learner data appear here. Nothing was committed, pushed or
deployed. The working database was only read.

## 1. How it was verified

| Layer | What ran | Result on the final code |
|---|---|---|
| Laravel suite | Unit, then Feature in 4 chunks (A–D / E–L / M–R / S–Z) | **1,218 passed, 10,000 assertions**, 0 failures, 0 skipped |
| JavaScript | `node --test tests/js/*.mjs` | 15/15 |
| Browser (isolated SQLite) | `tests/Browser/run.mjs` | 160/160 |
| Link crawl, all 4 roles | `tests/Browser/crawl.mjs` (new) | 232 URLs, all HTTP 200; no error text; no console errors |
| Slow network / failure / concurrency | `tests/Browser/netfail.mjs` (new) | 15/15 |
| DB-level concurrency (isolated MySQL) | `tests/Browser/verify-race.mjs` (new), 3 runs | correct in all 3, after one fix (§3) |
| Bounded load (isolated MySQL) | `tests/Browser/loadtest.mjs` (new), 4 / 8 / 16 users | 0 errors (§5) |
| Build / diff | `npm run build`, `git diff --check` | pass / clean |
| Integrity | `php artisan dss:check-integrity`, `migrate:status` | clean; 0 pending |
| Dependencies | `composer audit`, `npm audit --omit=dev` | no advisories; 0 vulnerabilities |
| Docker image | — | **NOT VERIFIED** (Docker not installed) |
| Real phone / screen reader / physical print | — | **NOT VERIFIED** (manual) |

**Isolation.**
- Every mutating browser, load and concurrency test used synthetic data only:
  - `storage/framework/browser-testing/browser.sqlite`; or
  - the MySQL database `naggasican_dss_loadtest`.
- The guard in `tests/Browser/isolation.php` refuses any other database. It was proved by
  pointing it at the working database: it refused.

## 2. Module status

"Evidence" names the automated test or harness that exercised each module. PASS means the
module's success, failure and permission paths were exercised.

**Authentication — PASS.**
- Login success and failure, throttling and disabled accounts: `FinalAuditScenariosTest`,
  `LoginRequest` tests.
- Logout and draft purge: `run.mjs`.
- JSON requests from disabled or ended sessions: `FinalAuditSaveSafetyTest`,
  `netfail.mjs` S5/S6.

**Admin:**
- **Users — PASS.** Create, edit and disable: existing tests. Role change with the new
  stranding guard and logging: `ReleaseReadinessGuardsTest`.
- **Students and learner imports — PASS.** `AdviserCannotAddOrImportStudentsTest`, the import
  tests, `MimesFixSixMoreRoutesTest`.
- **Sections, subjects, tracks, specializations — PASS.** Subject/Section tests; crawl.
- **Academic years and terms — PASS.** `AcademicHistoryArchitectureTest`; `netfail.mjs` S4
  (term closed mid-session).
- **Teaching assignments (assign, change teacher, remove, history) — PASS.**
  `TeachingAssignmentEditTest`, `DemoFlowEndToEndTest`, `run.mjs`.
- **Reports and activity logs — PASS.** Crawl; `RoleAccessMatrixTest`.

**Adviser:**
- **Dashboard, My Students, Advisory Overview — PASS.** Crawl; `AdviserReportingUxTest`.
- **My Classes, Class Record, Add/Edit Assessment — PASS.** `ClassRecordUiTest`,
  `SubjectTeacherClassRecordTest`, `run.mjs`, `netfail.mjs`.
- **Encode Grades — PASS.** `EncodeVerifyGradesSplitTest`, `GradeEncodingScopeTest`.
- **Verify Grades — PASS after one fix (§3).** `EncodeVerifyGradesSplitTest`,
  `verify-race.mjs`.
- **Submit Report — PASS.** Existing report tests; crawl.
- **Intervention acknowledgement and delivery — PASS.** `AdviserDeliveryWorkflowTest`,
  `DemoFlowEndToEndTest`.
- **Learner report, Excel, Copy Scores — PASS.** `IndependentGradeCalculationTest`,
  `SubjectTeacherClassRecordTest`; print-to-PDF in `run.mjs`.

**Subject Teacher:**
- **My Classes, Class Record, assessments, scores, reports and exports within assigned classes —
  PASS.** Access outside assignments is refused. Evidence: `RoleAccessMatrixTest`,
  `DemoFlowEndToEndTest`, crawl.

**Principal:**
- **Dashboard, Academic Monitoring, Students, Subject Analysis, Reports — PASS.**
  `PrincipalAcademicMonitoringTest`, `SubjectFirstMonitoringTest`, crawl.
- **Concern review and intervention decisions — PASS.**
  `PrincipalConcernReviewAndInterventionTest`, `DemoFlowEndToEndTest`.

**Grading engine — PASS.**
- Hand-computed fixtures: `GradingEngineTest` (the 81.11 and 68.5 worked examples) and the new
  `IndependentGradeCalculationTest`. The latter uses a DO 015 subject with ST1/ST2/TE and an
  additional-support item: 82.00 / 80.00 / 67.50 → 76.65. The Class Record, Verify Grades, the
  Excel export and the stored official grade all match it.

**Monitoring (concerns) — PASS.**
- Missing vs blank vs 0; official failing subject with a passing average; distinct-learner
  counts; review, delivery and intervention never resolving; evidence-driven resolution.
  Evidence: `DemoFlowEndToEndTest`, `AcademicConcernTest`, `SubjectFirstMonitoringTest`.

**Legacy attendance — PASS (archival only).**
- No writer, no rule, no reader outside history protection. 0 working rows; 12 historical rows
  preserved in the 2026-09-27 pre-demo-reset backup.

**Retired routes — PASS.**
- Old Assessments bookmarks redirect to an authorized Class Record. Retired upload routes answer
  404/405 and write nothing: `ClassRecordConsolidationTest`, `RetiredAssessmentUploadTest`.

## 3. Defects found and fixed in this audit

1. **HIGH — misleading "Saved" and a lost draft for a disabled account.**
   - Found by code inspection, reproduced by `netfail.mjs` S5.
   - Cause: `EnsureAccountIsActive` answered a JSON save with a redirect to /login. `fetch()`
     followed it to a 200 HTML page, and the Class Record treated any 2xx as success: it marked
     the cells saved and dropped the local draft, while nothing was written.
   - Fix:
     - the middleware returns **401 JSON** for JSON requests;
     - `class-record.js` accepts a save only when the endpoint confirms it (2xx, not redirected,
       a `changed` count present);
     - 401/419 now say "Your session has ended…" and keep the entries on the page.
   - Tests: `FinalAuditSaveSafetyTest`, `netfail.mjs` S5/S6.
2. **MEDIUM — false conflict after a lost response.**
   - When the server committed but the browser never got the answer, the retry was reported as
     a conflict against the user's own value.
   - A cell already holding exactly the requested value is now treated as an idempotent retry
     (`changed: 0`, no second write, no second history row). A genuinely different value is
     still a 409 conflict.
   - Tests: `FinalAuditSaveSafetyTest`, `netfail.mjs` S2.
3. **MEDIUM — raw SQL error shown to the adviser under simultaneous bulk verification.**
   - Found only against real MySQL (`verify-race.mjs`).
   - Cause: the roster read inside the bulk transaction came before the row lock. Under
     REPEATABLE READ that fixed the transaction's snapshot, so a waiting second batch still saw
     "no grades" and hit a duplicate key. The data stayed correct (rolled back), but the adviser
     saw "SQLSTATE[23000]…".
   - Fix: read the roster before the transaction, make the lock the first read, map a unique-key
     collision to a plain 409 "the eligible learners changed", and never show database text.
   - 3 re-runs: every request applied, refused as changed, or reported unchanged; one grade and
     one log entry per learner.
4. **LOW — Principal › Interventions empty state clipped at tablet/phone width.**
   - It sat inside the wide scrolling table.
   - Now rendered outside the table, with an accurate hint (interventions come from Academic
     Monitoring or Students, not only Students). `FinalAuditUiTest`.
   - The same pattern exists in 20 other tables. A shared CSS rule now keeps any empty state
     inside a `.tbl-scroll` table pinned to the visible width. Measured at 390 px with the table
     scrolled to both ends: the message stays fully visible.
5. **LOW — Verify Grades filters cramped at 768–1279 px** (values truncated). They are now two
   per row until 1280 px.

Earlier the same day, documented in `RELEASE_READINESS_2026-09-28.md`: the role-change stranding
guard, the security patch updates, and test logs no longer polluting the operational log.

## 4. Cleanup

- **Removed `app/Services/Grade12DiscrepancyChecker.php`.**
  - It had zero references in app, tests, routes, config and views, including as a class-name
    string. It was a remnant of the retired upload flow.
  - The autoloader was rebuilt.
  - `NoOperationalMlDependencyTest` makes exactly 14 fewer assertions: it scans every service
    file for 14 patterns.
- **Deliberately kept:**
  - `DssAssessmentRecord`, `EcrReaderService`, `EcrProfileDetector` and `AssessmentUploadService`,
    which Admin's learner import still uses.
  - The legacy attendance table and model, and historical migrations.
  - All backups and logs.

## 5. Slow network, failure, concurrency and load

**Browser scenarios** (`netfail.mjs`, isolated, 45-learner class). All 15 pass:
- **S1:** 2 s latency and five rapid clicks produce one request; "Saving…" shows until the
  server answers.
- **S2:** the response is lost after the commit. The page shows a failure with the entry kept;
  the retry succeeds with no conflict and no second write.
- **S3:** two tabs editing different learners both save; the same cell from two tabs gives the
  second save a conflict, and the first value is kept.
- **S4:** the term closed mid-session refuses the save, and the value is unchanged.
- **S5:** an account disabled mid-session gets an explicit refusal; the entry and draft are kept
  and nothing is written.
- **S6:** an expired session says "session has ended", keeps the entries and writes nothing.

**Bounded load** (`loadtest.mjs`).
- **Setup:**
  - isolated MySQL database (`naggasican_dss_loadtest`);
  - 4 single-threaded PHP built-in servers, OPcache off (this dev box);
  - real sessions with CSRF;
  - half the users save scores in their own learner rows; the rest read Verify Grades, Advisory
    Overview, Academic Monitoring and the Principal dashboard.
- **Results:**
  - 4 users / 20 s: 64 requests, all 200. p50 about 0.7–2.0 s; p95 at most 2.7 s.
  - 8 users / 30 s: 140 requests, all 200. p95 2.3–3.2 s. 43 saves, 0 conflicts.
  - 16 users / 30 s: 134 requests, all 200. p95 5.4–7.7 s. 39 saves, 0 conflicts.
- Throughput levels off at about 4.5 requests per second, which is the 4 single-threaded servers
  saturating. There were **no errors at any level**.
- This is the capacity of this local test setup only; it says nothing about production capacity,
  where Apache/PHP-FPM with OPcache behaves differently.

## 6. Security review

- **Server-side authorization on every route:** `RoleAccessMatrixTest` walks the live route table
  for all four roles plus guests and disabled sessions. Forged learner/assessment IDs are refused
  (`DemoFlowEndToEndTest` F); stale tabs lose write access on teacher replacement, term closure or
  disabling.
- **CSRF:** the middleware is active with no exemptions.
- **SQL:** raw SQL appears only with constants.
- **Output escaping:** no `{!! !!}` in views; `XssEscapingAcrossRolePagesTest` passes.
- **Mass assignment:** no `$guarded = []`.
- **Uploads:** extension-validated, stored under random names on the private disk; no
  user-controlled paths.
- **Spreadsheets:** exports write no formulas, and text is stored as strings.
- **Error output and cookies:** `APP_DEBUG=false` in `.env` and in the image. Session cookies are
  HTTP-only, SameSite=Lax, and Secure in the image.
- **Local web boundary:** `.env`, logs, backups, the test harness and the test database all
  return 403 on Apache.
- **Deployment image:** excludes tests, backups, `.env*`, SQL and spreadsheets.
- **Dependencies:** `composer audit` has no advisories after the patch updates; `npm audit` shows
  0 production vulnerabilities (1 low, dev-only, in esbuild).
- **Remaining gate:** every working account still uses the old shared default password. The
  Admin must change them privately before any public access. Passwords were not changed or shown
  here.

## 7. Data integrity

- `dss:check-integrity` finds no issues, and no migrations are pending.
- The working database's academic tables are identical to backup
  `naggasican_dss_pre_release_readiness_20260928_203747.sql`, which was restore-tested (38 tables
  matched).
- Since that backup the activity log gained 12 entries, all application use: 10 logins and the
  Admin's 2 role changes.
- **Configuration needing an Admin decision:** section **Shakespeare now has no adviser**. The
  Admin cleared it and moved user 10's role. Shakespeare has no learners, so nothing is blocked
  today; assign an Adviser account on Admin > Sections before it is used.

## 8. Policy decisions still open (school)

1. Whether a corrected official grade stays official (it does today).
2. Whether a correction needs a reason (not today; old and new values are logged).
3. Whether a typed grade can become official without verification (never today).
4. Whether Subject Teachers may verify (not today).
5. Attendance monitoring rules — not implemented, not approved.
6. Mapping the export to the official DepEd workbook — pending. The download is labelled a
   *system export*.

## 9. Manual UAT additions (beyond `RELEASE_READINESS_2026-09-28.md` §7)

- [ ] With a Class Record open, the Admin disables that teacher; the teacher presses Save
  [not saved; "account has been disabled"; entries stay on the page]
- [ ] Throttle the network in the browser dev tools (Slow 3G), then Save and click repeatedly
  [one save; "Saving…" until done]
- [ ] Stop the Wi-Fi right after pressing Save, then reconnect and Save again
  [no duplicate; "No changes to save" if the first save had arrived]
- [ ] Two advisers' sessions press Verify all eligible at the same time
  [one verifies; the other is told the set changed; nothing is duplicated]
- [ ] Principal › Interventions on a tablet with no interventions
  [the message is fully readable]

## 10. Readiness

- **A. Final defense / demo — READY WITH STATED LIMITATIONS.**
  - All automated layers pass on the final code.
  - Limitations to state:
    - the export is not the DepEd template;
    - attendance is not implemented;
    - the policy decisions above are open;
    - real-device accessibility is unverified.
  - Shakespeare's missing adviser does not affect a demo using Agila.
- **B. Deployment preparation — PREPARED, BLOCKED BY GATES:**
  - the Docker image has not been built or booted;
  - account passwords must be rotated;
  - the policy decisions above;
  - Shakespeare's adviser, if that section will be used.
- **C. Production acceptance — NOT VERIFIED.** No deployed environment was tested.

No claim is made that undiscovered defects do not exist.
