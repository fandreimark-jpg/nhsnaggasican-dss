# FINAL PRE-DEMO AND PRE-DEPLOYMENT AUDIT REPORT

Naggasican NHS — Learner Academic Monitoring and Decision Support System
Branch `rule-based-academic-monitoring`, 2026-09-27 (evening). Nothing was
committed, pushed or deployed.

## 1. Executive summary

Tested for real: the full Laravel suite (plus 7 new end-to-end scenario
tests), the JS test, the production build, migrations and the integrity
checker on the working database (read-only), and — on an isolated MySQL
copy with synthetic data — 44 black-box authentication / session / CSRF /
role-access / IDOR / disclosure checks, 5 concurrency races and a 9-process
mixed read/write load run, all through the real HTTP kernel.

Two real concurrency defects were found and fixed (duplicate interventions
under double-submission; HTTP 500 + possible overwrite on a double-submitted
Add Item). One policy conflict (manually encoded grades are never
"official") is recorded, not changed. This audit does not claim the system
is free of defects or fully secure; see §14–§15 for what remains.

## 2. Environment

| | |
|---|---|
| Laravel | 12.62.0 |
| PHP | 8.2.12 ZTS (XAMPP, Windows 11) |
| Database | MariaDB 10.4.32, working DB `naggasican_dss` |
| Node / npm | 24.14.0 / 11.9.0 |
| Test DB | SQLite `:memory:` via `phpunit.xml` (TestCase guard refuses anything else) |
| Isolated HTTP DB | `naggasican_dss_audit` (new, migrated + synthetic seed: 5 users, 2 sections, 84 learners, 504 scores, 168 official grades) |
| Isolation method | plain PHP processes with inline `DB_DATABASE=naggasican_dss_audit`, each asserting the connection name before any request; working-DB counters compared before/after every run |

## 3. Scope

Tested: authentication, sessions, CSRF, throttle, role matrix, IDOR,
mass assignment, XSS/SQL-injection-like input, error disclosure, the
mandatory business scenarios (§6), term restrictions, interventions,
concurrency, load, build, migrations, integrity, secrets/`.gitignore`,
logs, deployment configuration (static).

Not tested (see §15): real browser (visual responsive, JS behaviour, network
throttling), the Docker image (no Docker on this machine), Railway, the
school's real filled-in E-Class Records.

## 4. Test results summary

| Area | Tests | Passed | Failed | Blocked | Result |
|------|------:|-------:|-------:|--------:|--------|
| Laravel suite (all areas) | 1,232 | 731 + 501 (see §5) | 0 | 0 | PASS (chunk 2 last full run pre-fix) |
| Authentication / session / CSRF (black-box) | 12 | 12 | 0 | 0 | PASS |
| Authorization role matrix + refused mutations | 18 | 18 | 0 | 0 | PASS |
| IDOR (cross-section) | 7 | 7 | 0 | 0 | PASS |
| Disclosure / XSS / injection / headers / mass assignment | 7 | 7 | 0 | 0 | PASS |
| Business scenarios (FinalAuditScenariosTest) | 7 | 7 | 0 | 0 | PASS |
| Concurrency races (MariaDB, 6–8 processes) | 5 | 3 → 5 after fixes | 2 → 0 | 0 | PASS after fix |
| Mixed load (9 processes, 264 requests) | 1 | 1 | 0 | 0 | PASS |
| JS (`node --test tests/js/confirm.test.mjs`) | 5 | 5 | 0 | 0 | PASS |
| Production build (`npm run build`) | 1 | 1 | 0 | 0 | PASS |
| Migrations / integrity (working DB) | 2 | 2 | 0 | 0 | PASS |
| Responsive | static only | — | — | browser | MANUAL REQUIRED |
| Deployment image | static only | — | — | Docker | MANUAL REQUIRED |

(3 black-box checks first read as failures and were harness/expectation
errors, each re-verified: the flash message after repeated failed logins —
proven correct with Laravel's own harness; the record download 403 — the
intended refusal of a foreign `section_id`.)

## 5. Automated test evidence

- `vendor/bin/phpunit -c phpunit.xml` in two chunks. Pre-fix run: 724 + 501 = 1,225 passed, 0 failed. Post-fix: chunk 1 731/731 passed (incl. the 7 new tests); chunk 2 was stopped at 63/501 by Claude Code's low-memory reaper (not a failure) and was NOT re-run; every test file touching the changed code (Add Item, interventions — 286 tests across both chunks) passed after the fixes. 3 PHPUnit deprecation notices (pre-existing).
- `node --test tests/js/confirm.test.mjs`: 5/5 pass
- `npm run build`: built, manifest + 1 CSS + 1 JS; no `public/hot`
- `php artisan migrate:status`: 75 ran, 0 pending
- `php artisan dss:check-integrity`: "No orphaned or inconsistent data found." (working DB and audit DB)
- `git diff --check`: clean

## 6. Business rule verification

| Rule | Evidence | Result |
|---|---|---|
| Blank vs 0 vs Missing | Add Item A=18 / B=0 / C=blank / D=Missing: B stored as 0 (Completed), C has no row, only D opens a concern | PASS |
| Missing Requirement + idempotency | D For Review, visible to the Principal with learner/subject/item; two full re-evaluations → still 1 open concern | PASS |
| Subject-first failure | 90/88/74 → average 84.00, Passed 2, Failed 1, "Subject C — 74.00 — Failed", concern open, For Review | PASS |
| Higher other subject never resolves | Subject A raised to 100 via Encode Grades → Subject C concern still open | PASS |
| Multiple failures | 73/74/90 → 2 concerns, learner counted once (dashboard learners_for_review distinct) | PASS |
| Clean learner | all passing → Failed 0, No Current Concern | PASS |
| Missing-only | For Review, no failed subject invented | PASS |
| No Current Concern / For Review only | no Low/Moderate/High/At Risk words rendered on any role page | PASS |
| Intervention lifecycle | review → intervention linked → adviser acknowledge + deliver → concern stays open; second delivery refused | PASS |
| Concern resolution | Subject C corrected 74→80 via Encode Grades → resolved with reason, history kept, No Current Concern; re-evaluation creates nothing | PASS |
| Term restrictions | closed term refuses Add Item, score edit, Encode Grades, Verify, Submit Report — nothing written | PASS |
| Grading profile | per-profile weights covered by the existing suite (SubjectClassificationConsistencyTest, GradingPolicyResolutionTest) | PASS (suite) |

## 7. Security findings

- **S1 (HIGH, deployment blocker, operator):** all 5 working accounts still
  use the password hard-coded in `database/seeders/DatabaseSeeder.php`
  (which is in Git). Change every password through Admin > Users / the
  profile modal before any deployment. Not changed by this audit.
- **S2 (LOW):** Laravel logs SQL bindings on database errors; the 21 MB
  local `storage/logs/laravel.log` holds one June line with a bcrypt hash.
  Git-ignored, never deployed; production logs to stderr at `warning`.
  Rotate/clear the local log before handing the machine over.
- No committed secrets found (APP_KEY/tokens/private keys pattern scan of
  tracked files; `.env*` ignored except `.env.example`, whose only values
  are placeholders). No `dd()`/`dump()`/`{!! !!}`. All raw SQL is
  parameter-bound or constant. CSRF enforced (419 without token). Session id
  regenerated on login; logout invalidates. Login throttled after 5
  failures. Role cannot be mass-assigned via profile. Learner names are
  escaped (payload `<script>…` rendered inert on adviser and principal
  pages). Security headers present.

## 8. Database findings

Integrity clean. `unique_assessment_per_period` and the concern `open_key`
unique index held under real concurrency. Transactions: import, Add Item,
intervention creation are atomic (a refused Add Item leaves no upload row).
75 migrations, 0 pending.

## 9. Performance / reliability

Conditions: in-process HTTP kernel (no Apache/OPcache), MariaDB 10.4 on the
dev laptop, isolated audit DB, 9 concurrent processes (1 writer editing 42
scores ×15, 3 principal, 3 adviser, 2 admin readers), ~9 s wall time.

| Endpoint | n | errors | median ms | p95 ms |
|---|---:|---:|---:|---:|
| principal/dashboard | 18 | 0 | 220 | 696 |
| principal/academic-monitoring | 18 | 0 | 241 | 502 |
| principal/students | 18 | 0 | 288 | 375 |
| principal/subject-analysis | 18 | 0 | 102 | 166 |
| adviser/submit-report | 18 | 0 | 352 | 505 |
| adviser/assessments | 18 | 0 | 284 | 384 |
| adviser/students | 18 | 0 | 119 | 171 |
| admin/students | 12 | 0 | 85 | 143 |
| WRITE edit 42 scores | 15 | 0 | 563 | 725 |
| login | 9 | 0 | 1060 | 1085 |

264 requests, 0 errors, integrity clean afterwards. No production capacity
is claimed from this.

## 10. Responsive / browser findings

STATIC inspection only (17 role pages rendered): every page has a viewport
meta, the drawer layout, and every data table sits in a scroll container.
MANUAL REAL-BROWSER VERIFICATION IS STILL REQUIRED at 1366×768, 768×1024 and
390×844. Accessibility (LOW): most form labels are not programmatically tied
to their inputs (`<label>` without `for`), including the shared profile modal.

## 11. Issues found

### High

**A1 — Duplicate open interventions under double-submission**
Severity HIGH · Feature: Principal interventions · Env: isolated MariaDB.
Steps: 6 processes POST the same intervention (learner, subject, term) at
the same second. Expected 1. Actual 6. Root cause: check-then-insert with no
lock; the rule cannot be a unique index. Fix applied: yes — transaction +
learner row lock in `store()` and `storeBulk()`
(`app/Http/Controllers/Principal/InterventionController.php`). Retest: 8
concurrent → 1. Regression: all intervention tests pass. Demo blocker: no
(button disables client-side). Deployment blocker: fixed.

### Medium

**A2 — Double-submitted Add Item: HTTP 500, possible overwrite**
Severity MEDIUM · Feature: Adviser Add Item. Steps: 6 concurrent identical
Add Item posts. Expected one item, clean refusal for the rest. Actual: 1
item, 5 × HTTP 500 (`unique_assessment_per_period`); with a different
interleaving `updateOrCreate()` could overwrite the first item. Fix applied:
yes — `create()` + UniqueConstraintViolation → "already exists" refusal
(`app/Http/Controllers/Adviser/AssessmentController.php`). Retest: 8
concurrent → 1 item, 0 errors; regression test added.

**P1 — Manually encoded grades are never official (POLICY, not changed)**
Encode Grades / grade import write grades without `is_verified`; they never
open a Subject Performance Concern but count toward Submit Report's
"every expected grade encoded". Requires the school's decision (treat as
official, or require verification before submission). Demo: use the
Assessments → Verify path for official grades.

### Low

- **L1** stale empty-state hint on Adviser Assessments and Encode Grades
  (pointed to per-term assignment removed on 2026-09-20). Fixed.
- **L2** form labels not programmatically associated (accessibility). Not fixed.
- **L3** local log contains SQL bindings (S2). Not fixed (operational).
- **L4** 3 PHPUnit deprecation notices in the suite output. Not investigated in depth.

## 12. Fixes applied

| Issue | Files | Test |
|---|---|---|
| A1 | `app/Http/Controllers/Principal/InterventionController.php` | 8-process race → 1; intervention suites pass |
| A2 | `app/Http/Controllers/Adviser/AssessmentController.php` | 8-process race → 1 item/0 errors; `FinalAuditScenariosTest::test_a_raced_duplicate_add_item_…` |
| L1 | `resources/views/adviser/assessments.blade.php`, `resources/views/adviser/grades.blade.php` | render + suite |
| — | `tests/Feature/FinalAuditScenariosTest.php` (new, 7 tests) | — |
| — | `CLAUDE.md` (baseline + conventions) | — |

## 13. Removed code / files

- `storage/app/test_input.json`, `storage/app/test_output.json` — retired ML
  classifier I/O leftovers (June), git-ignored, never tracked; reference
  search of app/routes/config/database/resources/tests/deploy found none.
- `storage/app/private/temp_ecr_learner_imports/804a18e6-….xlsx` (earlier
  today, reset task) — unreferenced temp learner import.
No source code was removed for cleanup.

## 14. Remaining issues / blockers

S1 (default passwords) before deployment; P1 policy decision; L2–L4.

## 15. Untested areas

Real browser at the three sizes; slow/failed network and interrupted
submission in a browser; the Docker image build/boot; Railway; the school's
real filled-in E-Class Records (only the blank official instrument and
sanitized fixtures are in the repository).

---

# ADDENDUM — Final remediation and release-readiness pass (2026-09-27, late)

- **S1 fixed in code:** `DatabaseSeeder` no longer carries a password.
  New accounts use `SEED_ACCOUNT_PASSWORD` (min 12, never `password`);
  production without it refuses before writing (verified on a throwaway
  MySQL DB via the real `artisan db:seed`: exception, 0 users). Local runs
  get a one-time random password. `SeederCredentialsTest` (6). The 5
  working accounts still use the OLD default — operator must change them.
- **Within-Term Progress:** "Change: +X percentage points" (was "points");
  a pre-delivery score edited after delivery is now flagged, since Before
  uses its current value and no score history exists. 2 unit tests.
- **Encode Grades:** red tint only for an OFFICIAL failure (shared
  `isFailing()`), "Not yet official" on a typed grade. 1 test.
- **75 target** labelled as a system reference, not a DepEd rule.
- **Full suite after every fix:** 1,241 / 1,241 passed, 0 failed, 0
  skipped, 9,036 assertions, four foreground chunks (509 + 225 + 270 +
  237). 3 PHPUnit doc-comment metadata deprecations (test code only).
- JS 5/5, build OK, 75 migrations / 0 pending, integrity clean, diff
  check clean, secret scan clean. Working DB untouched throughout.
- Throwaway databases left for the operator to drop:
  `naggasican_dss_audit`, `naggasican_dss_seedcheck` (synthetic only).
