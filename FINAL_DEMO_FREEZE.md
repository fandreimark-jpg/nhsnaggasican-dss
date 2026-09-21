# FINAL DEMO FREEZE — Naggasican NHS Senior High School DSS

**Frozen at:** 2026-09-21 16:18:46 (Asia/Manila, UTC+8)
**Purpose:** the exact application, configuration and dataset state used for the capstone / system demonstration. Documentation only — nothing in this file changes behaviour. Contains no passwords or secrets.

---

## 1. Environment

| Component | Version / value |
|---|---|
| PHP | 8.2.12 (XAMPP, ZTS x64) |
| Laravel | 12.62.0 |
| Database | MariaDB 10.4.32, database `naggasican_dss` on 127.0.0.1:3306 |
| Python | 3.14.6 — scikit-learn 1.9.0, numpy 2.4.6 |
| Node / npm | v24.14.0 / 11.9.0 (Vite build) |
| Web server | XAMPP Apache, console-mode, ports 80 and 443 |
| `APP_ENV` / `APP_DEBUG` | `local` / `false` |
| Session / cache / queue | `database` / `database` / `database` |
| Mail | `log` (nothing is sent) |
| Demo URL | `http://localhost/naggasican-dss/public/` (`/naggasican-dss/` redirects there) |
| Migrations | 70 ran, 0 pending |
| Routes | 94 total — admin 47, adviser 23, principal 10, other 14 |

## 2. Source control

| | |
|---|---|
| Branch | `main` |
| HEAD at freeze | `42fda0c` — *fix: refuse a repeat upload that would rewrite an item's classification* |
| Working tree | clean (before this manifest was added) |
| Preceding commits | `e939f5b` Phase 1 hardening docs · `a798143` pre-demo checkpoint · `f8a80c9` Railway deployment prep |

Never committed (git-ignored, verified with `git check-ignore`): `.env`, `storage/logs/`, `backups/`, `storage/app/private/`, root-level `*.csv` (the Term 1 intervention/remedial mapping export), `public/hot`, `analytics/models/candidate|archived`. `analytics/model_cache.pkl` is tracked on purpose.

## 3. Frozen Term 1 dataset (SY 2026-2027, Grade 11, sections Shakespeare and Curie)

| Figure | Value |
|---|---|
| Learners | 84 (42 + 42) in 2 sections |
| Subjects | 7 (4 Grade 11 core, 3 Grade 12 elective); Term 1 resolves 3 per section (*Philippine History and Society* is configured for Terms 2–3 only) |
| Assessment items | 108 (54 regular + 54 additional-support, all Term 1) |
| Assessment uploads | 22 (Term 1; 6 read through the prescribed SSHS ECR profile) |
| **Regular assessment scores** | **2,268** |
| **Additional-support (remedial) scores** | **246** |
| **Assessment scores total** | **2,514** |
| **Grades** | **252** — all verified (126 per section = 42 learners × 3 subjects; expected 126/126 both) |
| **Report submissions** | **2** (one per section, Term 1, 09:10:33 and 09:10:57) |
| **Risk results** | **84** — Low 36 / Moderate 32 / High 16; `was_overridden` = 0 for all |
| **Interventions** | **82** — all `approved`, origin `principal`, decided, acknowledged, delivered (77 group / 5 individual, all with delivery notes), all linked to a RiskResult |
| Post-delivery focus-component scores | exactly 3 per intervention (82 × 3 = 246) |
| Within-Term Progress available | 82 / 82 (all with enough evidence; change +12.0 to +30.0 pp, mean +19.95) |
| Cross-component remedial errors | 0 |
| Non-intervention remedial scores | 0 |
| Pre-delivery remedial scores | 0 |
| Other-component warnings | 0 |
| Term 2 / Term 3 records | 0 |
| Last write to any academic table | 2026-09-21 14:02:40 (assessment scores); grades 09:08:24; risk results / reports 09:25:16; interventions 10:18:46 |
| Activity-log rows after the 14:34:08 freeze | 0 |

Content checksums (read-only, for later comparison): `SUM(score×id)` over assessment_scores = 550356481.00 · `SUM(grade×id)` over grades = 7950484.00 · `SUM(average_grade×id)` over risk_results = 885647.06.

## 4. Grading configuration (read-only verification)

- `TransmutationService::schemeFor()` resolves **DO 015, s. 2026 (`do015_2026`)** for Grade 11 *and* Grade 12 in SY 2026-2027 onward when `sections.curriculum` is unset or `sshs`; **DO 8, s. 2015 (`do8_2015`)** only for an explicit `k12_2013` section or a school year before 2026-2027. Verified for (11, 2026-2027, null), (12, 2026-2027, null), (12, 2026-2027, k12_2013), (11, 2025-2026, null), (12, 2027-2028, null).
- Both live sections: Grade 11, SY 2026-2027, `curriculum = NULL`, Academic Track → `do015_2026`.
- Weights are **data**: `subject_group_weights` holds the seven DO 015 profiles (core_academic 20/50/30, academic_other 20/50/30, arts_sports_wellness 20/60/20, field_exposure 15/70/15, research_innovation 40/60/—, techpro 15/65/20, work_immersion 20/80/—) plus 6 DO 8 rows; `exam_role_shares` 3 rows (ST1/ST2/TE 30/30/40 fallback); `deped_subject_catalog` 141 rows; `transmutation_ranges` 41 bands per scheme.
- Every live subject resolves through `GradingEngine::resolveSubjectProfile()` by its subject group (core_academic 20/50/30; academic_other 20/50/30; arts_sports_wellness 20/60/20). None is catalog-linked (titles differ from DepEd catalog titles); the resulting weights and 30/30/40 exam-role split are identical to their catalog rows, so no grade differs.

## 5. Machine-learning state

| | |
|---|---|
| Artifact | `analytics/model_cache.pkl` |
| SHA-256 | `14b01918f3b210777bf3013d1f56bba79062b20f3b13e2fd05ff8314027807cd` |
| Size / modified | 171,041 bytes / 2026-09-07 21:39:04 (+08:00) |
| Registry | `analytics/models/active`, `candidate`, `archived` all empty — no candidate trained, none promoted, no new artifact |
| `classify.py` | inference only (no `.fit()`, no cross-validation, no report writer — pinned by `test_inference_contract.py`) |
| Risk results | 84 rows from exactly 2 classifier runs (09:25:00 and 09:25:16 — one per section's Submit Report); never regenerated |

**The deployed classifier is the legacy labelled synthetic prototype** (`analytics/legacy/legacy_model.json`: `version = legacy_synthetic_prototype`, `feature_names = ['average_grade']`, `dataset_type = synthetic`). Its effective production feature is **`average_grade`**, and it outputs **`low` / `moderate` / `high`** on calibrated thresholds (Low ≥ 85, Moderate 75–84.9, High < 75; observed: Low 86.00–95.00, Moderate 76.00–83.33, High 73.33–74.00). Every prediction it returns carries `dataset_type: synthetic`.

**The Term 1 experiment demonstrates DSS workflow functionality using synthetic UAT data. It does NOT establish real-world predictive validity.** No authorised historical dataset exists; a real candidate model cannot go to production until the binary-target vs three-level question is resolved with the school (see `analytics/README.md`).

## 6. Automated tests at the freeze

| Suite | Result |
|---|---|
| PHP `php artisan test` (sqlite `:memory:`, guarded in `tests/TestCase.php`) | **1,081 passed, 0 failed, 0 skipped, 5,331 assertions** (459.7 s) |
| `python analytics/test_inference_contract.py` | 34 / 34 |
| `python analytics/test_classify.py` | 6 / 6 |
| `python analytics/test_training_pipeline.py` | 62 / 62 |
| `npm run build` | exit 0 — `public/build/assets/app-ChhmiViw.css` (byte-identical to the previously audited build) + `app-zbVra3PO.js`; `public/hot` absent |
| `php artisan migrate:status` | 70 ran, 0 pending |
| `php artisan dss:check-integrity` (verified read-only) | "No orphaned or inconsistent data found." |

The live database was audited before and after the test runs: all 31 recorded figures (counts, latest timestamps, checksums, artifact hashes) identical.

## 7. Backups (outside the web root, `C:\newxampp\db_backups\`)

| File | Size | SHA-256 |
|---|---|---|
| `naggasican_dss_FINAL_TERM1_FROZEN_PASS_20260921_143408.sql` (pre-Phase-2, kept, unchanged) | 764,775 bytes | `eec8400e8060cd8edd3737ded237ae5a913b8d285f950b4a289801b54bb7eadd` |
| **`naggasican_dss_FINAL_DEMO_FREEZE_20260921_161846.sql`** (this freeze) | 773,213 bytes | **`fbc0d9ed515b04d79b34f58751979c775b7d506d91be0594bd1547f55cfe93b2`** |

The new dump: `mysqldump --single-transaction --routines --triggers --events --hex-blob --add-drop-table`, exit 0, ends with `-- Dump completed on 2026-09-21 16:18:46`, 32 tables, 2,514 `assessment_scores` tuples. Every academic and configuration table's INSERT content is identical to the 14:34 frozen dump; only `sessions` differs (HTTP session rows). Neither dump has been restored.

Older project archives (14 DSS zips and 3 stray files that used to sit in `htdocs`) were moved, hash-verified, to `C:\newxampp\db_backups\archive\`.

## 8. Apache / security status

`C:\newxampp\apache\conf\extra\httpd-naggasican-dss.conf` (included from `httpd.conf`, backup `httpd.conf.bak_20260921_pre_dss_hardening`) denies the whole project directory and re-grants only `naggasican-dss/public`; directory listings off; `.zip/.rar/.7z/.sql/.bak/.env` denied anywhere under htdocs. Re-verified at the freeze — **403** for `.env`, `.env.example`, `.git/`, `storage/`, `storage/logs/laravel.log`, `storage/app/private/`, `backups/` and the frozen dump inside it, the mapping CSV, `analytics/model_cache.pkl`, `database/`, `vendor/`, `tests/`, `composer.json`, `CLAUDE.md`, this file, `public/.htaccess`, `public/../.env`, an unsigned `public/storage/...` URL — on localhost, on the LAN address and over HTTPS. **200** for the login page, both built assets, Bootstrap Icons and the favicon. Response headers carry `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`. `httpd -t`: Syntax OK.

This is machine configuration, not repository code: re-check `curl -sI http://localhost/naggasican-dss/.env` (expect 403) before the demo, especially after any XAMPP reinstall. Apache runs as a console process here — restart it from the XAMPP control panel (there is no `Apache2.4` service).

## 9. Role and authorization verification (read-only)

Every route under `admin/`, `adviser/`, `principal/` carries exactly `auth` + `role:<role>`; `RoleAccessMatrixTest` walks the live route table on every run. Learner **create / import / import-from-ECR / enroll** exist only under `admin/students…`; the Adviser has `PUT adviser/students/{id}` (edit within their section) and no create or upload route. The Principal has no route or code path that writes `risk_results` (only `Adviser\ReportController` does, through Submit Report). Sidebar per role: Admin — Dashboard, Users, Tracks, Specializations, Subjects, Sections, Students, Academic Terms (with Academic Years), Reports, Activity Logs; Adviser — Dashboard, My Students, Encode Grades, Assessments (detect → verify → preview → import, add/edit item), Submit Report, Interventions (acknowledge, deliver, additional-support evidence); Principal — Dashboard, Students (at-risk / monitoring), Reports, Subject Analysis, Interventions. Laravel's `/up` health endpoint is public and returns only "OK".

## 10. Phase 2 repeat-upload safeguard (commit `42fda0c`)

A repeat assessment/ECR upload that matches an existing item by name can no longer silently rewrite its `component`, `exam_role`, `is_additional_support` or `max_score`. `AssessmentItemConflictDetector` compares the confirmed mapping with the stored item (same subject/section/term/year, name case-insensitive, values normalised) and any difference is refused: at Preview (the Verify screen is re-rendered with the adviser's selections intact, conflicting rows highlighted and every conflict named in words — *Stored: Written Work · Upload: Performance Task*), again in `import()` before the upload record exists, and a third time inside the service transaction before the first write (`AssessmentMetadataConflictException`, full rollback — no item, score or upload row). Identical metadata, a new item, or a same-named item elsewhere is never a conflict, so a legitimate re-upload still replaces scores as designed. `RepeatUploadMetadataConflictTest` (10 tests) is the test of record.

## 11. Demo cautions — read before presenting

- **DO NOT press "Verify All"** on the frozen Term 1 dataset (all 252 grades are already verified; re-verifying rewrites `verified_at`).
- **DO NOT re-submit** either Term 1 report (Submit Report re-runs the classifier and overwrites the 84 stored RiskResults).
- **DO NOT rerun analytics** in any form.
- **DO NOT retrain or promote** the ML model; leave `analytics/models/` empty and `model_cache.pkl` untouched.
- **DO NOT upload additional Term 1 files** — a repeat upload of an identical file is now refused only if it changes classification, but it would still replace recorded scores and move audit columns.
- **Term 2 and Term 3 are outside the frozen Term 1 demonstration** — they hold no records; *Philippine History and Society* is configured for Terms 2–3 only.
- **The additional-support (remedial) scores are synthetic, 92–97 %** (246 of 246 lie between 90 and 98 %). That is why all 82 intervention progress records show improvement (+12 to +30 percentage points).
- **Within-term improvement is observed synthetic UAT change, NOT proof of causal intervention effectiveness.** The interface uses before/after language deliberately; do not claim causation.
- **The deployed ML model is a synthetic prototype, NOT validated real-world predictive evidence.** Its risk levels are calibrated thresholds on `average_grade`.
- Section names, learner names and counts are synthetic UAT data, not the school's roster.
- Log in only with the demo accounts; an Admin resets passwords from Users (password-reset e-mails go to the log).

## 12. Non-blocking items recorded for future work

- Four Grade 11 core subjects and three Grade 12 electives are not linked to `deped_subject_catalog` rows (titles differ from DepEd's catalog titles); resolved weights are identical, so grading is unaffected. Linking is a human decision.
- Both live sections carry `specialization_id` (HUMSS / STEM) with `curriculum = NULL`; harmless for Grade 11 core-only grading, should be cleared or the curriculum set explicitly for real data.
- Apache hardening lives in XAMPP's config, not the repo; a XAMPP reinstall loses it. XAMPP's `Options Indexes` remains on for other folders in `htdocs`.
- OPcache is off in XAMPP's `php.ini` (~250 ms per request); an operator change, not made here.
- RiskResult history (keeping prior classifier runs instead of overwriting) is not implemented; a re-submission overwrites — which is exactly why the caution above exists.
- The intervention/remedial mapping CSV still exists at the project root (git-ignored, 403 over HTTP); delete it when no longer needed.
