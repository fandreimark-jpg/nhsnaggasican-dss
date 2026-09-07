<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Collection;

/**
 * maatwebsite/excel's SkipsFailures reports one Failure per failed
 * ATTRIBUTE, not per row — an 8-row file where each bad row is missing 5
 * required fields would otherwise be reported as "40 row(s) skipped"
 * instead of 8. Group by row first, then combine each row's attribute
 * errors into a single message, so both the count and the message list
 * are row-shaped everywhere this is used.
 */
trait SummarizesImportFailures
{
    /**
     * @param Collection<int, \Maatwebsite\Excel\Validators\Failure> $failures
     * @return array{skippedCount: int, rowMessages: array<int, string>, headerHint: ?string}
     */
    protected function summarizeImportFailures(Collection $failures, object $importer): array
    {
        $byRow = $failures->groupBy(fn($failure) => $failure->row())->sortKeys();

        $rowMessages = $byRow->map(function (Collection $rowFailures, int $row) {
            $messages = $rowFailures->flatMap(fn($f) => $f->errors())->all();

            return "Row {$row}: " . implode(', ', $messages);
        })->values()->all();

        return [
            'skippedCount' => $byRow->count(),
            'rowMessages'  => $rowMessages,
            'headerHint'   => $this->headerHintFor($byRow, $importer),
        ];
    }

    /**
     * If every failed row is missing every required column, the most
     * likely cause is a header row that doesn't match the template at
     * all — surface that directly rather than leaving the reader to
     * decode a wall of per-column "required" messages.
     */
    private function headerHintFor(Collection $byRow, object $importer): ?string
    {
        if (!method_exists($importer, 'rules')) {
            return null;
        }

        $requiredFields = collect($importer->rules())
            ->filter(fn($rules) => in_array('required', (array) $rules, true))
            ->keys()
            ->map(fn($key) => preg_replace('/^\*\./', '', $key))
            ->values();

        if ($requiredFields->isEmpty()) {
            return null;
        }

        $allRowsMissingAllRequired = $byRow->every(function (Collection $rowFailures) use ($requiredFields) {
            $missingFields = $rowFailures
                ->filter(fn($f) => collect($f->errors())->contains(fn($msg) => stripos($msg, 'is required') !== false))
                ->map(fn($f) => preg_replace('/^\*\./', '', $f->attribute()))
                ->unique();

            return $requiredFields->diff($missingFields)->isEmpty();
        });

        if (!$allRowsMissingAllRequired) {
            return null;
        }

        return "All skipped rows were missing every required column. Check that your file's header row matches the template exactly: `"
            . $requiredFields->implode(', ') . '`.';
    }
}
