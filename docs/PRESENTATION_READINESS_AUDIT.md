# Presentation readiness audit ? 13 September 2026

The automated audit preserves the existing academic-year/date editing work. No commit or push was made. Browser UAT remains required; automated HTTP tests do not establish visual or projector readiness.

**Critical fixes**

- UserController now constructs an unsaved User, sets the validated role, then saves once. Required fields and active status are present in the initial INSERT. Role remains protected from mass assignment. Factory afterMaking hooks already set role before insertion. DatabaseSeeder now uses firstOrNew and preserves existing accounts and active singleton roles.
- Every web request rechecks the authenticated account from the database. Disabled or invalid-role sessions are logged out, invalidated, given a fresh CSRF token, and redirected to login. Disabled login receives: ?Your account has been disabled. Contact the administrator.?
- Empty previous terms cannot unlock Terms 2 or 3. Populated sections without subjects block progression. Completion counts only students currently belonging to the section and subjects expected for that term. Term switches close/open within a transaction with row locks.
- Student, Subject, Section, Track, Specialization and User model deletion events reject referenced records. The map covers existing schema tables, including scores, grades, assessments, uploads, submissions, risk results, interventions, section-subject mappings and user audit/decision attribution. Sections with assigned advisers are protected. User deletion no longer detaches sections or nulls grade attribution. Existing unused-record deletion remains available.
- Analytics files use a UUID per execution. Argument-array process invocation has a 120-second timeout, validates JSON and expected learner IDs/risk labels, and removes both files in finally paths. Missing Python or invalid output produces the existing submission warning. Missing deployed model now fails instead of automatically training one.
- Bootstrap Icons 1.11.3 and Chart.js 4.4.0 use local copies of the exact prior CDN assets. Their copyright headers are retained.

**Security review**

All Admin routes have auth and role:admin. Existing direct HTTP authorization and cross-section tests cover role boundaries and adviser scoping. Login regenerates session IDs; logout invalidates the session and regenerates CSRF. Password writes use hashing. Role and active status remain excluded from user mass assignment.

Static scans found no raw Blade output and no POST forms missing CSRF directives. All literal navigation route names resolve after removal of unused starter navigation. Inspected raw SQL expressions are fixed aggregate expressions or use parameter bindings. Upload rules restrict extension and size, then parse/validate workbook structure; they deliberately accept genuine ECR files whose MIME is application/octet-stream. Stored preview files have generated names on the private local disk. Existing ECR tests cover Grade 11/12 formats, blank versus zero, duplicate/conflict handling, preview confirmation, rollback and scope. No importer or grading redesign was performed.

Tracked-file secret screening found only the example environment template as a candidate; its application key is empty and database-password setting is commented. .env is not tracked. Spreadsheet fixture shared strings contained no 12-digit identifiers; this is a limited automated screen, not certification of every field or Git-history object. Use authorized anonymized demo data and do not project private learner details.

**Database and migrations**

The live integrity command reports ?No orphaned or inconsistent data found.? All migrations were already applied, including the pre-existing untracked 2026_09_12_210000 term-date migration. Normal migrate reports nothing to migrate. No new migration was created in this audit and no historical migration was rewritten. Historical cascade constraints were retained to avoid a risky schema rewrite; application model deletion protections guard current deletion paths. Direct SQL/bulk query deletes bypass model events and must not be used for these records.

**Seeders and grading**

All seven PHP seeders were inspected: DatabaseSeeder, TracksAndSpecializationsSeeder, TransmutationRangesSeeder, Do015TransmutationSeeder, SubjectGroupWeightsSeeder, ExamRoleSharesSeeder and DepedSubjectCatalogSeeder (seven files total). They were exercised by the isolated regression tests; the new repeat-run test invokes the root seeder twice and the catalog seeder twice and checks stable counts and one active Principal. No live db:seed was run.

Do015TransmutationSeeder deliberately deletes/replaces its scheme rows; DepedSubjectCatalogSeeder updates catalog records. These must not be run blindly against live policy data. DatabaseSeeder has a fixed demo school year, demo accounts/default password, and an explicitly incomplete core-subject list. Its plaintext password console output was removed. Existing accounts are not recredentialed.

ExamRoleSharesSeeder explicitly labels 30/30/40 as provisional. This audit does not certify those values as official DepEd policy. Strengthened SHS and Grade 12 legacy grading profiles remain separate; no universal 25/50/25 rule was introduced. Existing grading, transmutation, examination-only and blank-versus-zero tests are retained.

**Cleanup and performance**

Removed resources/views/layouts/navigation.blade.php: tracked since the initial commit, unused by any current layout/component/test, and containing stale profile.edit links. The active sidebar is in layouts/app.blade.php. Removed obsolete user-detachment code and automatic model-training fallback. Retained model_cache.pkl, model_accuracy.txt, training architecture scripts, fixtures including the intentionally truncated workbook, documentation, migrations, dependencies and used assets. No student uploads or generated academic reports were removed.

.gitignore now covers .env variants except .env.example, private local uploads, analytics scratch JSON and private dataset directories. Existing vendor, node_modules, logs and backup ignores remain. Test logs are kept locally under ignored storage/logs for diagnosis.

Deletion guards use short-circuit exists queries instead of loading related collections; four old count-based guards now use exists. Existing pagination/scale tests remain the performance checks. Per-section term progress and per-year dependency inspection still perform repeated queries; no speculative performance rewrite was attempted.

**Verification**

Final verification results:

| Check | Result |
| --- | --- |
| Full Laravel regression | 967 passed, 3,090 assertions, 301.18 seconds |
| Targeted auth/user/deletion/authorization/analytics failure | 92 passed, 235 assertions |
| Targeted term/deletion/elective configuration | 30 passed, 178 assertions |
| Python classifier | 3 passed |
| Python training pipeline | 16 passed in isolated temporary directories |
| Missing-model guard | Passed; deployed artifact unchanged |
| npm production build | Passed, 63 modules |
| PHP syntax | 366 files, zero errors |
| Blade compilation | Passed; compiled views cleared afterward |
| Live migrations | All applied; nothing to migrate |
| Live integrity | No orphaned or inconsistent data found |
| Routes | 100; all literal navigation targets resolve; Admin routes all protected |
| Analytics scratch files after tests | Zero |
| Final git diff --check | Passed |

No observed automated functional blocker remains. Browser UAT and policy caveats below remain explicit. Initial failures were diagnosed: one new assertion compared freshly created model attributes with normalized database values; it was corrected to compare two database snapshots. Windows sandbox restrictions required approved retries for esbuild and Python temporary test directories. No dependency version changes were needed: Python 3.14.6 and scikit-learn 1.9.0 match requirements.

**Exact browser UAT ? use anonymized records only**

Use the application's configured base URL plus the paths below. Use separate browser profiles/private windows for the Admin and test Adviser. Do not replace the active school year or edit real scores merely to demonstrate a feature.

1. Admin: open /login, submit a wrong password and verify an error, then sign in correctly. Verify /admin/dashboard loads and /login redirects an already signed-in Admin safely.
2. Open /admin/users. Create an Adviser using a unique demo username and a strong temporary password. Retry its username and an invalid/missing field; verify controlled errors and no duplicate account. Sign into this Adviser in a second browser profile.
3. From Admin, disable that demo Adviser. Refresh its already-open /adviser/dashboard in the second profile: expect login redirect and the exact disabled-account message. Retry login and expect rejection. Verify the Admin remains authenticated. Keep used demo accounts disabled rather than deleting their audit history.
4. Visit /admin/tracks, /admin/specializations, /admin/subjects, /admin/sections and /admin/students. Check the populated table, pagination, filters/search, empty-search result, edit buttons, and narrow/mobile width. Attempt deletion only on approved demo records with dependencies; expect a clear block and unchanged dependent rows.
5. Visit /admin/academic-terms. Verify academic years and term dates render. Test invalid date order in the edit dialogs, then cancel. On an isolated demo year, verify empty Term 1 blocks opening Term 2 and empty Term 2 blocks Term 3. With completed demo grades, verify progression leaves exactly one open term.
6. On Admin import dialogs, preview an anonymized approved file, verify detected curriculum/columns and rejected rows, then cancel if no demo write is intended. Reject an executable extension and an oversized file. Logout; a protected Admin URL must redirect to login.
7. Adviser: sign into an active assigned demo Adviser. Check /adviser/dashboard and /adviser/students. Try another adviser's learner ID with the edit endpoint; expect rejection and unchanged data.
8. Open /adviser/assessments and /adviser/grades. On approved demo data, encode one blank score and one numeric zero; reopen and verify blank remains absent while zero remains zero. Preview the correct Grade 11 or Grade 12 ECR before confirming a demo import. Check HPS and subject/term identification.
9. Open /adviser/submit-report, choose the correct open grading period, submit completed demo grades and verify report status and risk output. A configured Python failure must show a warning without losing official grades. Verify /admin/users and Admin write URLs are forbidden. Logout.
10. Principal: sign in and verify the distinct /principal/dashboard. Open /principal/students, /principal/subject-analysis, /principal/interventions and /principal/reports. Exercise grade, section and term filters, learner drilldown, empty results and pagination.
11. On approved demo records, record/review an intervention decision and its note; verify Adviser visibility and the displayed risk/safety-net explanation. The current routes do not expose a separate manual risk-override endpoint; do not invent one for the demo. Verify /admin/users and Admin write URLs are forbidden. Logout.
12. Disconnect networking, hard-reload login and each dashboard, and check icons and Principal charts. Password/reset guest pages may fall back from the external Bunny font to the system font. Check projector resolution and browser console for failed local asset paths. Reconnect only if needed.

**Remaining presentation items**

- HIGH: 30/30/40 examination subcomponent shares remain explicitly provisional. Obtain policy confirmation before presenting them as official.
- MEDIUM: browser UAT, projector layout and offline hard-reload checks remain manual; HTTP tests do not replace them.
- MEDIUM: the deployed model is synthetic/single-feature and has not been validated against real historical outcomes. Explain this limitation during defense; no retraining or invented historical data was performed.
- LOW: unused guest-layout font still uses Bunny CDN, with normal fallback fonts. Icons and charts are local.
- LOW: root seeder is demo-oriented and can replace policy rows through its child seeder; do not run it on the live database tonight.

**Data safety confirmation**

No live student data, grades, assessments, reports, risk results or interventions were deleted. The database was not reset or restored. The deployed ML model was not retrained or activated. Python pipeline tests fit disposable synthetic candidates and exercise promotion only inside their temporary test directories, as required by the requested suite. No commit or push was made.
