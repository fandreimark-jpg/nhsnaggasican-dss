<?php

namespace Tests\Feature;

use App\Models\Intervention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Master pass" PART 1.2b — the 2026_09_09_000001 migration's up() must
 * backfill every EXISTING row to 'principal' (true of all of them — this
 * system has no other creation path) rather than leaving old rows null
 * while only new ones get a value. Verified by rolling the schema back to
 * just before this migration, inserting a raw row the ORM/factory never
 * touches, then migrating forward and checking the backfill caught it.
 */
class ExistingInterventionsBackfilledToPrincipalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pre_existing_row_is_backfilled_to_principal_on_migrate(): void
    {
        // --step=1 assumed the origin migration was always the newest one —
        // true when this test was written, false since the "ECR alignment"
        // work order added migrations after it. Roll back exactly from the
        // origin migration through whatever is newest, by name rather than
        // a hardcoded position, so this keeps working no matter how many
        // more migrations land later.
        $ranMigrations = DB::table('migrations')->orderBy('id')->pluck('migration')->values();
        $originIndex = $ranMigrations->search('2026_09_09_000001_add_origin_to_interventions_table');
        $this->assertNotFalse($originIndex, 'The origin migration must have already run before this test can roll it back.');
        Artisan::call('migrate:rollback', ['--step' => $ranMigrations->count() - $originIndex]);

        // Confirm we actually rolled back the origin migration, not some
        // unrelated later one — this table must have no origin column now.
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('interventions', 'origin'));

        $studentId = DB::table('students')->insertGetId([
            'lrn' => '110000000098', 'last_name' => 'Backfill', 'first_name' => 'Test',
            'section_id' => DB::table('sections')->insertGetId([
                'name' => 'BackfillSection', 'grade_level' => 11, 'school_year' => '2026-2027',
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'gender' => 'male', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $principalId = DB::table('users')->insertGetId([
            'name' => 'Backfill Principal', 'email' => 'backfill@example.com',
            'password' => bcrypt('secret'), 'role' => 'principal', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $preExistingId = DB::table('interventions')->insertGetId([
            'student_id' => $studentId, 'recommended_type' => 'remediation', 'status' => 'recommended',
            'created_by' => $principalId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('migrate');

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('interventions', 'origin'));
        $this->assertSame('principal', Intervention::find($preExistingId)->origin);
    }
}
