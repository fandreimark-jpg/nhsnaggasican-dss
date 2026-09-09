<?php

namespace Tests\Feature;

use App\Models\TransmutationRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 1d — the 41 bands transcribed directly
 * from HELPER!B7:D47 of the official DepEd Strengthened SHS Electronic
 * Class Record for SY 2026-2027 (ECRSHS2026, 2026_v1.0), asserted band by
 * band against what Do015TransmutationSeeder actually writes. This is the
 * evidence behind Do015TransmutationSeeder's SOURCE NOTE and CLAUDE.md's
 * "confirmed against DepEd's own instrument" claim — if a future edit to
 * the seeder's BANDS constant drifts from the workbook, this fails instead
 * of the claim silently going stale.
 */
class Do015BandsMatchOfficialEcrTest extends TestCase
{
    use RefreshDatabase;

    /** [min_initial, max_initial, transmuted] — HELPER!B7:D47, top to bottom. */
    private const ECR_BANDS = [
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
        [70.00,  71.17,  75],
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
        [ 0.00,   4.67,  60],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
    }

    public function test_all_41_ecr_bands_are_present_with_exact_figures(): void
    {
        $this->assertCount(41, self::ECR_BANDS, 'The fixture itself must carry all 41 rows from HELPER!B7:D47.');

        $seeded = TransmutationRange::where('scheme', 'do015_2026')->count();
        $this->assertSame(41, $seeded, 'Do015TransmutationSeeder must write exactly 41 rows for do015_2026.');

        foreach (self::ECR_BANDS as [$min, $max, $transmuted]) {
            $this->assertDatabaseHas('transmutation_ranges', [
                'scheme'      => 'do015_2026',
                'min_initial' => $min,
                'max_initial' => $max,
                'transmuted'  => $transmuted,
            ]);
        }

        // The passing anchor and the minimum reportable grade are the two
        // bands most likely to be hand-edited by mistake — already covered
        // by the loop above, called out again here by name so a failure on
        // either is unmistakable in the test's own output.
        $this->assertDatabaseHas('transmutation_ranges', [
            'scheme' => 'do015_2026', 'min_initial' => 70.00, 'max_initial' => 71.17, 'transmuted' => 75,
        ]);
        $this->assertDatabaseHas('transmutation_ranges', [
            'scheme' => 'do015_2026', 'min_initial' => 0.00, 'max_initial' => 4.67, 'transmuted' => 60,
        ]);
    }
}
