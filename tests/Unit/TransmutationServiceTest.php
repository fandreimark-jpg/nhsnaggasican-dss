<?php

namespace Tests\Unit;

use App\Models\TransmutationRange;
use App\Services\TransmutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DO 8, s. 2015 boundary coverage — every seeded band's edges, plus the
 * two behaviors that must degrade visibly rather than silently: a
 * missing scheme, and (defensively) a value outside every band.
 */
class TransmutationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransmutationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TransmutationService();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
    }

    public function test_100_transmutes_to_100(): void
    {
        $this->assertEquals(100.0, $this->service->transmute(100));
    }

    public function test_exactly_60_transmutes_to_the_75_passing_cutoff(): void
    {
        $this->assertEquals(75.0, $this->service->transmute(60));
    }

    public function test_just_below_60_falls_into_the_next_lower_band_not_the_cutoff(): void
    {
        $this->assertEquals(74.0, $this->service->transmute(59.99));
    }

    public function test_every_band_below_60_floors_at_a_transmuted_60(): void
    {
        $this->assertEquals(60.0, $this->service->transmute(0));
        $this->assertEquals(60.0, $this->service->transmute(2));
        $this->assertEquals(60.0, $this->service->transmute(3.99));
    }

    public function test_the_band_immediately_above_the_floor_transmutes_to_61(): void
    {
        $this->assertEquals(61.0, $this->service->transmute(4.00));
        $this->assertEquals(61.0, $this->service->transmute(7.99));
    }

    public function test_a_worked_example_from_gradeverificationtest_68_5_transmutes_to_80(): void
    {
        // WW=84% (contribution 21), PT=60% (contribution 30), Exam=70%
        // (contribution 17.5) -> computed_grade 68.5, which falls in the
        // 68.00-69.59 band.
        $this->assertEquals(80.0, $this->service->transmute(68.5));
    }

    public function test_a_value_below_every_band_returns_unchanged_and_does_not_throw(): void
    {
        TransmutationRange::where('scheme', 'do8_2015')->where('max_initial', '<', 10)->delete();

        // 5 no longer matches any band in this now-gapped scheme.
        $this->assertEquals(5.0, $this->service->transmute(5));
    }

    public function test_missing_scheme_returns_the_initial_grade_unchanged(): void
    {
        $this->assertEquals(72.34, $this->service->transmute(72.34, 'nonexistent_scheme'));
    }

    public function test_default_scheme_is_do8_2015(): void
    {
        $this->assertSame('do8_2015', TransmutationService::DEFAULT_SCHEME);
    }

    /**
     * TASK 2 of "terminology, transmutation, and interface cleanup" —
     * scheme selection by grade level + school year, per DO 015, s. 2026:
     * Grade 11 moves to the Strengthened curriculum's adjusted table in
     * SY 2026-2027; Grade 12 in that same year has not moved.
     */
    public function test_grade_11_sy_2026_2027_resolves_to_do015_2026(): void
    {
        $this->assertSame('do015_2026', $this->service->schemeFor(11, '2026-2027'));
    }

    public function test_grade_12_sy_2026_2027_stays_on_do8_2015(): void
    {
        $this->assertSame('do8_2015', $this->service->schemeFor(12, '2026-2027'));
    }

    public function test_grade_11_before_sy_2026_2027_stays_on_do8_2015(): void
    {
        $this->assertSame('do8_2015', $this->service->schemeFor(11, '2025-2026'));
    }

    /**
     * "ECR alignment" work order, PART 3a — curriculum, when given, is what
     * actually decides the scheme now, not grade level: an 'sshs' section
     * resolves to do015_2026 even in a year the grade-level inference alone
     * would call do8_2015 — this is the actual point of the column, a
     * transition cohort running differently than "Grade 11 = new curriculum."
     */
    public function test_sshs_curriculum_wins_even_when_the_year_alone_would_say_otherwise(): void
    {
        $this->assertSame('do015_2026', $this->service->schemeFor(11, '2020-2021', 'sshs'));
    }

    public function test_k12_2013_curriculum_wins_even_for_grade_11_in_sy_2026_2027(): void
    {
        $this->assertSame('do8_2015', $this->service->schemeFor(11, '2026-2027', 'k12_2013'));
    }

    public function test_null_curriculum_falls_back_to_the_original_grade_level_inference_unchanged(): void
    {
        $this->assertSame('do015_2026', $this->service->schemeFor(11, '2026-2027', null));
        $this->assertSame('do8_2015', $this->service->schemeFor(12, '2026-2027', null));
    }

    public function test_transmute_with_availability_reports_unavailable_for_a_grade_outside_do015_2026s_0_to_100_range(): void
    {
        // do015_2026 is now fully seeded 0.00-100.00 (Do015TransmutationSeeder)
        // — the only way to be genuinely unmatched is a value the table was
        // never meant to cover at all.
        $result = $this->service->transmuteWithAvailability(150.0, 'do015_2026');
        $this->assertFalse($result['available']);
        $this->assertEquals(150.0, $result['value']);
    }

    public function test_transmute_with_availability_reports_available_for_the_seeded_do015_2026_anchor(): void
    {
        $result = $this->service->transmuteWithAvailability(70.0, 'do015_2026');
        $this->assertTrue($result['available']);
        $this->assertEquals(75.0, $result['value']);
    }

    public function test_transmute_with_availability_resolves_a_mid_band_do015_2026_grade(): void
    {
        $result = $this->service->transmuteWithAvailability(68.5, 'do015_2026');
        $this->assertTrue($result['available']);
        $this->assertEquals(74.0, $result['value']);
    }
}
