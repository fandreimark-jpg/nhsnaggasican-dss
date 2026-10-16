# Release readiness — Naggasican NHS Learner Academic Performance DSS

Prepared 2026-09-28 from the working checkout (branch `rule-based-academic-monitoring`,
uncommitted). No credentials or learner data appear in this document. Nothing here has
been deployed; production acceptance has NOT been performed.

---

## 1. Four roles — what each can and cannot do

| Role | Can | Cannot |
|---|---|---|
| **Admin** | Users, school years/terms, tracks, specializations, subjects (incl. Terms Taught), sections and their adviser, learners (add, import, edit), **teaching assignments** (assign, change teacher with a reason, remove with a reason) | Encode assessment scores, verify grades, decide interventions |
| **Adviser** | Read every applicable subject of the section they advise (Class Record read-only, Advisory Overview, learner report); encode scores and type grades ONLY for classes they are assigned to teach; **verify official grades** for their advisory section (Verify Grades); acknowledge and record delivery of interventions | Edit a class they are not assigned to; verify another section; manage learner master data; decide interventions |
| **Subject Teacher** | Only their assigned classes: Class Record, Add/Edit Assessment details, Save Scores, Excel/Copy, learner report limited to their subjects | Advisory pages, Encode/Verify Grades, learner master data, any unassigned class |
| **Principal** | Academic Monitoring (evidence and concerns), mark a concern Reviewed, record/decide interventions, dashboards, reports, subject analysis | Encode scores, verify grades, manage master data |

Every rule above is enforced server-side (route middleware + `TeachingAccess` + per-request
checks), not by hiding menus. `RoleAccessMatrixTest` walks every route for every role.

## 2. What the DSS does (rule-based, no prediction)

The DSS does not predict. It records assessment evidence, computes grades with the configured
DepEd grading profile, and opens an **academic concern** only when an approved rule is met:

1. **Missing Requirement** — a teacher explicitly marked a required assessment Missing.
2. **Subject Performance Concern** — a subject's **official (verified)** grade is Failing
   (74 and below). Each subject is judged on its own; a passing overall average never hides it.

No attendance, in-term percentage, decline or risk-level rule is active. In-term "Below Target"
readings are descriptive only and open nothing. Monitoring shows **For Review** (≥1 open
concern) or **No Current Concern**; there are no severity levels.

### Blank, zero and Missing

- **A number, including 0** — a recorded score (Completed). A 0 counts in the grade.
- **Blank** — No Evidence Yet. Never treated as 0, never Missing, opens nothing.
- **M / MISSING** — explicitly marked Missing by the teacher. It opens a Missing Requirement
  concern; in the grade it is "no evidence", not a 0.

### Concern versus intervention

- A concern is opened and resolved **only by evidence** (a score recorded, a Missing mark removed,
  an official grade becoming passing).
- Principal review, intervention, adviser acknowledgement and delivery are recorded history. They
  **never resolve** a concern.
- Concerns are never deleted; a resolved concern keeps its reason.

## 3. Official grades — current behaviour (not a policy recommendation)

- **Verify (Verify Grades, adviser of the section only):** the complete computed grade becomes
  the official transmuted grade. Incomplete evidence cannot be verified. A repeated verify with
  unchanged figures writes nothing. Bulk "Verify all eligible" covers the whole class (not the
  Status filter) and verifies nothing if the eligible set changed after the confirmation.
- **Typed grades (Encode Grades):** they need the adviser of the section who is also the
  subject's assigned teacher. A NEW typed grade is "Not yet official": it opens no concern.
- **Correcting an already-official grade on Encode Grades:**
  - it **stays official** (verified flag, verified date and stored computed grade unchanged);
  - **no reason** is asked;
  - it is audited as `correct_official_grade` with the old and new value;
  - concerns are re-evaluated from the new value;
  - Verify Grades then shows **Re-verify**, stating that the official value (not the evidence)
    changed.

## 4. Outstanding school-policy decisions

1. Should a corrected official grade stay official, or return to "not yet official" until
   re-verified? (Today it stays official.)
2. Should a correction require a written reason? (Today it does not; the old and new values are
   logged.)
3. May a typed or imported grade ever become official without verification? (Today it never does
   — yet Submit Report counts typed grades as "encoded".)
4. May a Subject Teacher verify grades for their own subject? (Today they may not; verification
   stays with the section adviser.)
5. Within-term additional-support items count additively (unchanged); the Summer Remedial Class
   is not implemented.
6. Any attendance-based monitoring (units, threshold, excused absences, resolution) — **not
   implemented** and not approved.
7. Mapping the Excel export into the official DepEd E-Class Record workbook — **pending**. The
   download is labelled a *system export*; no formula compatibility is claimed.

## 5. Known limitations (actual)

- The Excel download is a system export, not the DepEd ECR template (10 WW / 10 PT / 3 EX column
  limit and XLOOKUP round-trip unverified).
- Attendance: the table exists as dormant history. It is **not implemented** (no page, no rule),
  not "implemented but hidden".
- Real-device keyboard, screen-reader and physical-print checks have not been performed.
- The deployment image has not been built on this machine (no Docker).

## 6. Final demo walkthrough (synthetic data only)

1. **Admin** → Teaching Assignments: assign a Subject Teacher to Subject X, Section S, Term 1.
2. **Subject Teacher** → My Classes → Class Record (the badge reads "Editable").
3. Add Assessment (details only). The new column is blank for everyone — no zeros, no Missing.
4. Type a score, a 0, leave one blank, and type **M** for another learner. Save Scores; reload the
   page; the values are unchanged.
5. Try 25 on a 20-point item: nothing is saved and the cell is flagged.
6. **Principal** → Academic Monitoring: the learner marked M is For Review, showing the item.
   Mark Reviewed, then Record Intervention.
7. **Adviser** → Interventions: Acknowledge, then Record delivery with a note. Back in Academic
   Monitoring the concern is still open.
8. **Subject Teacher** replaces M with a score and saves. The concern resolves with its reason.
9. Complete the three components. **Adviser** → Verify Grades → Verify; the grade becomes
   "Official · verified".
10. With a failing official grade in one subject and a passing average: a Subject Performance
    Concern opens for that subject only.
11. Download Excel / Copy Scores; open a learner report and print it.
12. **Admin** changes the teacher (with a reason). The old teacher's open tab can no longer save;
    the new teacher can.

`tests/Feature/DemoFlowEndToEndTest.php` runs exactly this sequence through the real routes.

## 7. Manual UAT checklist (expected outcome in brackets)

- [ ] Each of the four roles signs in and lands on its own home page [no 403, correct sidebar]
- [ ] A disabled account cannot sign in [refused with a message]
- [ ] A teacher opens an unassigned class by URL [403]
- [ ] Adviser opens another teacher's class in their section [read-only, teacher named]
- [ ] Add Assessment [a blank column; no scores and no concern created]
- [ ] Save 18, 0, blank, M, then reload [identical; 0 shown as 0, blank stays empty]
- [ ] Save a score above the HPS [nothing saved; the cell is marked]
- [ ] Two tabs edit the same cell [the second save is refused as a conflict; nothing is overwritten]
- [ ] Go offline, then Save [failure message; the entry is kept; Save again after reconnecting works]
- [ ] Principal sees the M learner For Review [evidence names the item]
- [ ] Intervention delivered [concern still open]
- [ ] Missing corrected with a score [concern resolved]
- [ ] Verify an incomplete learner [refused, with the reason]
- [ ] Verify a complete learner [Official · verified; one log entry]
- [ ] Correct an official grade on Encode Grades [stays official; activity log shows old → new; Re-verify offered]
- [ ] Close the term (test system only) [every save/verify refused; pages still readable]
- [ ] Phone width: open the menu, press Escape [menu closes, focus returns to the menu button; nothing covers content]
- [ ] Learner report print preview [one learner; school year/term/section; no buttons or menu]
- [ ] Excel download [LRN as text, 0 numeric, MISSING text, blank empty, no formulas]

## 8. Deployment (Railway) — prepared, NOT executed

**Inspected path.** `railway.json` → `builder: DOCKERFILE`, `dockerfilePath: Dockerfile.vercel`,
health check `/up` (Laravel's built-in health route), restart on failure. The image:

- builds from `php:8.2-apache-bookworm` with pdo_mysql, mbstring, gd, zip, intl, bcmath,
  opcache, curl and the XML extensions;
- runs `composer install --no-dev` and a Node 22 `npm ci && npm run build`;
- serves Apache with DocumentRoot `public/` on `$PORT` (default 8080);
- sets `APP_DEBUG=false`, `display_errors=Off`, `expose_php=Off`, `LOG_CHANNEL=stderr`;
- the entrypoint refuses to start without `APP_KEY`, recreates the `storage/` tree, and runs **no
  migrations**.

`.dockerignore` excludes `.env*`, `tests/` (including the browser harness), `backups/`, `*.sql`,
`*.xlsx/*.csv`, `storage/` and docs. No scheduler or queue worker is needed
(`QUEUE_CONNECTION=sync`; no scheduled tasks are defined).

**Not verified:** Docker is not installed on the development machine, so the image has not been
built or booted. First gate: `docker build -f Dockerfile.vercel -t dss-check .` then run it
against a throwaway MySQL with a test `APP_KEY`, and check `/up`, `/login`, assets and a login.

### Service variables (names only — set values privately in Railway)

- `APP_KEY` — generate once for a NEW environment (`php artisan key:generate --show`). Never
  replace an existing production key: doing so invalidates sessions and anything encrypted.
- `APP_URL` — `https://<public domain>`
- `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `SESSION_SECURE_COOKIE=true`. The image already sets `SESSION_DRIVER=database`,
  `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`, `APP_ENV=production`, `APP_DEBUG=false` and
  `TRUSTED_PROXIES=*` (acceptable only behind Railway's ingress; `X-Forwarded-Host` is never
  trusted).
- `SEED_ACCOUNT_PASSWORD` — only if the seeder will create initial accounts. At least 12 characters;
  unique; remove it after first use.
- `MAIL_*` — only if password-reset email is wanted (otherwise the Admin resets passwords).
- Optional: a volume mounted at `/var/www/html/storage` if uploaded files and logs must survive
  redeploys (sessions and cache already live in MySQL).

### Credential gate (must be cleared before any public access)

- The working accounts still use the old shared default password (recorded in earlier audits).
  Before exposing any instance, the Admin sets a new unique password for every account
  privately: Admin > Users > Edit (password field), or the user's own Profile. This pass did not
  change any password.
- Production accounts are created either through the seeder (`SEED_ACCOUNT_PASSWORD`, then the
  variable is removed) or by the Admin in the UI. No password is committed to the repository.

### Deployment steps (after approval)

1. **Back up** the target database (`mysqldump --single-transaction`) to storage outside the app.
   Record the file name and size, and check it ends with "Dump completed".
2. Build and deploy the image (Railway builds from `railway.json`).
3. **Review migrations** before running them: `php artisan migrate:status`, then
   `php artisan migrate --pretend`. Every migration since 2026-09-28 is additive (new tables,
   nullable columns, a wider `users.role` enum). Then run `php artisan migrate --force` once, as
   a deliberate one-off command (for example `railway run` or a pre-deploy command) — never
   automatically at every start.
4. Only for a NEW database: seed the reference data (grading weights, transmutation tables,
   catalog) with the project seeders. Never run `migrate:fresh`.
5. Check the health endpoint: `GET /up` returns 200.
6. Smoke test (designated test accounts and records only):
   - login for each role;
   - a 403 on an unassigned class;
   - Class Record save and reload;
   - a Missing concern visible to the Principal;
   - verification only by the adviser;
   - Excel/report access;
   - assets load over HTTPS;
   - an error page shows no debug output;
   - `/.env`, `/storage/logs/laravel.log` and `/tests/...` return 404.
7. `php artisan dss:check-integrity` — read-only.

### Recovery

- **Application-only problem:** redeploy the previous image. The schema is additive, so older
  code tolerates the extra tables and columns.
- **Data problem:** restore the step-1 dump into a NEW database, verify it, then repoint
  `DB_DATABASE`.
- **Do not** use `migrate:rollback` as a recovery method. The recent migrations' `down()` methods
  drop `teaching_assignments`, `teaching_assignment_history`, `assessment_evidence_audits` and
  `attendance_records`, which would destroy real data. The role-enum rollback refuses while
  Subject Teacher accounts exist.

## 9. Backup and restore (local)

- Backups live in `C:\newxampp\db_backups\`, outside the web root and the repository.
- **Backup:** `mysqldump -uroot -h127.0.0.1 --single-transaction --routines --triggers
  --default-character-set=utf8mb4 naggasican_dss > C:\newxampp\db_backups\naggasican_dss_<label>_<YYYYMMDD_HHMMSS>.sql`.
  Never overwrite an existing file.
- **Restore test:** create a separate database (never `naggasican_dss`), load the dump, and
  compare every table's row count with the source.
- This pass: `naggasican_dss_pre_release_readiness_20260928_203747.sql` (547 KB) was restored
  into `naggasican_dss_restore_check`. All 38 tables' row counts and the score totals matched.
  That database may be dropped by the operator.
