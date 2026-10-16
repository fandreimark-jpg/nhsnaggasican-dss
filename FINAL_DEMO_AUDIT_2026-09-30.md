# Final demo audit — 2026-09-30

Branch `rule-based-academic-monitoring` at `76e2306`, plus the existing uncommitted
working tree (254 entries at start, all preserved). PHP 8.2.12 (ZTS), Node 24.14.0,
Composer 2.9.5, MariaDB 10.4.32, Windows 11, XAMPP. Nothing was committed, pushed,
deployed or exposed.

Previous reports (`FINAL_AUDIT_2026-09-28.md`, `RELEASE_READINESS_2026-09-28.md`) were
treated as history. No application file had changed since them. Everything below was
re-executed today.

## A. Verdict

- **Demo — CONDITIONALLY READY.** This applies to this code, on this machine, for the
  workflows verified below. Three defects found today are fixed and re-verified (§C).
  No known demo-blocking defect remains. Limitations to state are in §G.
  - **One preparation step is required:** the working database has **0 grades**. The
    rehearsal step "verify a failing subject result" needs one prepared learner (§H, step 0).
- **Deployment — NOT READY / NOT VERIFIED.** The gates are unchanged:
  - the Docker image has never been built;
  - working accounts still use the old shared password;
  - `laravel/framework` has a new advisory, fixed in 12.69 (§E6);
  - the school-policy decisions in §G are open.

## B. Coverage checklist

Legend: **P** Passed · **F** Failed then fixed (see §C) · **B** Blocked · **NT** Not Tested.

Methods and environments:
- **PU** — PHPUnit, SQLite `:memory:`.
- **BR** — headless Chrome against the isolated SQLite server (`tests/Browser`, port 8765).
- **HTTP** — live requests against the isolated servers (SQLite 8765, or MySQL
  `naggasican_dss_loadtest` on 8781–8784).
- **RO** — read-only check on the working DB.

| Area / role | Entry point | Scenario → expectation | Method | Result / evidence |
|---|---|---|---|---|
| Auth (all) | `POST /login` | valid → role home; invalid → generic message; no hash echoed | HTTP, PU `Auth/*` | P — "These credentials do not match our records." |
| Auth | `POST /login` | repeated failures → throttle | HTTP | P — "Too many login attempts… 55 seconds." |
| Auth | `POST /login` without CSRF | 419 page, no trace | HTTP | P — custom "419 Session expired" page |
| Auth | `POST /logout` | old cookie dead after logout | HTTP | P — replayed cookie → 302 /login |
| Auth | disabled / expired mid-edit | save refused, entries and draft kept, never "Saved" | BR `netfail` S5/S6 | P (S6 wording fixed, D-04) |
| Auth | password change / reset | profile modal; forgot-password (guest) | PU `ProfileTest`, `Auth/PasswordResetTest` | P |
| Security headers | every response | X-Frame-Options, nosniff, Referrer-Policy, httponly session cookie | HTTP | P |
| Authorization matrix | all role routes | own role only; guest/disabled → login | PU `RoleAccessMatrixTest` (reads live route table) | P |
| IDOR — Subject Teacher/Adviser | class record read/export/copy/save; edit/PUT/history; learner report; Encode; Verify | modified IDs/bodies across section, subject, assessment, learner | HTTP `idor.mjs` (25 probes) | P — 25/25, zero cross-boundary writes |
| Advising ≠ teaching | Bong advises Agila, not assigned General Science | read yes, save/edit/add/typed grade no | HTTP | P (403s; 0 rows written) |
| Verify Grades scope | `?section_id=` of another adviser's section | ignored, own advisory shown | HTTP | P |
| Retired endpoints | `/adviser/assessments/detect`, `/item` | gone | HTTP | P — 404 |
| Admin CRUD pages | 36 admin URLs | load, no broken links, no console errors | BR `crawl.mjs` | P (page load only) |
| All roles page load | 218 URLs, 4 roles | 200, no error text | BR `crawl.mjs` | P |
| Admin — learner import | `admin.students.import` | oversize, wrong type, **corrupt xlsx**, duplicate/blank LRN | HTTP + PU | **F → fixed** (D-02) |
| Admin — ECR learner import | preview/confirm | corrupt file refused | HTTP | P |
| Admin — roster extraction | `extract-roster` | corrupt file refused | HTTP | P |
| Admin — rejected rows CSV | download | re-uploadable; **formula-safe** | PU | **F → fixed** (D-03) |
| Adviser — final-grade import | `adviser.grades.import` | oversize/wrong type refused; **corrupt xlsx** | HTTP + PU | **F → fixed** (D-02) |
| Adviser — grade template | `adviser.grades.template` | learner/subject text **formula-safe**; round-trips | PU | **F → fixed** (D-03) |
| Class Record export | `teacher.class-record.export` | LRN as text, 0 / blank / MISSING distinct, formula-safe, labelled system export | PU `ClassRecordExport*`, fixtures | P |
| Copy Scores | `teacher.class-record.copy` | TSV, apostrophe guard | PU | P |
| Learner report / print | `teacher.learners.report` | scoped subjects; prints one learner, headers repeat | BR, PU | P |
| Assessment Add/Edit | `teacher.assessments.*` | details only; HPS below a kept score refused; exam-type rules; duplicate submit | PU, HTTP race A/B | P |
| Class Record grid | save, Mark Missing, Clear Entry, Undo, paste preview, drafts, leave guard, keyboard | as specified | BR `run.mjs` (160 checks) | P |
| Score validation | `parseScore()` | negative / nonnumeric / above-HPS refused; 0 valid; decimals to 2 dp | PU, race F | P |
| All-or-nothing save | save with one invalid cell | 422, nothing written | HTTP race F | P |
| Grading — independent | 6 profiles | hand-calculated = Class Record = Verify = export = stored = report | PU `IndependentGradingFixturesTest` (new) | P |
| Verification | single, bulk, repeat, stale bulk, concurrent | one grade, one log, 409 on stale | PU, HTTP `verify-race.mjs` | P |
| Monitoring A–J | concerns lifecycle | see §E4 | PU `FinalAuditScenariosTest`, `DemoFlowEndToEndTest`, race E/G/H | P |
| Failing threshold, decimals | official 74.01–74.99 | consistent with the DepEd 75 passing mark | PU probe | **Discrepancy** (D-01, policy) |
| Interventions | record, duplicate, deliver | one open per learner/subject/term; never resolves a concern | HTTP race G/H, PU | P |
| Responsiveness | 390 / 768 / 1366 / 200% | no overlap, no page scroll; last row and save bar reachable | BR | P (200% on 3 pages; other pages at 390/768) |
| Network failures | slow, offline, lost response, rapid clicks, term closed, disabled, expired | never false "Saved"; entries kept | BR `netfail.mjs` 15/15 | P |
| Concurrency | 4 / 8 / 16 VUs; 8-way races | no errors, no duplicates | HTTP | P (§E5) |
| DB integrity | `dss:check-integrity`, migrations | clean | RO + isolated | P |
| Dependencies | composer / npm audit | — | CLI | 2 new advisories, not reachable (§E6) |
| Real devices / screen reader | — | — | — | **NT** — not performed |
| Docker/Railway image | — | — | — | **B** — no Docker on this machine |

## C. Defect register

| ID | Severity | Role | Reproduction | Expected | Actual (before) | Cause | Fix | Verified |
|---|---|---|---|---|---|---|---|---|
| D-01 | P2, **policy** | Adviser, Principal | Correct an already-verified official grade to `74.50` (Encode Grades, or the grade import) | Consistent pass/fail against DepEd's 75 | Row stays official. `isFailing()` (`<= 74.0`) → **Passed**, no concern. Reports and Submit Report shade `< 75` red. `RecomputeGradesCommand` treats `< 75` as not passing. | Typed grades accept any decimal from 60 to 100, and a correction keeps `is_verified`. Transmuted grades are integers (`unsignedTinyInteger`), so only this path produces a decimal. | **Not changed** (threshold is policy). Options: (a) Failing = `< 75`; (b) typed official grades must be whole numbers; (c) a correction needs re-verification. | Probe: 74.01 / 74.50 / 74.99 → Passed, 0 concerns; 74.00 → Failing, 1 concern. Working DB: **0 grades, 0 affected**. |
| D-02 | P2 | Admin, Adviser | Upload a corrupt `.xlsx` to Admin › Students › Import, or Encode Grades › Import | Refused with a message, nothing written | **HTTP 500** ("Could not find zip member") | Extension-only validation lets the file reach the reader. The "controlled refusal" existed only on the retired assessment upload. | `ValidatesSpreadsheetUpload::importSpreadsheetOrRefuse()`, used by both imports | `UnreadableSpreadsheetImportTest` (fails before the fix, passes after); live probe → 302 with "could not be read as a spreadsheet… Nothing was imported." |
| D-03 | P2, security | Adviser, Admin | Learner name `=HYPERLINK(…)` → Encode Grades › template CSV (or the Admin rejected-rows / draft-roster CSV) | Text, never a live formula | Written raw and executed as a formula in Excel | The CSV writers did not use the existing `ClassRecordService::safeText()` guard | Both writers use `safeText()`. `GradesImport` reads back a `'`-escaped subject header, so the template still round-trips. | `CsvFormulaInjectionTest` (fails before, passes after, includes round trip) |
| D-04 | P3 | Teacher | Session expires with unsaved cells → Save | Plain message | "CSRF token mismatch. Your session has ended…" | Server message prefixed for 419 as well as 401 | Prefix only the 401 (account) message | `netfail` S6 re-run → "Your session has ended…"; S5 unchanged |
| D-05 | P3, hygiene | — | Any failed spreadsheet read | Temp copy removed | Laravel-Excel leaves `storage/framework/cache/laravel-excel/*` behind: 330 files, ~21 MB since 09-10, including uploaded learner data. Not web-exposed. | Library does not delete on exception; test runs also write there | **Not changed.** Only the 11 files this session created were removed. | — |
| H-01 | Harness | — | `loadtest.mjs` logged in as `narra@browser.test`, which the fixture never creates | All VUs sign in | 1–4 VUs refused per run | Test tooling | Uses `bong@browser.test` (Agila adviser) | Re-run: every role signs in, 0 errors |

## D. Changes

| File | Change |
|---|---|
| `app/Http/Controllers/Concerns/ValidatesSpreadsheetUpload.php` | + `importSpreadsheetOrRefuse()` (D-02) |
| `app/Http/Controllers/Admin/StudentController.php` | import uses it (D-02); CSV cells via `safeText()` (D-03) |
| `app/Http/Controllers/Adviser/GradeController.php` | import uses it (D-02); template names and headers via `safeText()` (D-03) |
| `app/Imports/GradesImport.php` | accepts a header escaped by `safeText()` (D-03) |
| `resources/js/class-record.js` | 419 message (D-04); `npm run build` re-run |
| `tests/Feature/IndependentGradingFixturesTest.php` | new — 6 hand-calculated fixtures |
| `tests/Feature/CsvFormulaInjectionTest.php` | new — 2 tests (D-03) |
| `tests/Feature/UnreadableSpreadsheetImportTest.php` | new — 2 tests (D-02) |
| `tests/Browser/loadtest.mjs` | H-01 |
| `CLAUDE.md` | standing conventions from this pass |

- No dependency, configuration or schema change.
- No test was skipped, weakened or removed.

## E. Execution evidence

1. **Backend:**
   - Baseline before any change: **1,218 passed**, 0 failed, 0 skipped (101 Unit +
     394 / 226 / 238 / 259 Feature).
   - After the fixes: **1,228 passed**, 0 failed, 0 skipped (101 + 396 / 232 / 238 /
     261; `--list-tests` = 1,228).
   - Warnings: PHPUnit "metadata in doc-comment" deprecations only.
2. **Frontend:** `node --test tests/js/*.mjs` → 15/15. `npm run build` OK.
3. **Browser (isolated SQLite 8765):**
   - `run.mjs` 160/160 — viewports 390 / 768 / 1366 and 200% zoom, drawer, keyboard,
     paste, drafts, print;
   - `crawl.mjs` 218 URLs, 0 problems, 0 console errors;
   - `netfail.mjs` 15/15, before and after D-04.
4. **Independent grading fixtures** (every expected value hand-calculated; transmuted
   grades hardcoded from DepEd tables):

   | Profile | WS (WW / PT / EX) | Initial | Official |
   |---|---|---|---|
   | DO 8, 25/50/25 | 21.11 / 42.50 / 17.50 | 81.11 | 88 |
   | DO 015 Arts 20/60/20 | 13.33 / 44.40 / 12.80 | 70.53 | 75 (pass anchor) |
   | DO 015 Core | 13.99 / 35.00 / 21.00 | 69.99 | 74 (Failing, 1 concern) |
   | Research 40/60/— | 0.00 / 60.00 / — | 60.00 | 72 (valid 0; exam refused) |
   | Basic Calculus (TE-only) | 18.00 / 40.00 / 22.50 | 80.50 | 83 |
   | Blank / Missing Term Exam | — | — | incomplete, not verifiable |

   After a recorded 0 on that Term Exam: Initial 88.00 → 90.

   Each fixture matched the Class Record, Verify Grades, the export, the stored grade
   and the learner report.
5. **Concurrency:**
   - Isolated MariaDB `naggasican_dss_loadtest`, 4 × single-threaded `php -S`, no
     OPcache, same laptop. Fixture: 3 sections, 54 learners, a 45-learner / 23-item
     Class Record.
   - Bounded load, 20 s per level:

     | VUs | Requests | Status | Class Record GET p50 / p95 | Save p50 / p95 |
     |---|---|---|---|---|
     | 4 | 96 | all 200 | 771 / 1,226 ms | 688 / 1,138 ms |
     | 8 | 116 | all 200 | 775 / 2,039 ms | 649 / 2,160 ms |
     | 16 | 112 | all 200 | 1,552 / 4,072 ms | 1,447 / 4,060 ms |

   - Throughput stays at ~4.5 req/s at every level. That is the four
     single-threaded servers, not an application limit.
   - 0 errors, 0 save conflicts. Server logs: 0 × 5xx, 0 errors.
   - **Races (8 simultaneous sessions): 8/8 passed.**
     - Same-token Add → 1 item. Same-name Add → 1 item.
     - Conflicting cell → 1 applied, 7 × 409, 1 audit row.
     - Lost-response retry → 1 change, 1 audit row.
     - Concurrent Missing → 1 marker, 1 concern.
     - Invalid batch → 422, nothing written.
     - Concurrent intervention → 1, concern stays open.
     - Score for the Missing item → resolved: "A score was recorded for…".
   - **`verify-race.mjs`:** 2 bulk requests → 409, 2 individual verifies applied;
     afterwards 2 grades, 2 logs, 0 duplicate open keys.
   - Production capacity is **not** inferred from these numbers.
6. **Dependencies:**
   - `composer audit`:
     - `laravel/framework` < 12.69 — XSS in the debug page. Needs `APP_DEBUG=true`,
       which is false in `.env` and in the Dockerfile.
     - `league/flysystem` ≤ 3.35.2 — path normalizer. No user-controlled storage
       path exists: names are UUIDs, pending state is in the session.
     - Both are advisory-only here, and **not upgraded**, because `vendor/` is shared
       with the running demo instance.
     - Post-demo:
       `composer update laravel/framework league/flysystem league/flysystem-local`
       (resolves 12.62 → 12.69.3, 3.34 → 3.36), then the full suite.
   - `npm audit --omit=dev`: 0.
7. **Integrity:**
   - Working DB (read-only): "No orphaned or inconsistent data found"; 0 pending
     migrations.
   - Isolated DB: one expected finding — the fixture inserts Missing markers without
     sync, so 43 concerns are "to open". This shows the check catches that.
8. **Static:**
   - 0 `{!! !!}` in views;
   - no raw SQL taking request input;
   - no `$guarded = []`;
   - no `$request->all()` passed to create/update.

## F. Data protection

- **Working DB:** `naggasican_dss` (MySQL).
- **Test databases:** PHPUnit uses SQLite `:memory:` (the `TestCase::setUpTraits()`
  guard, no cached config). Browser tests use SQLite
  `storage/framework/browser-testing/browser.sqlite`. Concurrency tests use
  `naggasican_dss_loadtest`.
- **Isolation proof:** the router refuses any other database, and each served process
  accepted a login that exists only in the isolated fixture.
- **Working DB access:** read-only only — `CHECKSUM TABLE`, counts, and
  `dss:check-integrity` / `migrate:status`, both confirmed read-only (dry-run evaluate).
- **Checksums:** all 22 academic, user and log tables had identical checksums before
  and after the session.
- **No backup or restore was needed or performed.**
- No secrets, passwords or learner records are reproduced here. The only password
  shown is the throwaway isolated-fixture one, already in `tests/Browser/prepare.php`.

## G. Remaining limitations and next steps

1. **D-01 (school decision):** how a decimal official grade from 74.01 to 74.99 is
   classified. Decide one of the options in §C, then apply it everywhere; the code
   today mixes `<= 74` and `< 75`.
2. **Open policies (unchanged):**
   - whether a corrected official grade stays official (it does today);
   - whether a correction needs a reason (no; old and new values are logged);
   - whether a typed grade can become official without verification (no);
   - whether Subject Teachers may verify (no).
3. The Excel download is a **system export**, not the DepEd ECR template. Attendance
   monitoring and ML prediction are **not** active features.
4. D-05: clear old Laravel-Excel temp files by hand, or add pruning (not done).
5. Apply the dependency patch after the demo (§E6).
6. Real-device, touch and screen-reader testing: **not performed**.
7. Deployment:
   - build and boot the Docker image;
   - rotate every account password;
   - set Railway variables (see `RELEASE_READINESS_2026-09-28.md`).
8. Section Shakespeare still has no adviser. It has no learners; it is not used in
   the demo.
9. `verify-race.mjs` still needs a `narra@browser.test` adviser added to the load-test
   DB by hand. This session added one there through the isolation guard.

## H. Demo rehearsal (working data; current labels)

**Step 0 — prepare, before the audience arrives** (Agila adviser account; Term 1 is open):

- Open **My Classes → Community Engagement Solidarity and Citizenship — Agila → Class
  Record**.
- For ONE learner, enter scores so the computed grade is below 70.00. This subject is
  DO 015, 20/60/20; 70.00 transmutes to 75 and 69.99 to 74.
- Every component the subject weighs needs at least one score, including each
  Examination Type present.
- Press **Save Scores**. Confirm under **Verify Grades** that the learner reads
  eligible with Official 74 or lower, but **do not verify yet**.

**Rehearsal:**

1. **Admin** → **Teaching Assignments**: Agila › General Science is taught by the
   Subject Teacher. Agila › Community Engagement… is taught by the Agila adviser for
   Terms 1–3. **Academic Terms** shows SY 2026-2027, Term 1 open.
2. **Assigned teacher** → **My Classes** → the class → **Class Record** → **Add
   Assessment**. Enter the name, component and HPS, then **Save Assessment**. The
   message reads "Enter scores in the Class Record". Type scores into the new column,
   then **Save Scores**. Wait for "Saved N change(s)".
3. Select a synthetic learner's cell → **Mark Missing** (the cell shows M) → **Save
   Scores**.
4. **Adviser** → **Advisory Overview** (or **My Students**): Monitoring reads **For
   Review** with the concern type. Learners For Review and Open Concerns are separate
   counts.
5. **Principal** → **Academic Monitoring**: filter to that learner. The Missing
   Requirement shows its evidence (item, marked by, when). Optionally **Mark as
   Reviewed**; this does not close it.
6. **Adviser** → **Verify Grades**: select the subject. The prepared learner is
   eligible → **Verify** → confirm.
7. **Principal** → **Academic Monitoring** (or **Students** › learner): a **Subject
   Performance Concern** with "official grade … (Failed)". Other subjects' grades do
   not hide it.
8. **Principal** → **Record Intervention** on that concern. Choose the type yourself
   (nothing is pre-selected), then save. The concern **stays open**.
9. **Teacher** → Class Record: type a score in the step-3 Missing cell → **Save
   Scores**.
10. **Principal** → Academic Monitoring: the Missing concern is **resolved** ("A score
    was recorded for …"). The Subject Performance Concern stays **open**, and the
    intervention is kept.
11. **Download Excel Class Record** (labelled a system export), or open the **Learner
    report** from Advisory Overview → **Print**.
12. Refusals:
    - The **Subject Teacher** opens Agila › Community Engagement: 403, because they
      are not assigned.
    - Or the Admin closes Term 1 (**Academic Terms**) while a Class Record is open;
      Save Scores then reads "Not saved — this class is no longer open to you for
      encoding."
    - Reopen the term afterwards if the demo continues.

Automated equivalent of steps 1–12: `DemoFlowEndToEndTest` (passing), plus
`netfail` S4 for the closed term.
