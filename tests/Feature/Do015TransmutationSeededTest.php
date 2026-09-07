<?php

namespace Tests\Feature;

use App\Models\TransmutationRange;
use App\Services\TransmutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 0 of "UI cleanup and correctness pass" — proves Do015TransmutationSeeder
 * actually delivers full coverage, not just the old single-anchor stub:
 * 41 rows for do015_2026, and a computed grade of 67.22 lands in ITS OWN
 * band rather than falling back to do8_2015's 79.
 */
class Do015TransmutationSeededTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
    }

    public function test_do015_2026_has_all_41_bands_seeded(): void
    {
        $this->assertSame(41, TransmutationRange::where('scheme', 'do015_2026')->count());
    }

    public function test_a_computed_grade_of_67_22_does_not_transmute_to_the_do8_2015_band_of_79(): void
    {
        $service = new TransmutationService();

        $result = $service->transmuteWithAvailability(67.22, 'do015_2026');

        $this->assertTrue($result['available']);
        $this->assertNotEquals(79.0, $result['value'], 'do8_2015\'s band for 67.22 is 79 — do015_2026 must resolve to its OWN band, not fall back to do8_2015\'s.');
        // 67.22 falls in do015_2026's [65.34, 69.99] -> 74 band.
        $this->assertEquals(74.0, $result['value']);
    }
}
