<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DATA migration — three do015_2026 transmutation rows only.
 *
 * The client workbook's Term Grade is AM17 = XLOOKUP(AL17, HELPER!C7:C47,
 * HELPER!D7:D47, , 1) over the STORED band maxima, two of which sit a hair
 * below their printed value (C33 = 69.9899999999999, C32 = 71.1699999999999).
 * For 2-decimal Initial Grades the formula therefore gives 69.99 -> 75 and
 * 71.17 -> 76, where the printed bands say 74 and 75. This writes the
 * formula's result at 2 dp (see Do015TransmutationSeeder's note):
 *   74: 65.34-69.99 -> 65.34-69.98
 *   75: 70.00-71.17 -> 69.99-71.16
 *   76: 71.18-72.35 -> 71.17-72.35
 *
 * Touches nothing else: only rows that still hold the printed values are
 * changed (idempotent; a database without these rows is left alone), and no
 * grade is recomputed or rewritten — a verified grade whose computed figure
 * sits exactly on 69.99 or 71.17 reads "Re-verify" on Verify Grades.
 * down() restores the printed values.
 */
return new class extends Migration
{
    private const CHANGES = [
        // transmuted => [[old min, old max], [new min, new max]]
        74 => [[65.34, 69.99], [65.34, 69.98]],
        75 => [[70.00, 71.17], [69.99, 71.16]],
        76 => [[71.18, 72.35], [71.17, 72.35]],
    ];

    public function up(): void
    {
        $this->apply(0, 1);
    }

    public function down(): void
    {
        $this->apply(1, 0);
    }

    private function apply(int $from, int $to): void
    {
        // All three or none: a partly edited table would have an overlap or a gap.
        $rows = DB::table('transmutation_ranges')->where('scheme', 'do015_2026')->get();
        $matches = collect(self::CHANGES)->every(fn($pair, $transmuted) => $rows->contains(fn($r) => (int) $r->transmuted === $transmuted
            && round((float) $r->min_initial, 2) === $pair[$from][0] && round((float) $r->max_initial, 2) === $pair[$from][1]));
        if (!$matches) {
            return;
        }

        DB::transaction(function () use ($from, $to) {
            foreach (self::CHANGES as $transmuted => $pair) {
                DB::table('transmutation_ranges')->where('scheme', 'do015_2026')->where('transmuted', $transmuted)
                    ->where('min_initial', $pair[$from][0])->where('max_initial', $pair[$from][1])
                    ->update(['min_initial' => $pair[$to][0], 'max_initial' => $pair[$to][1], 'updated_at' => now()]);
            }
        });
    }
};
