# Workbook parity (D1–D3), running grade and Class Record export — report

Date: 2026-10-04. Branch: `rule-based-academic-monitoring` (uncommitted; nothing committed, pushed or deployed).

**Basis.** The work order selected the client workbook as the computational
reference for D1 (WW/PT denominator), D2 (examination denominator), D3
(examination rounding order), subject weights and transmutation. This is a
**project implementation requirement, not a recorded school approval.**

**Status: implemented and verified against the workbook's own formulas, with
the open items in §10.** Not production-ready. No school approval is claimed.

---

## 1. What changed (files)

**Application**

- `app/Services/GradingEngine.php`
  - D1: `workbookComponent()`. D2/D3: `workbookExamination()`.
  - `computeGrade()` now returns `component_detail` and `workbook_complete`. `complete` (verification) is kept separate.
  - The additional-support flag is display only.
- `app/Services/ExaminationRoles.php`: `workbookColumns()`, the one ST 1 / ST 2 / TE column mapping. The engine and the grid both use it.
- `app/Services/ClassRecordService.php`
  - TOTAL/PS/WS now come from the engine.
  - The grid uses the shared exam mapping.
  - Cell explanations rewritten.
  - An official grade that no longer matches the evidence is noted "evidence changed — re-verify".
- `app/Services/GradeVerificationBoard.php`
  - New state `verified_stale`: a verified grade whose evidence is no longer complete. It is never shown as current.
  - Clearer reason when an exam type that was given is unscored.
- `app/Services/ClassRecordExport.php`: rewritten as a client-style **system** export (§7).
- `app/Services/DashboardAnalyticsService.php`, `app/Services/SubjectAnalysisService.php`: their own component percentages now use the workbook denominator, so they do not drift from the engine.
- `app/Services/ProgressMonitoringService.php`: comment only. Its progress comparison is over scored items and is not the grade.
- `resources/views/teacher/class-record.blade.php`: help text and the duplicate/untyped Examination warnings now name which item is counted.
- `resources/js/class-record.js`, `resources/js/class-record-drafts.js`: the assessment-scoped "Save this assessment's blanks as Missing" choice is kept in the local draft (§6).
- `CLAUDE.md`: grading rules, baseline, and the new unresolved items.

**Tests**

- New: `tests/Support/WorkbookOracle.php` (independent oracle, §2).
- New: `tests/Feature/WorkbookParityTest.php` (11 tests, 739 assertions).
- New: `tests/Concerns/SharesClassAssessments.php` (fixture helper, see below).
- Extended: `tests/Feature/ClassRecordFollowUpChecksTest.php`, with D1–D3 rewritten plus stale-verification and export tests.
- Extended: `tests/js/class-record-drafts.test.mjs`, `tests/Browser/concern-flow.mjs`.
- Updated: `tests/Browser/prepare.php` (an isolated-only `narra@browser.test` adviser for `verify-race.mjs`).

**Tests whose expectations changed with a workbook basis.** Each change is commented in the test.

- AcademicConcern, AcademicMonitoringTrigger, AssessmentLearnerStatus #4/#5: 90% → 45% (Term 1!O17).
- ClassRecordAutoMissing #17: Missing 75% → 50%.
- ExamRoleWeighting:
  - No TE item: 85 → 51 (AJ17).
  - TE given but unscored: 51 running, not verifiable.
  - A second ST1 is not counted.
- ClassRecordUi dash reasons.
- IndependentGradingFixtures: blank TE gives running 88.00 (the workbook's AL17).
- PerformanceBatchEquivalence: its oracle now uses every HPS.
- PrincipalConcernReview readiness: a bigger Quiz 2 keeps the subject On Track.
- SubjectTeacherClassRecord: export layout.

**Fixtures that assumed private per-learner items in a shared class.** In AdditionalSupportFlag, the two In-Term status/dashboard tests, InterventionReadyForReview and VerifyAllLogs, each learner had its own item inside one class. Under D1 every item in a class applies to every learner, so those fixtures now share one column per class (`SharesClassAssessments`) or use separate sections. No assertion was weakened, deleted or skipped.

## 2. Workbook cells and formulas used

Source file: `tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx`. Its formulas and values match `Downloads/SSHS-E-Class-Record-SY-2026-2027.xlsx` cell for cell across all 7 sheets (0 content differences; only the file packaging differs). Term 2 and Term 3 have the same row formulas as Term 1; only the term label and sheet guard differ.

| Cell | Formula |
|---|---|
| N14 | `=SUM(D14:M14)` — every HPS of slots 1–10 |
| N17 | `=IF(COUNT(D17:M17)=0,"",SUM(D17:M17))` |
| O17 | `=IFERROR(IF(N17="","",ROUND(N17/N$14*O$14,2)),"")` |
| P17 | `=IFERROR(IF(O17="","",ROUND(O17*P$14,2)),"")` — P14 = D12 = HELPER!G13/100 |
| AA17 / AB17 / AC17 | the same for PT |
| AG17 (AH, AI) | `=IFERROR(IF(AD17="","",ROUND(AD17/$AD$14*$AG$14,2)),"")` — AG14:AI14 = HELPER!G17:G19 (catalog AA:AC) |
| AJ17 | `=IF($AD$12=0,"",IF(OR(AG17<>"",AH17<>"",AI17<>""),(SUM(AG17:AI17)/SUM($AG$14:$AI$14))*$AJ$14,""))` — not rounded |
| AK17 | `=IFERROR(IF(AJ17="","",ROUND(AJ17*$AK$14,2)),"")` |
| AL17 | `=IF(AND(C17<>"",P17<>"",AC17<>"",$AD$12=0),SUM(P17,AC17),IF(AND(…,AK17<>"",$D$12>0,$Q$12>0,$AD$12>0),SUM(P17,AC17,AK17),""))` |
| AM17 | `=IFERROR(XLOOKUP(AL17,HELPER!$C$7:$C$47,HELPER!$D$7:$D$47,,1),"")` — exact or next larger |

**The oracle is independent of the code under test.** `WorkbookOracle` writes HPS and scores into the real `Term 1` sheet and evaluates N17…AL17 with PhpSpreadsheet's calculation engine. AM17 is computed by reproducing XLOOKUP match mode 1 over HELPER!C7:D47, read from the same file, because PhpSpreadsheet 1.30.7 does not implement XLOOKUP. No `App\Services` code is involved.

`WorkbookParityTest::test_helper_catalog_weights_and_shares_equal_the_seeded_catalog` checks all 139 fixed HELPER catalog rows (W/X/Y weights, AA/AB/AC shares) against `deped_subject_catalog`. The 2 teacher-supplied rows are covered in §10.

## 3. Before → after (numbers from the workbook formulas)

Weights are WW 20 / PT 50 / EX 30; shares are ST1 30 / ST2 30 / TE 40.

| Case | Before (old engine) | After = workbook |
|---|---|---|
| D1: Quiz 1 15/20, Quiz 2 Missing (HPS 10) | WW PS 75.00, WS 15.00 | **PS 50.00, WS 10.00** |
| D1: the same with Quiz 2 an unsaved blank | 75.00 / 15.00 | **50.00 / 10.00** |
| D1: Quiz 2 a recorded 0 | 50.00 / 10.00 | 50.00 / 10.00 |
| D1: every entry Missing | no PS | no PS (COUNT guard) |
| D2: only TE, 30/40 | EX PS 75.00, WS 22.50 | **WS TE 30.00, PS 30.00, WS 9.00** |
| D2: ST1 Missing, TE 30/40 | no EX figure | **PS 30.00** (running; not verifiable until ST1 is scored) |
| D2: ST1 80/100, ST2 90/100, no TE item | 85.00 | **51.00** |
| D3: ST1 0/7, ST2 1/9, TE 5/11 | PS 21.52, WS 6.46 | **WS ST 0.00 / 3.33 / 18.18, PS 21.51, WS 6.45** |

**Transmutation edges** (Initial Grade → Term Grade). The workbook result comes from the oracle; the system result from `TransmutationService`:

- Match: 4.67→60, 4.68→61, 69.98→74, 70.00→75, 71.18→76, 74.72→79, 79.43→82, 79.44→83, 99.49→99, 99.50→100.
- **69.99:** workbook 75, system 74. HELPER!C33 is stored as `69.9899999999999`. *Open, pass/fail* (§10).
- **71.17:** workbook 76, system 75. HELPER!C32 is stored as `71.1699999999999`. *Open* (§10).
- **74.71:** the workbook's unrounded AL17 is `0 + 46 + 28.71 = 74.71000000000001`, so a strict lookup gives 79. The system rounds to 74.71 and gives 78. *Excel itself was not available to confirm its own arithmetic* (§10).

The system's DO 015 band table equals HELPER!B7:D47 as printed, to 2 dp (tested).

## 4. Running grade vs official grade

- **Running Initial Grade:** the sum of the Weighted Scores present, with weights not rescaled. It is labelled "running · partial", and "· unsaved preview" while typing. It is display only.
- **When every WS exists:** the running grade equals the workbook's AL17.
- **`computed_grade`, Term Grade and verification** still need a numeric score in every Examination Type that was given. A workbook-computable number does not relax verification. Example: blank TE gives running 88.00 (the workbook shows 88.00 and Term Grade 90), but the system shows no Term Grade and the learner reads "incomplete" on Verify Grades.
- **Preview, save, Class Record, Verify Grades and export** all read the same `GradingEngine::computeGrade()`. Tests cover preview = save (`test_16`) and export = display (`test_the_export_carries_the_displayed_figures…`).
- **Concerns:** a Missing concern reads markers; a failing concern reads the official grade only. Tested: a verified 81 whose recomputed figure is 59 opens no failing concern.

## 5. Existing verified records

No official grade was rewritten and no migration was needed: grades already store `computed_grade`.

**Impact on the working data.** A read-only `mysqldump` of `naggasican_dss` was restored into the isolated database `naggasican_dss_impact` and evaluated there. The dump is at `C:\newxampp\db_backups\naggasican_dss-before-workbook-parity-20261004-204642.sql`.

| Grade | Official | Stored computed | Now computed → Term Grade | Verify Grades shows |
|---|---|---|---|---|
| #505 | 80.00 | 77.00 | 65.83 → 74 | Re-verify (`verified_changed`) |
| #506 | 78.00 | 74.50 | 60.13 → 72 | Re-verify |
| #507 | 74.00 | 67.00 | 63.11 → 73 | Re-verify |
| #508 | 75.00 | 70.50 | 59.01 → 72 | Re-verify |
| #509 | 85.00 | 82.50 | 57.22 → 72 | Re-verify |

All five are Effective Communication, Term 1, 2026-2027.

**Why the drop is large** (for example #509): Quiz 2 is Missing and its HPS of 25 now counts (WW 14/45). Only ST 1 was given: 25/30 contributes 25 of the 100 configured shares (AJ 25), where it was previously rescaled to 83.33.

Re-verifying would turn four currently passing official grades into failing ones (≤ 74). That decision belongs to the school and has not been taken. The isolated copy `naggasican_dss_impact` was left in place; drop it when no longer needed.

## 6. Automatic Missing and draft scope

**Unchanged behaviour**
- The blanks of the assessments in the save scope become Missing only after a confirmed save.
- A recorded 0 stays a score.
- Unused template slots, unsent cells and unrelated assessments are untouched.
- Saves are all-or-nothing, with 409 on stale cells, idempotent retry and audit history.

**Explicit all-blank assessment.** Select any cell and press **Save this assessment's blanks as Missing**. This is assessment-scoped; there is no per-learner marking.

**Draft persistence (new)**
- The chosen assessment ids are stored in the existing per-user, per-class key `cr-draft:{user}:{year}:{section}:{subject}:{term}`.
- **On reload:** the notice names the assessment and its *current* blank count. It is re-validated against what the server just rendered: the assessment must still be on the page, the page must still be editable (read-only pages delete drafts), and the cells must still be blank.
- **Concurrent score:** a cell scored elsewhere meanwhile is no longer blank and is never sent. Browser-tested: 8 → 7 blanks, and the other session's 6 is kept. The server's 409 still guards any race.
- **Clearing:** cleared by Discard (draft notice or save bar) and by a successful save.

## 7. Export

`Download Excel Class Record` is now laid out like the workbook's "Term N" sheet. It is labelled **SYSTEM-GENERATED … NOT the official DepEd E-Class Record workbook**.

**Present**
- A title and system-export notice.
- School Year, Grade Level, Section, Track, Term, Subject, Teacher.
- The grading profile with exam shares, and a legend.
- Band row and slot row: WW 1–10 (+11…), TOTAL, PS, WS; PT the same; ST 1 / ST 2 / TE; "Other" columns, each labelled "not counted" or "pooled".
- WS ST1 / ST2 / TE, PS, WS, INITIAL GRADE, TERM GRADE.
- An item-name row (an addition), and an HPS row with TOTAL HPS, 100, weight % and shares.
- MALE / FEMALE blocks, plus "GENDER NOT RECORDED" if any learner has none.
- A separate **DSS STATUS** block: Initial Grade status (provisional / running · partial), Official Grade (verified only), Official status, Evidence.
- Representation: a number (0 included) is a score; `MISSING` (shaded) is Missing; an empty cell is blank. Text cells are formula-safe.

**Absent or different compared with the workbook**
- No formulas, and no INPUT DATA, HELPER or FINAL GRADES sheets.
- No Region, Division, School ID or School Name: the system does not store them.
- An LRN column and an item-name row are added.
- The running grade can appear in INITIAL GRADE, with its status beside it (the workbook leaves AL17 blank until complete).
- The workbook's integer-only dropdown validation for scores is not reproduced.

## 8. Tests run (actual results)

- `php artisan test tests/Unit`: **101 passed**.
- `--testsuite Feature --filter 'Feature.[A-D]'` 427, `[E-L]` 232, `[M-R]` 238, `[S-Z]` 272: **1,270 PHP tests passed** (101 + 1,169), run after all changes.
- `node --test tests/js/*.mjs`: **18/18**.
- Intermediate run: 24 failures after the engine change, all triaged as above. The same files were re-run until green.

## 9. Browser and integration evidence (isolated only)

- **SQLite** (`storage/framework/browser-testing/browser.sqlite`): `run.mjs` 165/165; `concern-flow.mjs` 30/30.
- **Isolated MySQL** (`naggasican_dss_loadtest`, `BROWSER_ISOLATION=mysql`): `run.mjs` 165/165; `concern-flow.mjs` 30/30.
- **`verify-race.mjs`** (4 servers): 34 eligible learners. Both bulk requests returned 409 (the set changed) and both individual verifies returned 200. The database holds 2 grades and 2 verify logs, with no duplicates.
- **`loadtest.mjs 8 30`**: 148 requests, all HTTP 200, 43 saves, 0 errors. Afterwards there were 0 duplicate score rows and 0 cells holding both a score and a Missing marker. p95 latency was about 2–3 s on single-threaded `php -S` with OPcache off; this is not a production measure.
- **concern-flow covers**:
  - Blanks saved as Missing, then the Missing concern appears.
  - The scoped choice survives reload; Discard clears it; a concurrent score shrinks it; a successful save clears it.
  - Missing replaced by 12, then the page is reloaded.
  - The concern resolves with "was Missing · now Completed". History keeps the automatic Missing, the marker removal and the score.
  - Another open concern keeps the learner For Review (2); No Current Concern only after all are resolved.
  - No JavaScript exceptions.
- **run.mjs covers**:
  - Live preview before saving; a late preview response is ignored.
  - An offline save keeps the input.
  - Two-tab conflict; keyboard; 390/768/1366/1920 widths and 200% zoom.
  - Download, verification and roles.
- **Screenshots** are under `storage/framework/browser-testing/results*`. They contain synthetic data only.
- The working database was never a test target. All routers and scripts assert the isolated database.

## 10. Open items and unverified points

1. **Transmutation edges.** Exactly 69.99 → workbook 75 vs system 74 (pass/fail), and 71.17 → 76 vs 75, caused by stored band maxima. 74.71 → 79 vs 78 comes from binary noise in the workbook's unrounded sum; *not confirmed in Excel*. The system keeps the printed bands. School decision needed.
2. **Five verified working-data grades now read Re-verify** (§5). Re-verifying would make four of them failing. School decision needed; nothing has been rewritten.
3. **Additional-support items** now count their HPS for every learner (the flag is display only, per the existing hard constraint). Whether they should apply only to the learners they were given to is unresolved.
4. **Examination items outside the three workbook columns** are listed and not counted: a second item of a type, a support item sharing a type, or an untyped item when typed ones exist. Previously duplicates were summed. Working data has none.
5. **No shares configured** (DO 8 / `k12_2013`, outside this workbook): the previous equal split among the types given is kept.
6. **"Other elective / special curricular program"**: the workbook uses teacher-typed weights; the system never does.
7. **Not implemented:** FINAL GRADES (the final grade across terms) and the workbook's integer-only score dropdown.
8. **Excel was not available.** Parity rests on PhpSpreadsheet evaluating the workbook's formulas, plus a transcription of XLOOKUP.
9. **The progress comparison** (intervention before/after) remains over scored items. It is labelled as a comparison of recorded scores, not the grade.

## 11. Manual acceptance walkthrough

1. Sign in as the assigned teacher. Open Class Record for a class and add Quiz 1 (WW, HPS 20) and Quiz 2 (WW, HPS 10).
2. Type 15 for one learner in Quiz 1. TOTAL shows 15 / 30 and PS 50.00 as an unsaved preview.
3. Press Save Scores. Every other blank in Quiz 1 becomes M. Quiz 2 also has blanks: select one of its cells, press "Save this assessment's blanks as Missing", reload, restore the draft, then Save.
4. The learner's TOTAL stays 15 / 30, PS 50.00, WS 10.00 (Quiz 2 Missing, HPS counted).
5. Add a TE item (HPS 40) and score 30. Examination PS shows 30.00 and WS 9.00, not 75.00.
6. On Verify Grades, a learner with a given-but-unscored exam type reads "incomplete". A grade verified before this change reads "Re-verify".
7. Download the Excel. It shows the same TOTAL/PS/WS, WS ST, Initial Grade with its status, and Term Grade only for complete rows, and is labelled a system export.
