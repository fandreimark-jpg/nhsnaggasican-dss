<?php

namespace Tests\Feature;

use App\Models\TransmutationRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 2 of "Naggasican DSS: unblock verification" — dss:verify-
 * transmutation. do8_2015's real, long-established table passes cleanly;
 * an empty do015_2026 reports zero bands and fails loudly, exactly the
 * situation Task 1 exists to unblock.
 */
class VerifyTransmutationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    public function test_do8_2015_passes(): void
    {
        $this->artisan('dss:verify-transmutation', ['scheme' => 'do8_2015'])
            ->assertExitCode(0);
    }

    public function test_an_empty_do015_2026_reports_zero_bands_and_exits_non_zero(): void
    {
        TransmutationRange::where('scheme', 'do015_2026')->delete();

        $this->artisan('dss:verify-transmutation', ['scheme' => 'do015_2026'])
            ->assertExitCode(1);
    }

    public function test_do015_2026_with_nothing_seeded_at_all_fails_on_coverage(): void
    {
        // do015_2026 is now owned entirely by Do015TransmutationSeeder
        // (see its class docblock) — TransmutationRangesSeeder (seeded in
        // setUp() above) never touches that scheme, so without also
        // running Do015TransmutationSeeder this scheme has zero rows and
        // must still fail loudly, same as the explicitly-emptied case
        // above.
        $this->artisan('dss:verify-transmutation', ['scheme' => 'do015_2026'])
            ->assertExitCode(1);
    }

    public function test_do015_2026_with_the_real_seeder_run_passes_cleanly(): void
    {
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);

        $this->artisan('dss:verify-transmutation', ['scheme' => 'do015_2026'])
            ->assertExitCode(0);
    }

    public function test_a_gap_in_an_otherwise_complete_table_fails(): void
    {
        // Remove one interior band from the real do8_2015 table.
        TransmutationRange::where('scheme', 'do8_2015')
            ->where('min_initial', 84.00)->where('max_initial', 85.59)
            ->delete();

        $this->artisan('dss:verify-transmutation', ['scheme' => 'do8_2015'])
            ->assertExitCode(1);
    }

    public function test_an_overlap_fails_even_though_it_still_covers_0_to_100(): void
    {
        // A duplicate/overlapping band claiming part of an existing
        // band's range — two bands would now match the same initial
        // grade.
        TransmutationRange::create(['scheme' => 'do8_2015', 'min_initial' => 84.50, 'max_initial' => 86.00, 'transmuted' => 91]);

        $this->artisan('dss:verify-transmutation', ['scheme' => 'do8_2015'])
            ->assertExitCode(1);
    }

    public function test_running_with_no_scheme_argument_checks_every_known_scheme(): void
    {
        // do015_2026 is incomplete (real current state), so the overall
        // command must still fail even though do8_2015 alone is fine.
        $this->artisan('dss:verify-transmutation')
            ->assertExitCode(1);
    }
}
