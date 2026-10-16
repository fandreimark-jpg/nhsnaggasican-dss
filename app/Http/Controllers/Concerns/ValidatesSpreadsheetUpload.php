<?php

namespace App\Http\Controllers\Concerns;

/**
 * "mimes:" MIME-sniffing rejects a real spreadsheet — found first at
 * Adviser\AssessmentController::detect(): the official DepEd SSHS
 * E-Class Record's actual bytes sniff as application/octet-stream, not a
 * recognised spreadsheet MIME, so mimes:xlsx,xls,csv,txt rejected the
 * exact file that route exists to accept. Confirmed directly —
 * Validator::make(['file' => <the real fixture>], ['file' =>
 * 'mimes:xlsx,xls'])->passes() === false — and at the real HTTP layer,
 * not assumed from that alone.
 *
 * Validate by EXTENSION instead. The file's real shape is verified
 * immediately after by whatever actually reads it (EcrProfileDetector,
 * AssessmentColumnClassifier's header parsing, a Maatwebsite import's own
 * row validation) — the extension check here only needs to keep out
 * something that obviously isn't one of the accepted file types at all;
 * it was never the thing actually guaranteeing the file was real.
 *
 * One place this rule lives, not six independent copies of the same
 * closure — see CLAUDE.md, "mimes: MIME-sniffing rejects the official
 * DepEd ECR" for the full list of routes this replaced mimes: on.
 */
trait ValidatesSpreadsheetUpload
{
    /**
     * @param array<int, string> $extensions lowercase, no leading dot
     * @param int $maxKb same unit/meaning as Laravel's own 'max:' rule
     * @return array<int, mixed> a Laravel validation rule array for the 'file' field
     */
    protected function spreadsheetFileRule(array $extensions = ['xlsx', 'xls', 'csv', 'txt'], int $maxKb = 2048): array
    {
        return ['required', 'file', "max:{$maxKb}", function ($attribute, $value, $fail) use ($extensions) {
            if (!in_array(strtolower($value->getClientOriginalExtension()), $extensions, true)) {
                $fail('The file must be a file of type: ' . implode(', ', $extensions) . '.');
            }
        }];
    }

    /**
     * Because the rule above checks only the extension, a corrupt or
     * truncated workbook reaches the reader and throws. Every import calls
     * this instead of Excel::import() directly so that is a refusal with a
     * message, never an HTTP 500. Returns null when the file was read, or the
     * message to show when it could not be (nothing was imported: the reader
     * fails before the first row).
     */
    protected function importSpreadsheetOrRefuse(object $import, \Illuminate\Http\UploadedFile $file): ?string
    {
        try {
            \Maatwebsite\Excel\Facades\Excel::import($import, $file);
        } catch (\PhpOffice\PhpSpreadsheet\Exception|\ValueError $e) {
            report($e);

            return '"' . $file->getClientOriginalName() . '" could not be read as a spreadsheet — it may be damaged or not really an Excel/CSV file. Nothing was imported.';
        }

        return null;
    }
}
