<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Adjusted Transmutation Table — DepEd Order No. 015, s. 2026
 *
 * Applies to Key Stages 2 to 4 for SY 2026-2027 as a transition toward
 * zero-based grading. From SY 2027-2028 transmutation is removed for
 * Grades 4 to 12 and a raw 75 stands as a term grade of 75 with no
 * adjustment — so this scheme has a known expiry and the code that
 * selects it should not assume otherwise.
 *
 * The anchor: an Initial Grade of 70.00 corresponds to a transmuted
 * passing grade of 75. Initial Grades below 70 map to 60 through 74,
 * with 60 as the minimum reportable grade.
 *
 * SOURCE NOTE — read before defending this in writing.
 * These 41 bands were cross-checked across three independent
 * reproductions of the table, which agree on every figure. They have
 * NOT been read from the signed PDF of DO 015, s. 2026 itself.
 * Before citing this table in the thesis, download the order from
 * deped.gov.ph and confirm the bands, then delete this note.
 *
 * Run with:  php artisan db:seed --class=Do015TransmutationSeeder
 */
class Do015TransmutationSeeder extends Seeder
{
    private const SCHEME = 'do015_2026';

    /** [min_initial, max_initial, transmuted] */
    private const BANDS = [
        [99.50, 100.00, 100],
        [98.32,  99.49,  99],
        [97.14,  98.31,  98],
        [95.96,  97.13,  97],
        [94.78,  95.95,  96],
        [93.60,  94.77,  95],
        [92.42,  93.59,  94],
        [91.24,  92.41,  93],
        [90.06,  91.23,  92],
        [88.88,  90.05,  91],
        [87.70,  88.87,  90],
        [86.52,  87.69,  89],
        [85.34,  86.51,  88],
        [84.16,  85.33,  87],
        [82.98,  84.15,  86],
        [81.80,  82.97,  85],
        [80.62,  81.79,  84],
        [79.44,  80.61,  83],
        [78.26,  79.43,  82],
        [77.08,  78.25,  81],
        [75.90,  77.07,  80],
        [74.72,  75.89,  79],
        [73.54,  74.71,  78],
        [72.36,  73.53,  77],
        [71.18,  72.35,  76],
        [70.00,  71.17,  75],   // <- the passing anchor
        [65.34,  69.99,  74],
        [60.67,  65.33,  73],
        [56.01,  60.66,  72],
        [51.34,  56.00,  71],
        [46.67,  51.33,  70],
        [42.01,  46.66,  69],
        [37.34,  42.00,  68],
        [32.68,  37.33,  67],
        [28.01,  32.67,  66],
        [23.35,  28.00,  65],
        [18.68,  23.34,  64],
        [14.01,  18.67,  63],
        [ 9.35,  14.00,  62],
        [ 4.68,   9.34,  61],
        [ 0.00,   4.67,  60],   // <- minimum reportable grade
    ];

    public function run(): void
    {
        // Self-check before writing anything. A transmutation table with a
        // gap or an overlap silently produces wrong report-card grades, so
        // it is worth refusing to seed rather than discovering it later.
        $sorted = self::BANDS;
        usort($sorted, fn ($a, $b) => $a[0] <=> $b[0]);

        $problems = [];
        for ($i = 1; $i < count($sorted); $i++) {
            $gap = round($sorted[$i][0] - $sorted[$i - 1][1], 2);
            if ($gap > 0.02) {
                $problems[] = "gap between {$sorted[$i-1][1]} and {$sorted[$i][0]}";
            }
            if ($gap < 0) {
                $problems[] = "overlap between {$sorted[$i-1][1]} and {$sorted[$i][0]}";
            }
        }
        if ($sorted[0][0] > 0.0) {
            $problems[] = 'table does not start at 0.00';
        }
        if (end($sorted)[1] < 100.0) {
            $problems[] = 'table does not reach 100.00';
        }

        if ($problems) {
            $this->command->error('Refusing to seed — table is not continuous:');
            foreach ($problems as $p) {
                $this->command->line("  - {$p}");
            }

            return;
        }

        DB::table('transmutation_ranges')->where('scheme', self::SCHEME)->delete();

        foreach (self::BANDS as [$min, $max, $transmuted]) {
            DB::table('transmutation_ranges')->insert([
                'scheme'      => self::SCHEME,
                'min_initial' => $min,
                'max_initial' => $max,
                'transmuted'  => $transmuted,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $this->command->info(count(self::BANDS).' bands seeded for '.self::SCHEME.'.');
        $this->command->line('  70.00 -> 75   (passing anchor)');
        $this->command->line('  0.00  -> 60   (minimum reportable)');
        $this->command->line('  100.00 -> 100');
        $this->command->newLine();
        $this->command->warn('Bands are from cross-checked secondary sources, not the signed PDF.');
        $this->command->warn('Confirm against DO 015, s. 2026 on deped.gov.ph before citing in writing.');
    }
}