# Evaluates the client workbook's own Term 1 row-17 formulas and its HELPER
# lookup in MICROSOFT EXCEL (not WPS/LibreOffice) and writes the results as
# CSV next to this script. Run from Windows PowerShell:
#   powershell -File docs\excel-validation\run-in-excel.ps1 [-ExcelExe <path>]
# It works on a TEMP copy of tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx
# with ONLY the <sheetProtection> element of "Term 1" removed (the sheet is
# password-protected; formulas and data are untouched). The copy is never saved.
# The class configuration cells (D12/Q12/AD12, AG14:AI14, C17) are set as
# tests/Support/WorkbookOracle does: core 20/50/30, shares 30/30/40.
param([string]$ExcelExe = "C:\Program Files\Microsoft Office\root\Office16\EXCEL.EXE")
$ErrorActionPreference = 'Stop'
$repo = Resolve-Path (Join-Path $PSScriptRoot "..\..")
$scratch = Join-Path $PSScriptRoot ("results-" + (Get-Date -Format "yyyy-MM-dd"))
New-Item -ItemType Directory -Force $scratch | Out-Null
$copy = Join-Path $env:TEMP "sshs-ecr-excel-check.xlsx"
Copy-Item (Join-Path $repo "tests\Fixtures\SSHS-E-Class-Record-SY-2026-2027.xlsx") $copy -Force
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$zip = [IO.Compression.ZipFile]::Open($copy, 'Update')
try {
  $entry = $zip.GetEntry("xl/worksheets/sheet3.xml") # Term 1 (xl/workbook.xml rId3)
  $reader = New-Object IO.StreamReader($entry.Open()); $xml = $reader.ReadToEnd(); $reader.Close()
  $xml = [regex]::Replace($xml, '<sheetProtection[^>]*/>', '')
  $entry.Delete(); $entry = $zip.CreateEntry("xl/worksheets/sheet3.xml")
  $writer = New-Object IO.StreamWriter($entry.Open(), (New-Object Text.UTF8Encoding($false))); $writer.Write($xml); $writer.Close()
} finally { $zip.Dispose() }

# Start the real Excel: on machines where another suite owns the Excel COM
# registration (e.g. WPS), New-Object -ComObject would bind to that instead.
$p = Start-Process -FilePath $ExcelExe -ArgumentList "/automation","-Embedding" -PassThru
$x = $null
for ($i = 0; $i -lt 40 -and $x -eq $null; $i++) { Start-Sleep -Milliseconds 500; try { $x = [Runtime.InteropServices.Marshal]::GetActiveObject("Excel.Application") } catch {} }
if ($x -eq $null) { "could not bind"; exit 1 }
function Fmt($v) { if ($v -is [double]) { $v.ToString("R") } else { "$v" } }
try {
  "Excel Path=" + $x.Path + " Version=" + $x.Version + " Build=" + $x.Build
  $x.DisplayAlerts = $false
  $x.AskToUpdateLinks = $false; $x.AutomationSecurity = 3; $wb = $x.Workbooks.Open($copy, 0, $false); "opened"
  $t = $wb.Worksheets.Item("Term 1")
  "Term 1 protected: " + $t.ProtectContents
  "AL17 formula: " + $t.Range("AL17").Formula
  "AM17 formula: " + $t.Range("AM17").Formula2

  # Class configuration as the oracle writes it: core 20/50/30, shares 30/30/40.
  $t.Range("C17").Value2 = "LEARNER, TEST"
  $t.Range("D12").Value2 = 0.2; $t.Range("Q12").Value2 = 0.5; $t.Range("AD12").Value2 = 0.3
  $t.Range("AG14").Value2 = 30; $t.Range("AH14").Value2 = 30; $t.Range("AI14").Value2 = 40
  foreach ($c in 'D','E','F','G','H','I','J','K','L','M','Q','R','S','T','U','V','W','X','Y','Z','AD','AE','AF') { $t.Range("${c}14").Value2 = $null; $t.Range("${c}17").Value2 = $null }
  $t.Range("D14").Value2 = 100; $t.Range("Q14").Value2 = 100; $t.Range("AD14").Value2 = 100; $t.Range("AE14").Value2 = 100; $t.Range("AF14").Value2 = 100

  # [label, WW, PT, ST1, ST2, TE] â€” each /100 (the cases in WorkbookParityTest).
  $cases = @(
    @('69.98 exact',0,82,90,100,99), @('69.98 noisy',2,80,98,100,98),
    @('69.99 exact',0,81,97,100,98), @('69.99 noisy',1,80,99,100,99),
    @('70.00 exact',0,80,100,100,100),
    @('71.16 exact',0,84,92,100,99), @('71.16 noisy',77,100,0,0,48),
    @('71.17 exact',0,83,99,100,98), @('71.17 noisy',73,100,0,1,54),
    @('71.18 exact',0,85,88,100,98), @('71.18 noisy',4,81,100,100,99),
    @('74.70 exact',0,90,98,100,99), @('74.70 noisy',4,89,96,100,98),
    @('74.71 exact',2,90,95,100,98), @('74.71 noisy',0,92,87,100,99),
    @('74.72 exact',0,91,94,100,98)
  )
  $rows = New-Object System.Collections.Generic.List[string]; $rows.Add("label,ww,pt,st1,st2,te,P17,AC17,AK17,AL17,AM17")
  "CASES label | P17 | AC17 | AK17 | AL17 | AM17"
  foreach ($c in $cases) {
    $t.Range("D17").Value2 = [double]$c[1]; $t.Range("Q17").Value2 = [double]$c[2]; $t.Range("AD17").Value2 = [double]$c[3]; $t.Range("AE17").Value2 = [double]$c[4]; $t.Range("AF17").Value2 = [double]$c[5]
    $x.CalculateFull()
    $rows.Add((($c | ForEach-Object { "$_" }) + @((Fmt $t.Range("P17").Value2), (Fmt $t.Range("AC17").Value2), (Fmt $t.Range("AK17").Value2), (Fmt $t.Range("AL17").Value2), (Fmt $t.Range("AM17").Value2))) -join ',')
    ($c[0], (Fmt $t.Range("P17").Value2), (Fmt $t.Range("AC17").Value2), (Fmt $t.Range("AK17").Value2), (Fmt $t.Range("AL17").Value2), (Fmt $t.Range("AM17").Value2)) -join ' | '
  }

  [IO.File]::WriteAllLines((Join-Path $scratch "row17-component-sums.csv"), $rows)
  # Direct numeric inputs: every 2-dp value 0.00..100.00 through the workbook's own lookup arrays.
  $h = $wb.Worksheets.Add()
  $n = 10001
  $h.Range("A1:A$n").Formula = '=(ROW()-1)/100'
  $h.Range("B1:B$n").Formula2 = '=XLOOKUP(A1,HELPER!$C$7:$C$47,HELPER!$D$7:$D$47,,1)'
  $x.CalculateFull()
  $out = $h.Range("A1:B$n").Value2
  $lines = New-Object System.Collections.Generic.List[string]
  for ($i = 1; $i -le $n; $i++) { $lines.Add(([double]$out[$i,1]).ToString("R") + "," + (Fmt $out[$i,2])) }
  $lines.Insert(0, "initial_grade,term_grade"); [IO.File]::WriteAllLines((Join-Path $scratch "direct-lookup-0-100.csv"), $lines)
  "DIRECT rows written: " + $lines.Count
  foreach ($g in '69.98','69.99','70','71.16','71.17','71.18','74.7','74.71','74.72') { "direct " + $g + " -> " + ($lines | Where-Object { $_.StartsWith("$g,") }) }
  $wb.Close($false)
} finally { $x.Quit(); [Runtime.InteropServices.Marshal]::ReleaseComObject($x) | Out-Null; Start-Sleep 2; if (-not $p.HasExited) { Stop-Process -Id $p.Id -Force } }


