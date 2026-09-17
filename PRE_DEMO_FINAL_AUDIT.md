# PRE-DEMO FINAL AUDIT — Naggasican NHS Learner Academic Risk and Decision Support System

Date: 2026-09-17
Branch: `main` (working tree; nothing committed by this pass — see "Files Changed")
Auditor: engineering pass run in Claude Code against the live local checkout

---

## Executive Summary

The system was audited end to end — architecture, baseline commands, authentication, authorization/IDOR, privacy, database integrity, academic-year/term behaviour, the grading engine and its provisional/fallback markers, ECR and file imports, the Python Random Forest integration, UI/UX, favicon, error pages, responsiveness (by code inspection — see caveat), slow-network states, concurrency controls, security, performance, deployment hygiene — and then fixed what the audit actually proved, with a regression test for every fix.

The application entered this pass in strong shape: **994 automated tests passed, 0 failed, 0 skipped**, every object-level endpoint already scoped through `Section::forAdviser()`, the Python call already used an argument-array `Process` with a timeout and output validation, and the schema already carried every composite unique constraint and deletion guard the audit checks for. The defects the audit **did** find and fix were real but peripheral to academic logic:

1. **No error pages existed at all** (`resources/views/errors/` was absent) — a wrong URL or a cross-role request showed Laravel's stock unbranded screen.
2. **Favicon inconsistency** — only the login page declared a tab icon; `public/favicon.ico` was a 0-byte placeholder, so the icon vanished on sign-in.
3. **830 abandoned temporary upload files (~22 MB)** in `storage/app/private` that nothing ever removed; the test suite itself added ~100 per run because it never faked the storage disk.
4. **Every Edit modal posted to a hardcoded root-relative path** (`/admin/users/${id}` and six siblings) that is wrong under the XAMPP sub-directory deployment this machine actually serves (`http://localhost/naggasican-dss/public/`).
5. Smaller hardening: classifier `confidence` never range-checked; the assessment-score import's write phase not wrapped in a transaction; the last `{!! !!}` in the views; `robots.txt` allowing indexing; login form lacking `type="email"`, autocomplete, an accessible show-password control, a non-implying placeholder, and a "Signing in…" state; nine upload/intervention forms falling back to the generic "Processing…" label; a 4-column stat grid that did not collapse on phones; the flash toast wider than a 360 px viewport allows.
6. Documentation drift: `ExamRoleSharesSeeder` still described 30/30/40 as "PROVISIONAL — not confirmed anywhere in this codebase" although the per-subject shares now come from DepEd's own ECR catalog and the seeder is only the fallback. Corrected the note; the numbers were not touched.
7. Nine verified-dead Breeze leftover files removed (7 unused Blade components, the stock Laravel SVG logo component, `AppLayout.php`).

No grading formula, transmutation band, risk threshold, ML model, migration, seeder value, `.env`, or database row was changed. No destructive artisan command was run.

## Final Classification

### **READY WITH DOCUMENTED LIMITATIONS**

Evidence for "ready": every critical workflow has automated coverage that passes (see "Automated Test Results"); no authorization or IDOR gap was found by direct URL/ID testing; secrets are not exposed; disabled users are evicted mid-session; imports validate → preview → confirm → transaction; analytics failure degrades to a recorded submission with an explicit warning; production assets build; the diff is reviewed.

Why not "READY FOR DEMO" outright — the limitations below are real and must be stated to a panel rather than hidden:

- The per-subject Examination shares and the DO 015 transmutation bands are confirmed against DepEd's own operational ECR workbook, **not** against the signed PDF of DO 015, s. 2026.
- The production Random Forest is trained on synthetic data and is effectively a threshold on `average_grade` (its own `model_accuracy.txt` reports 100 % importance on that single feature). The training pipeline's held-out accuracy of 100 % on the fixture cohort is a symptom of that, not a strength — it is reported, not hidden.
- Responsive behaviour was verified by code inspection only (no browser was available to this pass); the Manual UAT section below lists the exact widths and pages a human must still check.
- The live demo database currently has 0 grades, 0 risk results, 0 interventions, 0 report submissions and **every term closed**; the Principal dashboard will be empty until the demo checklist's data-preparation steps are done.

---

## Environment Tested

| Item | Value |
|---|---|
| OS | Windows 11 Home 10.0.26200 (XAMPP) |
| PHP | 8.2.12 (ZTS) with GD, XML |
| Laravel | 12.x (`laravel/framework ^12.0`) |
| Database (live) | MySQL `naggasican_dss` on 127.0.0.1:3306 (`DB_CONNECTION=mysql`) |
| Database (tests) | SQLite `:memory:` (`phpunit.xml`) |
| Node / Vite | Vite 7, Tailwind 3, `npm run build` OK |
| Python | 3.14.6; `analytics/requirements.txt` satisfied |
| Servers found running | Apache :80 (serves `/naggasican-dss/public/`), `php artisan serve` :8000, Vite dev server :5173 (`public/hot` present and legitimate — do not delete while `npm run dev` runs) |
| `APP_DEBUG` | `false` in the local `.env` (correct for demo) |

Baseline commands run before any change:

| Command | Result |
|---|---|
| `php artisan optimize:clear` | OK |
| `php artisan migrate:status` | 65 migrations, all `Ran`, 0 pending |
| `php artisan route:list` | 101 routes |
| `php artisan test` | **994 passed, 0 failed, 0 skipped** (3,391 assertions, 298.7 s) |
| `npm install` / `npm run build` | OK (63 modules; 135.9 kB CSS, 79.9 kB JS) |
| `npm audit` | 7 vulnerabilities (2 critical, 3 high, 1 moderate, 1 low) — **all in devDependencies** (`concurrently`/`shell-quote`, `postcss`, `browserslist`, `nanoid`, `esbuild`); nothing ships to the browser |
| `composer validate` | valid |
| `composer check-platform-reqs` | all satisfied |
| `php artisan dss:check-integrity` | "No orphaned or inconsistent data found." |
| `python analytics/test_classify.py` | 3 tests OK |
| `python analytics/test_training_pipeline.py` | 16 tests OK (prints "Held-out accuracy: 100.0 %" — see ML section) |
| `php -l` on every changed PHP file | no syntax errors |

Environment failures vs application failures: **none of the baseline commands failed**. The one tool that was not available was a browser (declined for this session), which limits the responsive audit to code inspection — an environment limitation, recorded honestly below, not an application failure.

## Architecture Verified

- **Routing**: `routes/web.php` — three prefixed groups (`admin/*` `role:admin`, `adviser/*` `role:adviser`, `principal/*` `role:principal`), all behind `auth`; `routes/auth.php` (Breeze) for login/logout/password reset; `/` and `/dashboard` redirect via `User::dashboardRouteName()`.
- **Middleware** (`bootstrap/app.php`): `RoleMiddleware` (403 on mismatch), `EnsureAccountIsActive` (re-reads the user on every request and evicts a disabled/unknown-role account), `PreventBackHistory` (`no-store` on every web response), trusted proxies for the Vercel deployment.
- **Authentication**: Breeze `LoginRequest` — rate limit 5/min per email+IP, disabled-account check only after the password verifies (no account enumeration), session regeneration on login, invalidation + token regeneration on logout.
- **Academic year/term**: `AcademicYear` (permanent entities, `activate()` transactional), `AcademicTerm::acceptsWrites()` = open term AND active year, enforced in every adviser write path.
- **Grading**: `App\Services\GradingEngine` is the single source of truth (weights: catalog row → `SubjectGroupWeight::resolve()` → scheme default; exam shares: catalog → `exam_role_shares`); `TransmutationService` (41 bands per scheme, optional fallback scheme that is **null** in config, so no provisional grades exist — verified live: `is_provisional = 0` rows, `fallbackActiveFor()` false for both grade levels).
- **Assessment workflow**: detect → verify → preview → import (`Adviser\AssessmentController` + `AssessmentUploadService`), temp files under `storage/app/private/temp_assessment_uploads/<uuid>.<ext>`, filename regex-validated before any path is built.
- **ECR import**: `EcrProfileDetector`/`EcrReaderService` (SSHS), `Grade12EcrProfileDetector`/`Grade12EcrReaderService`, Admin "Import Learners from ECR" preview → confirm in a `DB::transaction`.
- **Risk**: `Adviser\ReportController::submit()` → JSON payload → Symfony `Process([python, classify.py, in, out])` with 120 s timeout → output validated → `RiskResult` rows; rule-based `applyFailingSubjectOverride()` on top of the ML level (`was_overridden` recorded).
- **Interventions**: Principal-only write surface; adviser acknowledge/deliver scoped by `section_id`; `Intervention::scopeUndecided()` single rule.
- **Audit log**: `LogActivity::log()` on every write path found (login, users, students, sections, subjects, terms/years, grades, verification, submission, interventions).
- **Frontend**: Tailwind design system in `resources/css/app.css` + `x-ui.*`/`x-stat-card`/`x-panel` components; one JS bundle (`resources/js/*.js` via Vite); local Bootstrap Icons and Chart.js.

## Changes Implemented

| # | Change | Files |
|---|---|---|
| 1 | Branded error pages 403/404/419/500/503 on a self-contained shell (no dependency on `auth()->user()`), `noindex`, safe navigation (dashboard when signed in, sign-in otherwise; 419 always sign-in) | `resources/views/errors/{layout,403,404,419,500,503}.blade.php` |
| 2 | One favicon source `<x-app-favicon />`; 32/192/180 px PNGs and a real 32×32 PNG-in-ICO generated from the school logo with GD; included in app layout, guest layout, login, error shell; page `<title>` now includes the page name | `components/app-favicon.blade.php`, `public/images/favicon-32.png`, `favicon-192.png`, `apple-touch-icon.png`, `public/favicon.ico`, `layouts/app.blade.php`, `layouts/guest.blade.php`, `auth/login.blade.php` |
| 3 | Guest layout (password-reset pages) shows the school logo instead of the stock Laravel SVG | `layouts/guest.blade.php` |
| 4 | Login: `type="email"`, `autocomplete="username"/"current-password"`, `inputmode`, neutral placeholder, labelled `aria-pressed` show-password button, `role="alert"` on errors, `data-loading="Signing in…"` | `auth/login.blade.php`, `resources/js/auth.js` |
| 5 | `TempUploadPruner` service — removes files older than 24 h from a flow's temp directory at the start of each new upload (no scheduler needed) | `app/Services/TempUploadPruner.php`, `Adviser\AssessmentController::detect()`, `Admin\StudentController::importFromEcrPreview()` |
| 6 | Base `TestCase` fakes the `local` disk so tests stop writing real files | `tests/TestCase.php` |
| 7 | Classifier output validation extracted to public `classifierOutputIsValid()` and extended with a `confidence` range check (0–100 or null) | `Adviser\ReportController` |
| 8 | Assessment-score import write phase wrapped in one `DB::transaction` (row-level rejections unchanged) | `app/Services/AssessmentUploadService::import()` |
| 9 | Edit-modal form actions derived from `data-update-url` (`route()` with `__ID__`) via `window.updateUrlFor()` instead of hardcoded `/admin/...` paths | `resources/js/modal.js`, 7 Blade forms |
| 10 | Action-specific loading labels on 10 more forms (sections/specializations/subjects/tracks/grades imports, ECR preview, roster extraction, score validation, single and bulk intervention recording) | 8 Blade files |
| 11 | Last `{!! !!}` converted to `{{ }}` (content was static strings; no markup) | `admin/dashboard.blade.php` |
| 12 | Academic-year record grid `grid-cols-4` → `grid-cols-2 sm:grid-cols-4`; flash toast `max-w-[calc(100vw-2rem)]`, `role="status" aria-live="polite"` | `admin/academic-terms.blade.php`, `layouts/app.blade.php` |
| 13 | `robots.txt` → `Disallow: /` | `public/robots.txt` |
| 14 | `ExamRoleSharesSeeder` docblock corrected to describe the table as the fallback behind the catalog (numbers unchanged) | `database/seeders/ExamRoleSharesSeeder.php` |
| 15 | `npm audit fix` (non-breaking): 7 → 1 advisory | `package-lock.json` |
| 16 | CLAUDE.md: new baseline + "Pre-demo audit pass" conventions section | `CLAUDE.md` |
| 17 | 27 new automated tests (7 files) — listed under "Automated Test Results" | `tests/Feature/*`, `tests/Unit/*` |

## Redundant Code Removed

Rule applied: delete only with zero references across `resources/`, `app/`, `tests/`, `routes/`, JS and config, confirmed by exact-tag grep (`<x-name[ >/]`), then run the suite.

| File | Reason | Evidence it was unused | Verification |
|---|---|---|---|
| `resources/views/components/danger-button.blade.php` | Breeze scaffold leftover | 0 `<x-danger-button` anywhere | full suite green |
| `resources/views/components/dropdown.blade.php` | Breeze scaffold leftover | 0 `<x-dropdown` (word "dropdown" only appears in CSS/JS comments and controller variable names) | full suite green |
| `resources/views/components/dropdown-link.blade.php` | Breeze scaffold leftover | 0 `<x-dropdown-link` | full suite green |
| `resources/views/components/modal.blade.php` | Breeze scaffold leftover; the app's modals are hand-written `role="dialog"` blocks with `.modal-box` | 0 `<x-modal` | full suite green |
| `resources/views/components/nav-link.blade.php` | Breeze scaffold leftover; sidebar uses the `.nav-link` CSS class, not the component | 0 `<x-nav-link` | full suite green |
| `resources/views/components/responsive-nav-link.blade.php` | Breeze scaffold leftover | 0 `<x-responsive-nav-link` | full suite green |
| `resources/views/components/secondary-button.blade.php` | Breeze scaffold leftover | 0 `<x-secondary-button` | full suite green |
| `resources/views/components/application-logo.blade.php` | Stock Laravel SVG; its only consumer (guest layout) now shows the school logo | 1 usage, replaced in the same change | `FaviconConsistencyTest` asserts the SVG is gone |
| `app/View/Components/AppLayout.php` | Class component for `<x-app-layout>`; every one of the 24 pages uses `@extends('layouts.app')` | 0 `<x-app-layout` | full suite green |

Kept deliberately (used): `GuestLayout.php` (4 auth views use `<x-guest-layout>`), `primary-button`/`text-input`/`input-label`/`input-error`/`auth-session-status` (auth views), `count-label` (15 usages), every `resources/js/*.js` file (each `window.init*` global is called from at least one view), the inert email-verification and confirm-password auth stacks (routes registered, no UI link; a prior explicit decision to keep — unchanged), `storage.local`/`storage.local.upload` (Laravel 12's signed-URL-gated local-disk routes; unused by the app but harmless and framework-default).

Debug statements: `dd(`/`dump(`/`var_dump(`/`print_r(` — **0** in `app/`, `resources/`, `routes/`, `database/`. `console.log` — **0** in `resources/js`, `public/js`.

## UI/UX Improvements

The design system from the 2026-09-14 modernization pass (tokens `#1F6B2A`/`#3FAE4D`/`#F5F7FA`/`#18212F`/`#667085`/`#E6EAF0`, `.card`/`.btn`/`.badge`/`.tbl`/`.filter-bar`/`.modal-box`, grouped sidebar, `x-stat-card`/`x-panel`) was already in place and consistent; this pass did not restyle it. It added the pieces that were missing (error pages, favicon, login semantics, loading labels, two mobile fixes) and confirmed by inspection: all 24 data tables sit in `.tbl-scroll`/`overflow-x-auto` wrappers; all 7 dialogs use `.modal-box` (viewport-safe, self-scrolling); all 4 Chart.js canvases are `responsive: true`; no `min-width`/fixed pixel width wider than 260 px exists outside a scroll container; every stat grid collapses at `sm`/`xl`; the sidebar is an off-canvas drawer below `md` with an accessible close button; every icon-only control found carries `aria-label`.

## Favicon Fix

Before: `auth/login.blade.php` alone had `<link rel="icon" href="images/nagga-logo.png">` (the raw 500×500, 210 kB PNG); `layouts/app`, `layouts/guest` had none; `public/favicon.ico` was 0 bytes. After: `<x-app-favicon />` in all four shells; four derived icon files; `FaviconConsistencyTest` asserts the identical declaration on login, guest, admin, adviser, principal and error pages, exactly one `sizes="32x32"` per page, and a genuine ICO header in `favicon.ico`.

## Authentication Results

All via real HTTP (`POST /login`), not `actingAs()`:

| Check | Result | Test |
|---|---|---|
| Login page renders | ✅ | `Auth\AuthenticationTest` |
| Valid login per role → correct dashboard | ✅ | `Auth\AuthenticationTest` (3 roles) |
| Invalid password | ✅ generic message | `Auth\AuthenticationTest` |
| Unknown email gets the **same** message as wrong password | ✅ | `AuthenticationHardeningTest` |
| Blank fields / malformed email rejected server-side | ✅ | `AuthenticationHardeningTest` |
| Disabled account cannot log in; specific message only after correct password | ✅ | `UserManagementTest` |
| Disabled account's **existing** session evicted on next request | ✅ | `AuthenticationHardeningTest` |
| 6th failed attempt throttled (even with the right password) | ✅ | `AuthenticationHardeningTest` |
| Logout invalidates session; protected page redirects after | ✅ | `AuthenticationHardeningTest` |
| Back-button: `Cache-Control: no-store` on authenticated responses | ✅ | `AuthenticationHardeningTest` |
| Signed-in user visiting `/login` → own dashboard | ✅ | `AuthenticationHardeningTest` |
| Guest visiting protected page → `/login` | ✅ | `RoleAuthorizationTest` |
| CSRF | Laravel `ValidateCsrfToken` on the `web` group; 419 now renders a branded page | `ErrorPagesTest` |
| Session expiry | `SESSION_LIFETIME` default 120 min, `SESSION_DRIVER=database` | config only |
| Remember-me | not exposed in the UI (checkbox absent); `LoginRequest` would honour it if posted | n/a |
| Self-registration | no route, no link | `LoginPageUxTest` |

## Authorization Matrix

`✅` = allowed and tested, `403` = forbidden and tested, `→login` = redirected and tested.

| Surface | Guest | Admin | Adviser | Principal | Disabled |
|---|---|---|---|---|---|
| `/admin/*` (users, students, sections, subjects, tracks, specializations, terms, years, reports, activity) | →login | ✅ | 403 | 403 | →login (evicted) |
| `/adviser/*` (dashboard, students, grades, assessments, submit-report, interventions) | →login | 403 | ✅ | 403 | →login |
| `/principal/*` (dashboard, students, reports, subject-analysis, interventions) | →login | 403 | 403 | ✅ | →login |
| `POST /principal/interventions*` (the only Principal write) | →login | 403 | 403 | ✅ | →login |
| Adviser assessment/grade/report writes on a **closed term or inactive year** | — | — | refused with reason | — | — |

Tests: `RoleAuthorizationTest` (40+ cases), `NewRoutesRoleAuthorizationTest`, `AcademicHistoryArchitectureTest`, `ErrorPagesTest` (403 page).

## IDOR Test Results

Every adviser endpoint that takes an object is resolved through `Section::forAdviser(auth()->id())` and then either `abort_if(... section_id !== ...)`, `Student::enrolledIn($section)`, or `Subject::forSection($section)`:

| Endpoint | Guard | Test |
|---|---|---|
| `PUT adviser/students/{id}` | `enrolledIn($section)` lookup | `CrossSectionAccessTest` |
| `POST adviser/grades` (student_id/subject_id in payload) | `enrolledIn` + `forSection` whitelists | `CrossSectionAccessTest`, `GradeEncodingScopeTest` |
| `POST adviser/grades/verify`, `verify-all` | student + subject scoped; 403 on foreign subject | `VerifyAllScopedToAdviserSectionTest` |
| `POST adviser/assessments/detect|preview|import` (subject_id) | `forSection` | `CrossSectionAccessTest` |
| `PUT adviser/assessments/item/{assessment}` | `abort_if(section_id mismatch, 403)` | `ManualAssessmentItemTest` |
| `POST adviser/interventions/{id}/acknowledge|deliver` | `abort_if(section_id mismatch, 403)` | `AdviserInterventionTest`, `AdviserInterventionDeliveryTest` |
| `POST adviser/interventions/deliver-group`, `acknowledge-all` | query scoped `where section_id` | `GroupDeliveryIsLabelledTest`, `NoBlindBulkDeliveryRouteTest` |
| `GET principal/students/{student}` | Principal is school-wide by design (read-only) | `PrincipalStudentDrilldownTest` |
| Admin `{id}` routes | Admin is master-data owner by design; deletion guarded by `ProtectsAcademicHistory` | `SectionManagementTest` etc. |

No IDOR was found. The temp-file parameter `stored_filename` is regex-locked to `^[a-f0-9-]+\.(xlsx|xls|csv|txt)$`, so no path traversal is possible on the preview/import steps.

## Privacy Findings

| Data | Where shown | Assessment |
|---|---|---|
| LRN | Admin Students; Adviser Students/Assessments/Preview; Principal Students list and detail | Admin and the learner's own adviser need it operationally; the Principal is the school head and sees their own school's identifiers — defensible, retained. Not in URLs, not in logs. |
| Birthdate | Admin Students, Adviser Students (own section only) | Not shown to Principal. Retained. |
| User email | Admin Users, Admin Sections (adviser column) | Admin only. Retained. |
| Uploaded filename | Adviser Verify screen (the file the adviser just chose), Admin draft-roster notice | Own upload only; `stored_filename` (UUID) is a hidden field, never a server path. |
| Exception messages | `GradeController::verifyAllRemaining` surfaces a `RuntimeException` message — the message is composed by the app itself ("Last, First — <outcome>") | Acceptable. No other `getMessage()` reaches a response. |
| Stack traces | `APP_DEBUG=false`; 500 page is branded | ✅ |
| `.env` | git-ignored, never committed (`git log --all -- .env` empty); `.dockerignore` excludes it | ✅ |
| `storage/logs/laravel.log` (9.5 MB, git-ignored) | Contains one 2026-06 `QueryException` whose logged SQL includes bcrypt **hashes** from a failed seeder run (no plaintext) | **Recommendation**: rotate/delete this local log before packaging anything; not modified by this pass. |
| Other `storage/logs/audit-*.log` files | prior audit scratch output, git-ignored | delete before packaging |

**Distributable ZIP risk — must NOT be included**: `.env`, `storage/app/private/**` (real uploaded ECR workbooks), `storage/logs/**`, `backups/**` (timestamped SQL dumps of live learner data), `analytics/datasets/**`, `node_modules/`, `vendor/`, `_ide_helper*.php`. `.gitignore` and `.dockerignore` already exclude all of these; a hand-made ZIP must respect the same list.

## Database Integrity Results

Verified live via `SHOW CREATE TABLE` on all 18 academic tables:

- Composite uniques: `grades (student, subject, period, year)`, `assessments (subject, section, period, year, name)`, `assessment_scores (assessment, student)`, `report_submissions (section, period, year)`, `risk_results (student, period, year)`, `student_enrollments (student, year)`, `section_subjects (section, subject, year)`, `academic_terms (year, term)`, `academic_years (school_year)`, `students (lrn)`, `users (email)`, `users (role_singleton_key)`, `specializations (track, code, curriculum)`.
- Foreign keys on every relation; `ON DELETE CASCADE` only where a parent's removal legitimately removes its evidence (scores under an assessment, enrollments/grades/risk/interventions under a student, subject links under a section) — and each of those parents is protected by `ProtectsAcademicHistory` (model `deleting` hook that throws a `ValidationException` rendered by the app layout) whenever academic references exist. `activity_logs.user_id` cascades, but a user with activity rows cannot be deleted (guard lists `activity_logs`) — audit history is preserved; disable is the supported path.
- `school_year` scoping and `(school_year, grading_period)` indexes on every academic table; `sections_adviser_year_index`.
- Transactions: year activation, grade verify-all, intervention bulk decisions, ECR learner import confirm, and (new) assessment-score import.
- `dss:check-integrity`: clean before and after.
- No migration was edited; no migration was added (none was needed); no `migrate:fresh|refresh|db:wipe`.

## Academic/Grading Results

- Single source of truth confirmed: `GradingEngine::computeGrade()`; no duplicate weighted calculation in controllers, Blade, JS or Python (Python receives an already-computed `average_grade`).
- Weight resolution: catalog row → subject group → scheme default; DO 8 by section track. Tests: `GradingEngineTest`, `SubjectGroupWeightingTest`, `CatalogWeightsBeatSubjectGroupTest`, `Do8WeightsResolveByTrackTest`, `SubjectClassificationConsistencyTest`, `ExamRoleWeightingTest`, `TermExamOnlySubjectExpectsNoSummativeTestsTest`.
- Missing component → grade incomplete, never guessed (`GradingEngineTest`); unassessed subject excluded from In-Term Status (design decision 1).
- Transmutation: 41 bands per scheme seeded and matched against DepEd's own ECR (`Do015BandsMatchOfficialEcrTest`); fallback scheme config is `null`; **0 provisional grades exist**; the dormant "Provisional" banner/badge mechanism was left intact as a safeguard.
- Marker-word classification (final source scan): `TODO` — 7 occurrences, all pointing at the same two documented items (transmutation seeder history, `ExamRoleSharesSeeder`, `DatabaseSeeder` core-list note) — legitimate, kept. `provisional` — 24 in `app/` (the provisional-grade display mechanism) — legitimate. `temporary` — 2 (`TempUploadPruner` docblock, Vercel note) — legitimate. `placeholder` — 30, all HTML `placeholder=` attributes — legitimate. `fallback` in `GradingEngine`/`TransmutationService` — 10, all documented resolution-order comments — legitimate. `FIXME` — 0.

## Exam Role Share Audit (section 12)

- Seeded in `ExamRoleSharesSeeder` → `exam_role_shares`: `do015_2026` st1 30 / st2 30 / term_exam 40 (3 live rows, seeded 2026-09-10).
- Consumed only by `GradingEngine::examinationPercentage()` and only **after** a linked `deped_subject_catalog` row's own shares (122 of 141 rows carry `st1_share`) — the catalog wins.
- Live linkage: 3 of 4 subjects linked. The unlinked one is **Oral Communication (Grade 11, core)** — a K-12 2013 name absent from the Strengthened SHS catalog — so it is the one subject whose Examination component computes on the 30/30/40 fallback. This is already recorded in CLAUDE.md as a finding about the pilot data, not a code gap.
- Demo dependence: any demo grade for Oral Communication with ST1/ST2/TE items uses the fallback split; the other three subjects use their catalog shares.
- Can it produce incorrect output? Only if a subject whose true split is not 30/30/40 (a TE-only or no-exam subject) is left unlinked — the catalog-link is the fix, not editing the table. Documented as a limitation; numbers unchanged.

## ECR/Import Results

Existing automated coverage (all passing): correct XLSX (real DepEd fixture `SSHS-E-Class-Record-SY-2026-2027.xlsx`, `GRADE-12-SANITIZED.xlsx`), wrong file type, MIME-sniffing regression (`EcrHttpUploadValidationTest`, `MimesFixSixMoreRoutesTest`), wrong/unknown ECR profile, empty workbook/no columns, invalid score, score > HPS, duplicate LRN in file, unknown LRN, MAX-row validation, filename/subject mismatch notice, weight-mismatch notice (never overwrites), preview → confirm, Insert/Existing/Conflict/Rejected classification, rejected-rows download, unauthorized upload (403), cross-section upload, closed-term refusal, oversize (`max:2048` KB flat, `max:10240` KB ECR; PHP `upload_max_filesize=40M`). Import forms use the "Confirm Import / Yes, Import" modal — no import uses the Delete modal (verified by grep). New in this pass: write phase atomic (`AssessmentUploadService::import()`), temp files pruned. Not automated: a genuinely large (>10 MB) upload — see Manual UAT.

## ML/Analytics Results

- Invocation: `Symfony\Process` argument array, `config('services.python_path')` (env `PYTHON_PATH`, default `python`), 120 s timeout, per-execution UUID temp files, `finally` cleanup. No shell string, no user data in arguments. ✅
- Model: `analytics/model_cache.pkl` loaded by `classify.py`; scikit-learn/NumPy/joblib satisfied; `python analytics/test_classify.py` OK.
- Failure paths: missing Python binary → submission recorded, `warning` flashed, no `RiskResult` (`ReportAnalyticsFailureTest`); malformed/partial/duplicate/out-of-range output → rejected as a whole (`ClassifierOutputValidationTest`, new); timeout → `ProcessTimedOutException` caught → same warning path.
- Rule adjustment and override tracking: `applyFailingSubjectOverride()` (`RiskClassificationTest`), `ml_risk_level` + `was_overridden` persisted (`RiskAnalyticsPayloadTest`).
- **Documented, not hidden**: `train_model.py` reports "Held-out accuracy: 100.0 %" and `model_accuracy.txt` reports 100 % feature importance on `average_grade`. The model is a single-feature threshold classifier trained on synthetic data; `RiskFeatureExtractor` already computes WW/PT/Exam means and trends that the model does not yet use. The training pipeline saved a *candidate* (`v20260917_185846`, status `candidate`) during the baseline run — it was **not** promoted; the active model is unchanged.
- No PII is sent to Python (`RiskAnalyticsPayloadTest` — payload is `student_id` + numeric features).

## Responsive Results

**Caveat**: no browser was available to this session, so this is a code-inspection result, not a rendered one. What was verified: viewport meta on every shell; sidebar off-canvas below `md` with hamburger + close; `page-header` stacks below `md`; `filter-bar` wraps; every table in a horizontal scroll container; modals `.modal-box` with `max-h-[92vh]` and `mx-4`; stat grids `grid-cols-1 sm:grid-cols-2 xl:grid-cols-4`; the one un-prefixed 4-column grid fixed; toast capped to viewport; charts responsive. Pages/widths a human must still confirm are listed under Manual UAT.

## Slow-Network/Error-State Results

- `resources/js/confirm.js` disables and relabels the submit button of every non-`data-no-loading` form on submit (`aria-busy`), using `data-loading` when present. Now labelled: Signing in…, Reading assessment form…, Validating scores…, Importing E-Class Record…, Analyzing learner performance…, Importing students/sections/specializations/subjects/tracks/grades…, Validating E-Class Record…, Reading E-Class Record…, Recording intervention(s)…
- Error states: branded 403/404/419/500/503; analytics failure → explicit warning; closed term/inactive year → reason text; no active year / no adviser / no subjects / no assessments → existing empty-state components and Data Health panel.
- No progress percentages are shown anywhere (none are known).

## Concurrency Results

Exactly what was tested — no load testing was performed:

| Scenario | Control | Evidence |
|---|---|---|
| Double report submission | unique `(section, period, year)` + `updateOrCreate`; re-submit confirm modal + disabled button | schema; `data-resubmit` |
| Double-click grade verification | unique grade row + `updateOrCreate` (idempotent) | `GradeVerificationTest` |
| Verify-all partial failure | `DB::transaction` rolls back all | `VerifyAllRollsBackOnFailureTest` |
| Duplicate intervention | server check for an open one per (student, subject, term) | `InterventionWorkflowTest` |
| Duplicate student | unique `lrn` + validation | `AdminStudentManagementTest`, `StudentsImportTest` |
| Duplicate assessment score | unique `(assessment, student)` + `updateOrCreate` | `AssessmentDataLayerTest` |
| Repeated import | temp file deleted after success; second submit finds no file → "start the upload again" | `AssessmentUploadWorkflowTest` |
| Two simultaneous analyses | per-execution UUID temp files | `runAnalytics()` |
| Year activation race | table lock inside `DB::transaction` | `AcademicYearConfigurationTest` |

## Security Findings

| Severity | Component | Scenario | Fix | Evidence |
|---|---|---|---|---|
| Low | Error handling | Unbranded framework error screens on 403/404/419/500 (no information leak with `APP_DEBUG=false`, but no safe navigation and inconsistent branding) | Branded error pages | `ErrorPagesTest` |
| Low | Analytics output | A classifier writing a non-numeric or out-of-range `confidence` would be persisted into `decimal(5,2)` (DB error → 500) | Range check; whole result set rejected | `ClassifierOutputValidationTest` |
| Low | Storage hygiene | Abandoned uploads (real learner ECR workbooks) accumulate indefinitely under `storage/app/private` | `TempUploadPruner` at each upload | `TempUploadPrunerTest`; 819 stale files removed |
| Low (defence in depth) | Blade | One `{!! !!}` (static strings) | `{{ }}` | grep = 0 |
| Info | `robots.txt` | allowed indexing of a login-only app | `Disallow: /` | file |
| Info | Deployment | edit-form actions wrong under sub-directory hosting (correctness, not security) | route-derived URLs | `EditFormActionsAreRouteDerivedTest` |
| Info | Dev dependencies | `npm audit` 7 → 1 (esbuild dev-server file read on Windows, GHSA-g7r4-m6w7-qqqr; dev server only, never in production) | `npm audit fix`; residual documented | `npm audit` |

Checked and clean: mass assignment (no `$guarded = []`; `role`/`is_active` excluded from `$fillable`), SQL injection (all `whereRaw` use bindings; no interpolated `DB::statement`), command injection (argument-array `Process`), path traversal (regex on `stored_filename`), open redirects (none), CSRF (framework), session fixation (regenerate on login), password handling (bcrypt via `Hash`, never logged by the app), debug exposure (`APP_DEBUG=false`), secrets (`.env` never committed).

## Performance Findings

- Eager loading on every list controller checked (`Admin\StudentController` 15 `with()`, `SectionController` 12, `UserController` 10, `ActivityLogController` `with('user')`, Principal/Adviser intervention controllers 8–9).
- Pagination on Admin Students/Users/Activity Logs, Adviser Students/Dashboard, Principal Interventions and the at-risk list (25/page, `DashboardScalePerformanceTest`).
- Admin Sections and Principal Students are unpaginated by design (a school has tens of sections; Principal Students is filtered per section/term).
- Assets: one 80 kB JS + 132 kB CSS bundle (down 4 kB after dead-component removal), icons and Chart.js local.
- No Redis/queue/cache infrastructure was introduced; none is needed at this scale.

## Automated Test Results

Final run (after all changes): see the line recorded at the end of this file under "Final validation run".

New tests added by this pass (27):

| File | Tests |
|---|---|
| `tests/Feature/AuthenticationHardeningTest.php` | 7 — session eviction on disable, throttle, blank/malformed input, no enumeration, signed-in `/login` redirect, logout invalidation, `no-store` |
| `tests/Feature/ErrorPagesTest.php` | 5 — 404 guest/signed-in, 403 cross-role, 419/500/503 render without auth and leak nothing, noindex + favicon on all |
| `tests/Feature/FaviconConsistencyTest.php` | 3 — identical declaration on 6 shells, icon files real, guest layout logo |
| `tests/Feature/LoginPageUxTest.php` | 3 — input semantics, placeholder, no self-registration |
| `tests/Feature/EditFormActionsAreRouteDerivedTest.php` | 3 — 6 admin forms, adviser form, `modal.js` has no hardcoded action |
| `tests/Unit/TempUploadPrunerTest.php` | 3 |
| `tests/Unit/ClassifierOutputValidationTest.php` | 3 |

Python: `test_classify.py` 3 OK, `test_training_pipeline.py` 16 OK (unchanged). Skipped tests: **0**.

## Remaining Limitations

1. **Signed-order verification** — exam-role shares (catalog and fallback) and the 41 DO 015 transmutation bands are confirmed against DepEd's operational ECR workbook, not the signed PDF of DO 015, s. 2026. Research task, not code.
2. **Single-feature synthetic model** — production Random Forest is effectively a threshold on `average_grade`; 100 % accuracy figures are an artefact of perfectly separable synthetic training data. Retraining awaits the school's real historical dataset (`TRAINING_DATA_CONTRACT.md`). Not changed here by instruction.
3. **Oral Communication is not a Strengthened SHS subject** — one of the four live subjects; its Examination split uses the 30/30/40 fallback. Data issue for the school to resolve.
4. **Elective assignment (`section_subject`) is empty** pending the school's answer to Q1 and a real roster (CLAUDE.md, "RESOLVED (mechanism)").
5. **Historical dataset format** not finalized — no semester/quarter redesign attempted.
6. **Responsive verification is by inspection only** in this pass.
7. **Demo database state** — 0 grades/risk/interventions/reports, all terms closed, sections Mahogany and Agila have no adviser, `2027-2028` exists inactive. The dashboards will look empty until the demo checklist is followed.
8. **`npm audit`** residual: 1 low (esbuild dev server, Windows-only, development only).
9. **Local `storage/logs/laravel.log`** contains a historical bcrypt hash inside a logged failed-seed SQL statement; git-ignored; rotate before packaging.
10. **Six generic Maatwebsite importers** still read without `setReadDataOnly(true)` (pre-existing, documented in CLAUDE.md) — a very large spreadsheet uploaded to Import Tracks/Sections/etc. could exhaust memory; not a demo path.
11. **ECR roster reconciliation catches only one direction** (learner in file but not enrolled; not the reverse) — pre-existing, documented.

## Manual UAT Required

**Guest**: open `/login` (tab icon = school seal); wrong password (generic message); 6 wrong attempts (throttle message); open `/admin/dashboard` while logged out (redirect); open `/nothing` (branded 404 with "Go to sign in").

**Admin** (`admin@naggasican.edu.ph`): dashboard cards + Data Health panel; Users → add adviser, Edit (verify the form saves — this exercises the new `data-update-url`), Disable → that adviser's open tab is kicked to login on next click; Students → add, edit, import CSV, Import Learners from ECR (preview → confirm), draft roster; Sections/Subjects/Tracks/Specializations → add/edit/delete (delete with dependents shows the red guard message); Academic Terms → activate year, open Term 1, edit; Reports; Activity Logs.

**Adviser** (`adviser@naggasican.edu.ph`, section Acacia): dashboard; My Students → edit one; Assessments → upload `tests/Fixtures/03_assessment_gen_math_q1.csv` for General Mathematics on the open term → Verify (max scores prefilled) → Preview → Import; add a manual item; Encode/verify grades (single and Verify All); Submit Report (watch "Analyzing learner performance…"; success = risk results appear on Principal side); Interventions → acknowledge/deliver after the Principal decides; type `/adviser/assessments/item/1` edit for another section's item → 403 page; `/admin/users` → 403 page.

**Principal** (`principal1@naggasican.edu.ph`): dashboard (risk cards, charts); filters (grade level/section/risk/component); Students list → drill-down; Subject Analysis; Reports; Interventions → record one, approve pending, bulk record; historical year selector (`?school_year=2027-2028` shows empty, not mixed); `/admin/users` → 403 page.

**Cross-cutting**: desktop 1366+, projector (1024×768 — check Principal dashboard charts and the at-risk table), tablet 768, phone 390 and 360 (login, each dashboard, Students tables scroll horizontally, modals fit, toast not clipped); Chrome DevTools "Slow 3G" on Submit Report and an import (button disables and relabels, no double post); leave a page idle >120 min then submit (branded 419); double-click every primary button once; upload a `.docx` (rejected), a 15 MB `.xlsx` to ECR import (rejected by `max:10240`); rename the Python binary via `PYTHON_PATH=nope` and submit a report (warning, submission still recorded).

## Demo Checklist

1. `php artisan optimize:clear`; confirm `APP_DEBUG=false`, `APP_ENV=local` or `production`.
2. If running `npm run dev`, keep it running; otherwise delete `public/hot` and rely on `public/build` (`npm run build` already done).
3. Data preparation (Admin): activate `2026-2027` (already active); **open Term 1**; assign advisers to Mahogany and Agila or leave them as intentional Data Health examples; ensure Acacia's adviser account is active.
4. Adviser: upload one assessment form per subject for Term 1, verify grades, submit Term 1 so the Principal dashboard has risk results and the trend chart has a point.
5. Principal: record at least one intervention so the Adviser's "awaiting decision" and delivery flows have data.
6. Verify `python -c "import sklearn, joblib, numpy"` on the demo machine and `PYTHON_PATH` in `.env`.
7. Rotate `storage/logs/laravel.log` and delete `storage/logs/audit-*.log` if the machine will be shared.
8. Keep `backups/` and `storage/app/private/` off any USB/ZIP handed out.
9. Have the "Remaining Limitations" list above ready for the panel.

## Files Changed

Modified: `CLAUDE.md`, `app/Http/Controllers/Admin/StudentController.php`, `app/Http/Controllers/Adviser/AssessmentController.php`, `app/Http/Controllers/Adviser/ReportController.php`, `app/Services/AssessmentUploadService.php`, `database/seeders/ExamRoleSharesSeeder.php`, `package-lock.json`, `public/favicon.ico`, `public/robots.txt`, `resources/js/auth.js`, `resources/js/modal.js`, `resources/views/admin/{academic-terms,dashboard,sections,specializations,students,subjects,tracks,users}.blade.php`, `resources/views/adviser/{assessments-verify,grades,students}.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/layouts/{app,guest}.blade.php`, `resources/views/principal/students.blade.php`, `tests/TestCase.php`.

Added: `app/Services/TempUploadPruner.php`, `public/images/{favicon-32,favicon-192,apple-touch-icon}.png`, `resources/views/components/app-favicon.blade.php`, `resources/views/errors/{layout,403,404,419,500,503}.blade.php`, 7 test files listed above, `PRE_DEMO_FINAL_AUDIT.md`.

Deleted: `app/View/Components/AppLayout.php`, `resources/views/components/{application-logo,danger-button,dropdown,dropdown-link,modal,nav-link,responsive-nav-link,secondary-button}.blade.php`.

Not committed — per instruction, nothing was committed or pushed. `git diff --check` is clean (the CRLF notices are the repository's pre-existing line-ending state).

Also removed from disk (not tracked): 819 stale temp upload files older than 24 h (760 assessment, 59 ECR-learner) via the same `TempUploadPruner::prune()` the application now calls; 11 files younger than 24 h were left for the next upload to prune.

## Final Recommendation

Demo the system as **READY WITH DOCUMENTED LIMITATIONS**. Complete the Manual UAT rows for phone/tablet/projector widths and the slow-network double-submit checks before the presentation — those are the only items this pass could not verify mechanically. State limitations 1–3 plainly if asked; each has a written answer in CLAUDE.md and here.

## Final validation run

Executed after the last code change, in this order:

| Command | Result |
|---|---|
| `php artisan optimize:clear` | OK |
| `php artisan migrate:status` | 65 ran, 0 pending |
| `php artisan route:list` | 101 routes; every `route('…')` name used in views/controllers/JS resolves (cross-checked programmatically) |
| `php artisan test` | **1,021 passed, 0 failed, 0 skipped** (3,569 assertions, 315.2 s) |
| `npm run build` | OK (131.8 kB CSS, 80.1 kB JS) |
| `npm audit` | 1 low (esbuild dev server, GHSA-g7r4-m6w7-qqqr — devDependency only) |
| `composer validate` | valid |
| `composer check-platform-reqs` | all satisfied |
| `php artisan dss:check-integrity` | "No orphaned or inconsistent data found." |
| `python analytics/test_classify.py` | 3 OK |
| `python analytics/test_training_pipeline.py` | 16 OK |
| `php -l` on every changed PHP file | clean |
| Source scan `dd(` `dump(` `var_dump(` `print_r(` `console.log(` `{!!` | 0 / 0 / 0 / 0 / 0 / 0 |
| `git diff --check` | clean (CRLF notices are the repository's pre-existing state) |
| `git diff --stat` | 28 files changed, 365 insertions(+), 173 deletions(-), plus 22 new/deleted untracked paths |
| Real HTTP on the running `:8000` server | `/login` 200 with the new attributes; `/nope` 404 branded; `/favicon.ico` 200 `image/vnd.microsoft.icon` 1,787 B; three PNG icons 200; `/robots.txt` `Disallow: /`; `/admin/dashboard` 302 → login as guest |

One test changed its expectation during this pass — `AdminDataHealthChecksTest::…stored_group_agrees_is_not_flagged` — because the Data Health panel text it asserts now goes through `{{ }}` instead of `{!! !!}`, so the apostrophe is HTML-escaped. Same wording, safer encoding; the comment in the test says so. No test was deleted, skipped, or weakened.
