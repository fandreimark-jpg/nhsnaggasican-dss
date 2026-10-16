# Grading correctness follow-up report

Date: 2026-10-06. Branch: `rule-based-academic-monitoring`. Nothing was committed, pushed or deployed. No database was deleted and no official grade was changed.

This follows `WORKBOOK_PARITY_REPORT_2026-10-04.md`. An interrupted session on the evening of 2026-10-04 had already started some of this work, and none of it had been verified. This report covers that work too. Everything below was re-inspected and tested in this session.

**Basis.** The client workbook is the computational reference for the project. That is a project implementation decision, **not a recorded school approval**.

## Statuses

| | Status | In one line |
|---|---|---|
| **A. Feature behaviour** | **COMPLETE: verified by tests. One deployment step is pending.** | All five items are implemented and covered by tests. The band data migration has **not** been run on the working database. |
| **B. Workbook numerical parity** | **PARTIAL: one confirmed discrepancy class, decision needed.** | Matches Excel for all 10,001 direct Initial Grades and the evaluator for 9 profiles × 30 cases. **Differs** when Excel's binary `SUM` lands just above a band maximum (74.71 → Excel 79, system 78). "Other elective" is unsupported (no weights exist). |
| **C. Existing-data acceptance** | **NOT ACCEPTED: school decision needed.** | The five verified grades still read Re-verify. Re-verifying would make four of them failing. Nothing was rewritten. |

---

## 1. Transmutation boundaries

### Why the 2026-10-04 report claimed parity while 69.99 and 71.17 differed

That report checked the system's band table against `HELPER!B7:D47` **rounded to 2 dp**, which is what the sheet prints. The workbook never reads the printed value. `Term 1!AM17` is:

`=IFERROR(XLOOKUP(AL17,HELPER!$C$7:$C$47,HELPER!$D$7:$D$47,,1),"")`

This is match mode 1, "exact or next larger", over the **stored** maxima in column C. The minima in column B are not used. Two of the stored maxima sit just below their printed value (raw XML of `xl/worksheets/sheet7.xml`):

| Cell | Stored | Printed | Band |
|---|---|---|---|
| HELPER!C33 | `69.9899999999999` | 69.99 | → 74 |
| HELPER!C32 | `71.1699999999999` | 71.17 | → 75 |
| HELPER!B32 / B31 | `69.9999999999999` / `71.1799999999999` | 70.00 / 71.18 | (not read) |

So 69.99 is greater than C33. XLOOKUP moves to C32 and gives **75**. Likewise 71.17 is greater than C32 and gives **76**. A test that rounds C33 to 69.99 cannot see this, so "band parity" held for the printed table but not for the lookup.

### Fix: deterministic, aligned with the workbook formula

- `database/seeders/Do015TransmutationSeeder.php` now holds the bands the formula actually produces:
  - 74: 65.34–**69.98**
  - 75: **69.99**–**71.16**
  - 76: **71.17**–72.35
  - The other 38 bands are unchanged.
- `database/migrations/2026_10_04_000001_align_do015_transmutation_edges_with_workbook_lookup.php` changes those three rows only.
  - It is all-or-nothing and idempotent, and `down()` restores the printed values.
  - It is tested by `Do015BandsMatchOfficialEcrTest::test_the_edge_migration_moves_only_the_three_printed_rows_and_reverses`.
- **Working database: the migration is still `Pending`.** Until it runs, the live app still gives 69.99 → 74.
  - To apply it: `php artisan migrate`.
  - A dump was taken first: `C:\newxampp\db_backups\naggasican_dss-before-grading-followup-20261006-075034.sql`.
  - It touches only `transmutation_ranges`; no grade is changed.
- **Pass/fail consequence:** an Initial Grade of exactly 69.99 now passes (75). The printed DO 015 table says 74. The rule follows the instruction to follow the formula, not the printed bands. The school should confirm it (§7).

### Boundary comparisons

**Direct numeric inputs.** "Excel" means Microsoft Excel 16.0 build 20430, run on the client workbook's own HELPER arrays.

| Initial | Printed table | Evaluator (PhpSpreadsheet) | **Excel** | System (now) |
|---|---|---|---|---|
| 69.98 | 74 | 74 | **74** | 74 |
| 69.99 | 74 | 75 | **75** | 75 |
| 70.00 | 75 | 75 | **75** | 75 |
| 71.16 | 75 | 75 | **75** | 75 |
| 71.17 | 75 | 76 | **76** | 76 |
| 71.18 | 76 | 76 | **76** | 76 |
| 74.70 | 78 | 78 | **78** | 78 |
| 74.71 | 78 | 78 | **78** | 78 |
| 74.72 | 79 | 79 | **79** | 79 |

All **10,001** values from 0.00 to 100.00 equal Excel. See `docs/excel-validation/results-2026-10-06/direct-lookup-0-100.csv`, read by `WorkbookParityTest::test_every_two_decimal_initial_grade_matches_excels_own_xlookup`.

**Initial Grades from component sums.** Core profile 20/50/30, shares 30/30/40, every item HPS 100. The inputs are WW, PT, ST1, ST2 and TE scores. P17, AC17 and AK17 are the workbook's Weighted Scores (WS).

| Case | Inputs | P17 / AC17 / AK17 | **Excel AL17** | Excel AM17 | System |
|---|---|---|---|---|---|
| 69.98 exact | 0, 82, 90, 100, 99 | 0 / 41 / 28.98 | 69.98 | 74 | 69.98 → 74 |
| 69.98 noisy | 2, 80, 98, 100, 98 | 0.4 / 40 / 29.58 | 69.97999999999999 | 74 | 74 |
| 69.99 exact | 0, 81, 97, 100, 98 | 0 / 40.5 / 29.49 | 69.99 | 75 | 75 |
| 69.99 noisy | 1, 80, 99, 100, 99 | 0.2 / 40 / 29.79 | 69.990000000000009 | 75 | 75 |
| 70.00 | 0, 80, 100, 100, 100 | 0 / 40 / 30 | 70 | 75 | 75 |
| 71.16 exact | 0, 84, 92, 100, 99 | 0 / 42 / 29.16 | 71.16 | 75 | 75 |
| 71.16 noisy | 77, 100, 0, 0, 48 | 15.4 / 50 / 5.76 | 71.160000000000011 | 75 | 75 |
| 71.17 exact | 0, 83, 99, 100, 98 | 0 / 41.5 / 29.67 | 71.17 | 76 | 76 |
| 71.17 noisy | 73, 100, 0, 1, 54 | 14.6 / 50 / 6.57 | 71.169999999999987 | 76 | 76 |
| 71.18 exact | 0, 85, 88, 100, 98 | 0 / 42.5 / 28.68 | 71.18 | 76 | 76 |
| 71.18 noisy | 4, 81, 100, 100, 99 | 0.8 / 40.5 / 29.88 | 71.179999999999993 | 76 | 76 |
| 74.70 exact | 0, 90, 98, 100, 99 | 0 / 45 / 29.7 | 74.7 | 78 | 78 |
| 74.70 noisy | 4, 89, 96, 100, 98 | 0.8 / 44.5 / 29.4 | 74.699999999999989 | 78 | 78 |
| 74.71 exact | 2, 90, 95, 100, 98 | 0.4 / 45 / 29.31 | 74.71 | 78 | 78 |
| **74.71 noisy** | 0, 92, 87, 100, 99 | 0 / 46 / 28.71 | **74.710000000000008** | **79** | **78** |
| 74.72 | 0, 91, 94, 100, 98 | 0 / 45.5 / 29.22 | 74.72 | 79 | 79 |

Every Weighted Score matches Excel. See `row17-component-sums.csv`, read by `test_component_sums_match_excel_except_the_confirmed_binary_sum_case`.

### The floating-point case: investigated, confirmed in Excel, not changed

- `AL17 = SUM(P17,AC17,AK17)` is computed in binary.
- When the double lands just **above** an exactly-stored band maximum, Excel's XLOOKUP compares exactly and moves one band up.
- Excel's own `=` operator calls the two values equal (`TRUE`), so this can only be seen through the lookup.

**Not reproduced by the system.** The system adds the WS in exact hundredths, consistent with the workbook's ROUND-to-2-dp rules, with no epsilon. Copying the artifact would mean emulating binary addition order. Two learners with the same displayed 74.71 would then get different Term Grades.

Scope of the difference:
- It affects only Initial Grades exactly on one of the 38 exactly-stored maxima, by +1 Term Grade.
- It **can never change pass/fail**: the 74/75 maximum is the `69.9899999999999` artifact, about 1e-13 below 69.99, which is wider than any sum noise.
- In a seeded sample of 3-WS compositions it occurred in about 11% of compositions on such a maximum (60% at 74.71).
- The profile sweep (§6) met none in 270 random cases.

### Evaluator support for the lookup functions

- PhpSpreadsheet 1.30.7 does **not** implement XLOOKUP (`Functions::DUMMY`). This is asserted in a test.
- The oracle therefore reproduces match mode 1. That copy is cross-checked against PhpSpreadsheet's **native** `INDEX(D, MATCH(g, C, -1))`, which is equivalent because C7:C47 is strictly descending. The check covers every band edge ±0.01, the nine boundary values and the noisy sum (`test_the_evaluators_native_match_agrees_with_the_xlookup_transcription`).
- **Results from the evaluator and from Excel are kept separate.**
  - The CSVs are Excel's own output.
  - The oracle and profile-sweep results come from the evaluator.
  - The two agreed on all 16 Excel row cases.
- This PC's `Excel.Application` COM registration is WPS Office. Its results (78 for the noisy case) were discarded. The real `EXCEL.EXE` was started explicitly; see `docs/excel-validation/README.md`.
- Running it again: `powershell -File docs\excel-validation\run-in-excel.ps1`.
  - Two runs were byte-identical.
  - Only `<sheetProtection>` of Term 1 is removed, on a TEMP copy, which is never saved.

## 2. Additional-support applicability

**The established rule, from the sources:**
- `HANDOFF.md`: "within-term additional support is uncapped and **additive**: an item adds its points to both the earned and possible score".
- `docs/claude-archive/04`: "the system implements (1) Additive".
- Hard Constraint 2: the flag is display-only, meaning it never changes how a **scored** item counts.

Support items are therefore **not** display-only items to be dropped for everyone. They apply to the learners given them. The 2026-10-04 parity change had counted their HPS for **every** learner, which broke this.

| Learner | Earned | HPS denominator |
|---|---|---|
| Given the item (numeric score) | + points | + HPS |
| Not given (blank / never scored) | absent | absent |

- Engine: `GradingEngine::workbookComponent()` skips a support item without a score. In `workbookExamination()`, a support item of a type is added to that type's cell only when scored.
- **New fix: Save no longer marks non-recipients Missing.**
  - Before, saving one recipient's support score sent the column's other blanks, and each became a Missing marker, which opened a Missing concern for every other learner.
  - Now a support blank is "not given" on the server (`ClassRecordService::saveBatch`). Clearing a recipient's score returns the learner to "not given".
  - The grid marks the cells with `data-support="1"`. `planSave()` never sends or announces those blanks, and the "Save this assessment's blanks as Missing" control is disabled on them.
- Same rule in preview, save, verification, reports and export. Tested in `AdditionalSupportApplicabilityTest` (5 tests):
  - Adding WW and ST1 support items for one learner leaves the other learner's grade, Class Record display, in-term status, Verify Grades state and export row **identical**.
  - The recipient's figures move additively (WW 50.00 → 62.50; WS ST1 15.00 → 13.50).
  - Preview leaves the non-recipient unchanged and writes nothing.
  - Save leaves 0 Missing markers and 0 concerns. A regular item's blank is still Missing.
  - The grid renders the support marker.
- Mutation check: with the server fix reverted, the save test fails.
- Working data has one support item: #282 "Remedial Quiz 1", section 3 / subject 11, unscored. Under the 2026-10-04 rule its HPS of 20 lowered every learner's WW there. Now it counts for no one. It does not affect the five grades.

## 3. Duplicate examination types

**Supported model:** one REGULAR item per Examination Type per class/term. The workbook has one cell each in AD/AE/AF. No aggregation rule for several regular items of one type is documented anywhere, so none was invented.

- **Creation and edit** are validated in `AssessmentFormController::examRoleProblem()`, on both Add and Edit (under the row lock). Already tested (`ExaminationTypeTest` 7-8).
- **Existing or raced duplicates** block the figure instead of letting the first item win. `ExaminationRoles::workbookColumns()` lists them, and `GradingEngine::workbookExamination()` then:
  - computes no Examination figure, no computed grade and no Term Grade;
  - returns `method = blocked` with a correction message naming both items: "…Edit the extra item to its correct Examination Type (Edit Assessment)".
- **Visible on:**
  - the Class Record warning;
  - Verify Grades ("Not eligible — …");
  - the direct verify POST. It returns 422 with the blocker and no longer says "a component has no scores" (`GradeController::verifyOneGrade`).
- **Detected without deleting:** `php artisan dss:check-integrity` now lists each class with duplicate regular types. Support items sharing a type are not duplicates.
- Working data: **0** duplicates and 0 untyped exam items. Checked by SQL and by the integrity command on the MySQL impact copy.
- Tests:
  - `ExamRoleWeightingTest::test_a_second_regular_item_of_one_type_blocks_the_examination_figure`, extended to cover the board, the direct POST, no official grade and both items kept;
  - `DataIntegrityCommandsTest::test_check_integrity_reports_duplicate_examination_types_without_deleting_them`.
- Residual: Add Assessment checks for a holder outside the insert transaction, and there is no DB unique constraint. Two simultaneous Adds could still create a duplicate. That duplicate is then **blocked and reported**, never silently miscounted.

## 4. Other elective profiles

**What the workbook does:**
- `HELPER!W93:AC93` (Academic) and `W147:AC147` (Tech-Pro) take this subject's WW/PT/EX weights and ST1/ST2/TE shares from `INPUT DATA!F43:F48`, typed per workbook.
- Those cells are **empty** in the template. `G43 = SUM(F43:F45)` and `G46 = SUM(F46:F48)` only display totals.
- The workbook contains no values for these profiles, and the system has no source for them.

**Decision: unsupported until the school supplies the figures and says who sets them** (§7). The existing `configuration_error` path was verified and closed in these places:
- `resolveWeightProfile()` returns `source = unconfigured` with null weights. It never falls back to `subject_group`; the test gives the subject `core_academic` to prove this.
- `computeGrade()` returns no figure, no running grade and no Term Grade, with the message as a blocker.
- The Class Record shows "Grades cannot be computed for this subject." Verify Grades shows the message. The direct verify POST returns 422 and **no official grade**.
- The Admin Subjects and Section Subjects pages and the export all load.
- **Fixed:** Add Assessment treated the unknown weights as "no Examination component" and refused exam items. Evidence can now be recorded with the three types (`ExaminationRoles::forContext`). Only the grade is withheld.
- `dss:check-integrity` lists subjects linked to the row. Working data: **0**.
- Tests: `OtherElectiveProfileTest` (3).

## 5. Existing official-grade history and the five grades

| Grade | Official | Stored computed | **Now** computed → Term | WW / PT / EX WS | Verify Grades | Reports |
|---|---|---|---|---|---|---|
| #505 | 80.00 | 77.00 | 65.83 → 74 | 17.33 / 44.00 / 4.50 | Re-verify (`verified_changed`) | evidence changed — re-verify |
| #506 | 78.00 | 74.50 | 60.13 → 72 | 9.33 / 44.50 / 6.30 | Re-verify | evidence changed — re-verify |
| #507 | 74.00 | 67.00 | 63.11 → 73 | 15.11 / 45.00 / 3.00 | Re-verify | evidence changed — re-verify |
| #508 | 75.00 | 70.50 | 59.01 → 72 | 11.11 / 42.50 / 5.40 | Re-verify | evidence changed — re-verify |
| #509 | 85.00 | 82.50 | 57.22 → 72 | 6.22 / 43.50 / 7.50 | Re-verify | evidence changed — re-verify |

All five are Effective Communication, section 2, Term 1, 2026-2027.

**Change from the 2026-10-04 figures: none.**
- The figures were computed on `naggasican_dss_impact` before and after applying the band migration there. Both runs give the same result.
- None of the five sits on 69.99, 71.17 or a binary-sum edge.
- Their class has no support item and no duplicate exam.

**Preservation:**
- The impact copy matched the working database by `CHECKSUM TABLE` on grades, assessments, scores, markers, bands, catalog, subjects, sections and shares.
- Grades, evidence and `activity_logs` checksums were identical before and after the computation.
- The working bands are still the printed ones.
- Kept for now: the backup `naggasican_dss-before-workbook-parity-20261004-204642.sql`, the new dump `naggasican_dss-before-grading-followup-20261006-075034.sql`, and the database `naggasican_dss_impact` (which now has the band migration applied).

**Stale results are now visible everywhere they appear as official** (new). Before this change, only the Class Record and Verify Grades flagged them. Reports showed an official 80 with no hint. Now one rule, `ClassRecordService::evidenceChanged()`, drives:
- the Class Record;
- the learner results: Advisory Overview and learner report ("Official — Passed · evidence changed — re-verify");
- every `SubjectResultSummary` report: Admin Reports, Submit Report, Advisory Overview and Principal student detail ("Evidence changed since verification — re-verify: <subjects>").

The official value stays the record. It is never rewritten, and failing concerns keep reading the official grade. Test: `ClassRecordFollowUpChecksTest::test_reports_and_learner_results_flag_a_stale_official_grade_without_rewriting_it`.

**Authorized re-verification:**
- The individual and bulk verify endpoints recompute with the current engine (`verifyOneGrade()` → `computeGrade()`), so they use the corrected calculation.
- They refuse closed terms and past years (`AcademicTerm::acceptsWrites`).
- Term 1 2026-2027 is open, so the adviser *could* re-verify. That was **not** done.
- Re-verifying would make #505–#509 into 74 / 72 / 73 / 72 / 72: four currently passing grades would become failing (#507 is already failing at 74).

## 6. Tests actually run (2026-10-06, after the last code change)

- PHP: `php artisan test tests/Unit` gave **101 passed**. The Feature suite ran in four chunks (`[S-Z]` re-run after the profile sweep was added: 277):

  | Chunk | Passed |
  |---|---|
  | `[A-D]` | 436 |
  | `[E-L]` | 232 |
  | `[M-R]` | 241 |
  | `[S-Z]` | 276 |

  **Total: 101 + 436 + 232 + 241 + 277 = 1,287 PHP tests passed.**
- An intermediate full run had **17 failures**, report pages returning 500. `SubjectResultSummary` called the engine, which throws for an unclassified subject. The fix: that case is not compared, and the page loads. All 17 then passed, followed by a clean full re-run.
- JS: `node --test tests/js/*.mjs` gave **19/19** (one new: support blanks are never sent).
- Profile sweep (evaluator): 9 distinct catalog profiles × 30 seeded cases gave **270 cases, every cell (O/P/AB/AC/AG–AK/AL) and Term Grade equal**, with 0 binary-sum differences.
- Excel (Microsoft 16.0.20430): 10,001 direct lookups and 16 row-17 compositions, with one confirmed discrepancy (§1).
- Browser, isolated only:
  - SQLite `run.mjs`: **165/165** on three fresh prepares. One earlier run reported 164/165, but the failing line was cut off in the captured output. It did not reproduce in three reruns and is recorded as unexplained.
  - SQLite `concern-flow.mjs`: **30/30**, twice.
  - Isolated MySQL (`BROWSER_ISOLATION=mysql`, `naggasican_dss_loadtest`): `run.mjs` **165/165**, `concern-flow.mjs` **30/30**.
  - Assets were rebuilt (`npm run build`).
  - Not re-run: `verify-race.mjs` and `loadtest.mjs`. Verification locking was not changed.
- MySQL: `dss:check-integrity` on `naggasican_dss_impact` reported no findings, with the new queries valid on MySQL.

## 7. Remaining unresolved questions

1. **Binary-sum Term Grade (the single open parity question).** Confirmed in Excel: `SUM(0,46,28.71)` = 74.710000000000008 → 79, where the system gives 78. Should the system copy Excel's binary result, which depends on the composition of the WS, or keep the exact decimal Initial Grade? It never changes pass/fail. Nothing was changed.
2. **69.99 → 75 / 71.17 → 76.** These are implemented per the workbook formula, against the printed DO 015 table, and make 69.99 passing. The school should confirm it. Then run `php artisan migrate` on the working database.
3. **The five verified grades.** Re-verify or keep them? Four would become failing.
4. **Other elective / special curricular program.** What are the weights and shares, and who records them (the workbook lets the teacher type them)?
5. Still open from before, unchanged: replacement or capped counting of support items for their recipients, and D-01 / the 74–75 inconsistency.

## Files changed in this session

- **App:**
  - `app/Services/ClassRecordService.php`: support blanks; `evidenceChanged()`.
  - `app/Services/LearnerResultsService.php`.
  - `app/Services/SubjectResultSummary.php`.
  - `app/Services/ExaminationRoles.php`: `forContext` for unconfigured profiles.
  - `app/Http/Controllers/Adviser/GradeController.php`: blocker message.
  - `app/Console/Commands/CheckIntegrityCommand.php`.
- **Views:** `teacher/partials/class-record-cell`, `admin/reports`, `adviser/advisory-overview`, `adviser/submit-report`, `principal/student-detail`.
- **JS:** `resources/js/class-record.js`, `class-record-core.js`.
- **Tests:**
  - New: `AdditionalSupportApplicabilityTest`, `OtherElectiveProfileTest`.
  - Extended: `WorkbookParityTest` (native MATCH check, two Excel-verified tests, profile sweep), `Support/WorkbookOracle` (`termGradeByMatch`), `Do015BandsMatchOfficialEcrTest`, `ExamRoleWeightingTest`, `DataIntegrityCommandsTest`, `ClassRecordFollowUpChecksTest`, `tests/js/class-record.test.mjs`.
  - Band comments only in four tests.
- **Docs:** `docs/excel-validation/` (README, `run-in-excel.ps1`, `results-2026-10-06/`), `CLAUDE.md`, this report.
- **From the interrupted session, now verified:** `GradingEngine` (support applicability, duplicate blocker, unconfigured profile), `ExaminationRoles::workbookColumns()`, `Do015TransmutationSeeder`, the edge migration, `Do015BandsMatchOfficialEcrTest`, `build-xlookup-check.php` and its workbook.
