<?php

namespace Database\Seeders;

use App\Models\TransmutationRange;
use Illuminate\Database\Seeder;

/**
 * The DepEd K-12 transmutation table under DO 8, s. 2015 (scheme
 * 'do8_2015') — long-standing and unambiguous: Initial Grade 100
 * transmutes to 100, the passing cut-off (Transmuted 75) sits at an
 * Initial Grade of exactly 60, and every Initial Grade below 60 floors
 * at a Transmuted 60. The full band set is entered explicitly below
 * (not approximated by a formula at lookup time — see
 * TransmutationService, which does a strict range lookup against these
 * rows) so it can be checked band-by-band against the published table.
 *
 * Idempotent via firstOrCreate() per row, matching this codebase's
 * other seeders.
 */
class TransmutationRangesSeeder extends Seeder
{
    private const SCHEME = 'do8_2015';

    /** @var array<int, array{0: float, 1: float, 2: int}> [min_initial, max_initial, transmuted] */
    private const BANDS = [
        [100.00, 100.00, 100],
        [98.40, 99.99, 99],
        [96.80, 98.39, 98],
        [95.20, 96.79, 97],
        [93.60, 95.19, 96],
        [92.00, 93.59, 95],
        [90.40, 91.99, 94],
        [88.80, 90.39, 93],
        [87.20, 88.79, 92],
        [85.60, 87.19, 91],
        [84.00, 85.59, 90],
        [82.40, 83.99, 89],
        [80.80, 82.39, 88],
        [79.20, 80.79, 87],
        [77.60, 79.19, 86],
        [76.00, 77.59, 85],
        [74.40, 75.99, 84],
        [72.80, 74.39, 83],
        [71.20, 72.79, 82],
        [69.60, 71.19, 81],
        [68.00, 69.59, 80],
        [66.40, 67.99, 79],
        [64.80, 66.39, 78],
        [63.20, 64.79, 77],
        [61.60, 63.19, 76],
        [60.00, 61.59, 75],
        [56.00, 59.99, 74],
        [52.00, 55.99, 73],
        [48.00, 51.99, 72],
        [44.00, 47.99, 71],
        [40.00, 43.99, 70],
        [36.00, 39.99, 69],
        [32.00, 35.99, 68],
        [28.00, 31.99, 67],
        [24.00, 27.99, 66],
        [20.00, 23.99, 65],
        [16.00, 19.99, 64],
        [12.00, 15.99, 63],
        [8.00, 11.99, 62],
        [4.00, 7.99, 61],
        [0.00, 3.99, 60],
    ];

    // DO 015, s. 2026's adjusted transmutation table (scheme
    // 'do015_2026') is a separate, fully-seeded table — see
    // Do015TransmutationSeeder, which owns that scheme end to end
    // (all 41 bands, plus its own gap/overlap self-check before writing
    // anything). Nothing for that scheme is seeded here.

    public function run(): void
    {
        foreach (self::BANDS as [$min, $max, $transmuted]) {
            TransmutationRange::firstOrCreate(
                ['scheme' => self::SCHEME, 'min_initial' => $min, 'max_initial' => $max],
                ['transmuted' => $transmuted]
            );
        }
    }
}
