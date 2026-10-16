# Excel validation of the client workbook's Term Grade lookup

## Result (2026-10-06, Microsoft Excel 16.0, build 20430)

The question this folder was built for is **answered**: Excel's own `SUM` and
`XLOOKUP` were run on the client workbook's formulas.

- `Term 1!AL17 = SUM(P17, AC17, AK17)` is a binary double. `SUM(0, 46, 28.71)`
  is `74.710000000000008` in Excel (shown as 74.71).
- `Term 1!AM17 = XLOOKUP(AL17, HELPER!$C$7:$C$47, HELPER!$D$7:$D$47, , 1)`
  compares exactly. It sends `74.710000000000008` to **79**, past the 78-band
  maximum `HELPER!C29 = 74.71`. Excel's `=` operator calls the two values equal
  (`G5 = TRUE` in the check workbook), but XLOOKUP does not.
- The system adds the Weighted Scores in exact hundredths (74.71) and gives
  **78**. This is the one remaining discrepancy; see the follow-up report.

Everything else matched Excel:

- **Direct 2-decimal Initial Grades.** All 10,001 values from 0.00 to 100.00
  were looked up through the workbook's own HELPER arrays. All 10,001 equal the
  system, including 69.99 → 75 and 71.17 → 76 (`HELPER!C33 = 69.9899999999999`,
  `C32 = 71.1699999999999`).
- **Component sums at the band edges.** 16 compositions were tried
  (69.98–74.72, exact and noisy). Every Weighted Score and Term Grade matches,
  except `74.71 noisy`.

Results: `results-2026-10-06/direct-lookup-0-100.csv` and
`results-2026-10-06/row17-component-sums.csv`. Both are read by
`WorkbookParityTest::test_every_two_decimal_initial_grade_matches_excels_own_xlookup`
and `::test_component_sums_match_excel_except_the_confirmed_binary_sum_case`.

**Not Excel:** this PC's `Excel.Application` COM registration belongs to WPS
Office (`et.exe`). WPS gave 78 for the noisy case. Its result is not Excel's
and was discarded.

## Reproduce

```
powershell -NoProfile -File docs\excel-validation\run-in-excel.ps1
```

`run-in-excel.ps1` does the following:

1. Copies `tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx` to TEMP.
2. Removes only the `<sheetProtection>` element of `Term 1`. The sheet is
   password-protected, and formulas and data are untouched.
3. Starts the real `EXCEL.EXE /automation` and sets the class configuration the
   way `tests/Support/WorkbookOracle` does: core 20/50/30, shares 30/30/40.
4. Fills row 17, reads P17/AC17/AK17/AL17/AM17, and runs the 0.00–100.00
   lookup on a scratch sheet.
5. Writes the two CSVs into `results-<date>/`. The copy is never saved.

Two runs on 2026-10-06 produced byte-identical files.

## The smaller check workbook

`xlookup-sum-precision-check.xlsx` (built by `build-xlookup-check.php`) has
`HELPER!C7:D47` copied with its stored values and six SUM + XLOOKUP rows.
Excel 16.0.20430 gave:

| Row | Case | F (SUM) | G (F=E) | I (XLOOKUP) | J (noise × 1e15) |
|---|---|---|---|---|---|
| 5 | 0 + 46 + 28.71 | 74.710000000000008 | TRUE | **79** | 14.21 |
| 6 | 0.40 + 45 + 29.31 | 74.71 | TRUE | 78 | 0 |
| 7 | 0.20 + 40 + 29.79 | 69.990000000000009 | TRUE | 75 | 14.21 |
| 8 | 0 + 40.50 + 29.49 | 69.99 | TRUE | 75 | 0 |
| 9 | 14.60 + 50 + 6.57 | 71.169999999999987 | TRUE | 76 | −14.21 |
| 10 | 0 + 40 + 30 | 70 | TRUE | 75 | 0 |
