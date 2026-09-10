<?php

namespace Tests\Feature;

use App\Models\Specialization;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 3a — `specializations` mixed two DepEd
 * taxonomies on one row with nothing to tell them apart. The
 * 2026_09_10_000004 migration backfills the curriculum of EXISTING rows at
 * the time it runs, by code — it does NOT retroactively classify rows a
 * seeder creates afterward (`TracksAndSpecializationsSeeder` was not
 * updated to know about curriculum; that's a live gap noted in CLAUDE.md,
 * not this test's job to hide).
 *
 * Same pattern as PART 2's `ExistingInterventionsBackfilledToPrincipalTest`:
 * roll the schema back to just before this migration, insert raw rows
 * shaped exactly like the real production data, migrate forward, and check
 * the backfill caught every one of them — this is what the migration
 * ACTUALLY does, not what a seeder happens to produce.
 */
class SpecializationCurriculumBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function rollBackToBeforeCurriculumMigration(): void
    {
        $ranMigrations = DB::table('migrations')->orderBy('id')->pluck('migration')->values();
        $targetIndex = $ranMigrations->search('2026_09_10_000004_add_curriculum_to_specializations_table');
        $this->assertNotFalse($targetIndex, 'The curriculum migration must have already run before this test can roll it back.');
        Artisan::call('migrate:rollback', ['--step' => $ranMigrations->count() - $targetIndex]);
    }

    public function test_the_real_12_row_production_shape_backfills_correctly_and_the_missing_stem_strand_is_added(): void
    {
        $this->rollBackToBeforeCurriculumMigration();

        $academicId = Track::firstOrCreate(['code' => 'ACAD'], ['name' => 'Academic Track'])->id;
        $techproId = Track::firstOrCreate(['code' => 'TECHPRO'], ['name' => 'TechPro Track'])->id;
        $tvlId = Track::firstOrCreate(['code' => 'TVL'], ['name' => 'TVL Track'])->id;

        $rows = [
            ['track_id' => $academicId, 'code' => 'STEM', 'name' => 'Science, Technology, Engineering, and Mathematics'],
            ['track_id' => $academicId, 'code' => 'ASSH', 'name' => 'Arts, Social Sciences, and Humanities'],
            ['track_id' => $academicId, 'code' => 'BUSENT', 'name' => 'Business and Entrepreneurship'],
            ['track_id' => $academicId, 'code' => 'SHW', 'name' => 'Sports, Health, and Wellness'],
            ['track_id' => $academicId, 'code' => 'ABM', 'name' => 'Accountancy, Business and Management'],
            ['track_id' => $academicId, 'code' => 'HUMSS', 'name' => 'Humanities and Social Sciences'],
            ['track_id' => $academicId, 'code' => 'GAS', 'name' => 'General Academic Strand'],
            ['track_id' => $techproId, 'code' => 'ICTPROG', 'name' => 'ICT Support and Computer Programming Technologies'],
            ['track_id' => $techproId, 'code' => 'AGRIFOOD', 'name' => 'Agri-Fishery Business and Food Innovation'],
            ['track_id' => $techproId, 'code' => 'HOSPTOUR', 'name' => 'Hospitality and Tourism'],
            ['track_id' => $tvlId, 'code' => 'ICT', 'name' => 'Information and Communications Technology'],
            ['track_id' => $tvlId, 'code' => 'HE', 'name' => 'Home Economics'],
        ];
        $now = now();
        foreach ($rows as $row) {
            DB::table('specializations')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
        }

        Artisan::call('migrate');

        $this->assertSame(13, Specialization::count(), '12 pre-existing rows + 1 inserted missing old-curriculum STEM strand.');
        $this->assertCount(0, Specialization::whereNull('curriculum')->get());

        foreach (['STEM', 'ASSH', 'BUSENT', 'SHW', 'ICTPROG', 'AGRIFOOD', 'HOSPTOUR'] as $code) {
            $this->assertTrue(Specialization::where('code', $code)->where('curriculum', 'sshs')->exists(), "{$code} must have an sshs row.");
        }
        foreach (['ABM', 'HUMSS', 'GAS', 'ICT', 'HE'] as $code) {
            $this->assertTrue(Specialization::where('code', $code)->where('curriculum', 'k12_2013')->exists(), "{$code} must be k12_2013.");
        }

        $sshsStem = Specialization::where('code', 'STEM')->where('curriculum', 'sshs')->first();
        $k12Stem = Specialization::where('code', 'STEM')->where('curriculum', 'k12_2013')->first();
        $this->assertNotNull($sshsStem);
        $this->assertNotNull($k12Stem, 'The missing old-curriculum STEM strand row must be inserted.');
        $this->assertNotSame($sshsStem->id, $k12Stem->id);
        $this->assertSame($sshsStem->track_id, $k12Stem->track_id, 'Both STEM rows share the ACAD track_id — curriculum is what disambiguates them now.');
    }
}
