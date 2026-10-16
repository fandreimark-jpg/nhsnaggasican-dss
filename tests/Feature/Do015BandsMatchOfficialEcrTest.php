<?php

namespace Tests\Feature;

use App\Models\TransmutationRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkbookOracle;
use Tests\TestCase;

/**
 * The 41 do015_2026 bands against the official DepEd Strengthened SHS
 * Electronic Class Record for SY 2026-2027 (ECRSHS2026, 2026_v1.0) —
 * HELPER!B7:D47 of tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx,
 * read from the FILE, not transcribed.
 *
 * What the workbook actually computes is AM17 = XLOOKUP(AL17,
 * HELPER!$C$7:$C$47, HELPER!$D$7:$D$47, , 1): the band whose STORED maximum
 * is the smallest one >= the Initial Grade. Column B (the printed minima) is
 * never read. For 2-decimal Initial Grades the effective band of each row
 * is therefore [previous effective max + 0.01, the largest 2-dp value not
 * above the stored maximum] — derived here with exact comparisons of the
 * stored double, no tolerance. Two stored maxima sit below their printed
 * value (C33 = 69.9899999999999, C32 = 71.1699999999999), which is why 69.99
 * transmutes to 75 and 71.17 to 76 (Do015TransmutationSeeder's note).
 */
class Do015BandsMatchOfficialEcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
    }

    /** @return list<array{0: float, 1: float, 2: int}> [min, max, transmuted] effective at 2 dp, low to high */
    private function effectiveBands(): array
    {
        $rows = WorkbookOracle::transmutationTable();
        usort($rows, fn($a, $b) => $a[1] <=> $b[1]);
        $bands = [];
        $previous = null;
        foreach ($rows as [, $storedMax, $transmuted]) {
            $max = round($storedMax, 2);
            if ($max > $storedMax) {          // a 2-dp grade equal to $max would exceed the stored maximum
                $max = round($max - 0.01, 2);
            }
            $bands[] = [$previous === null ? 0.0 : round($previous + 0.01, 2), $max, (int) $transmuted];
            $previous = $max;
        }

        return $bands;
    }

    public function test_all_41_bands_equal_the_workbook_lookup_at_two_decimals(): void
    {
        $expected = $this->effectiveBands();
        $this->assertCount(41, $expected);

        $seeded = TransmutationRange::where('scheme', 'do015_2026')->orderBy('min_initial')->get()
            ->map(fn($r) => [round((float) $r->min_initial, 2), round((float) $r->max_initial, 2), (int) $r->transmuted])->all();
        $this->assertSame($expected, $seeded);

        // Named, so a failure on the edges most likely to be hand-edited is unmistakable.
        $this->assertContains([65.34, 69.98, 74], $seeded);
        $this->assertContains([69.99, 71.16, 75], $seeded, '70.00 -> 75 (passing anchor); 69.99 -> 75 by the workbook lookup');
        $this->assertContains([71.17, 72.35, 76], $seeded);
        $this->assertContains([0.0, 4.67, 60], $seeded);
    }

    public function test_only_the_two_stored_precision_rows_differ_from_the_printed_table(): void
    {
        $printed = array_map(fn($r) => [round($r[0], 2), round($r[1], 2), (int) $r[2]], WorkbookOracle::transmutationTable());
        $differs = array_values(array_filter($this->effectiveBands(), fn($b) => !in_array($b, $printed, true)));

        $this->assertSame([[65.34, 69.98, 74], [69.99, 71.16, 75], [71.17, 72.35, 76]], $differs,
            'the 74 and 75 maxima (stored below their printed value) and the 76 minimum that follows');
    }

    /** The data migration for a database seeded before 2026-10-04: three rows, all or none, reversible. */
    public function test_the_edge_migration_moves_only_the_three_printed_rows_and_reverses(): void
    {
        $migration = require base_path('database/migrations/2026_10_04_000001_align_do015_transmutation_edges_with_workbook_lookup.php');
        $edges = fn() => TransmutationRange::where('scheme', 'do015_2026')->whereIn('transmuted', [74, 75, 76])->orderBy('transmuted')
            ->get()->map(fn($r) => [(float) $r->min_initial, (float) $r->max_initial])->all();
        $others = fn() => TransmutationRange::where('scheme', 'do015_2026')->whereNotIn('transmuted', [74, 75, 76])->orderBy('id')->get(['min_initial', 'max_initial', 'transmuted'])->toArray();
        $untouched = $others();
        $lookup = [[65.34, 69.98], [69.99, 71.16], [71.17, 72.35]];
        $printed = [[65.34, 69.99], [70.0, 71.17], [71.18, 72.35]];

        $migration->down();
        $this->assertSame($printed, $edges());
        $migration->up();
        $this->assertSame($lookup, $edges());
        $migration->up();
        $this->assertSame($lookup, $edges(), 'idempotent');
        $this->assertSame($untouched, $others(), 'no other band changes');

        // A table that matches neither shape is left alone (never a partial edit).
        $migration->down();
        TransmutationRange::where('scheme', 'do015_2026')->where('transmuted', 76)->update(['min_initial' => 71.19]);
        $migration->up();
        $this->assertSame([[65.34, 69.99], [70.0, 71.17], [71.19, 72.35]], $edges());
    }
}
