<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "ECR alignment" work order, PART 3a — `specializations` mixes two DepEd
 * taxonomies on one row with nothing to tell them apart: STEM/ASSH/BUSENT/SHW
 * and the Tech-Pro cluster rows are Strengthened SHS clusters; ABM/HUMSS/GAS
 * and the TVL rows are the old 2013-curriculum strands. A Grade 12 STEM
 * strand section has nowhere to point today, since the existing STEM row
 * already means "SSHS STEM cluster."
 *
 * Backfilled by CODE, not id, so this is safe to re-run against a
 * differently-ordered database. Verified against this database's actual
 * live rows (not TracksAndSpecializationsSeeder.php, which produces
 * different codes entirely and is evidently unused for this data):
 *
 *   sshs:     STEM, ASSH, BUSENT, SHW (track ACAD)
 *             ICTPROG, AGRIFOOD, HOSPTOUR (track TECHPRO -- these three
 *             match Strengthened SHS Tech-Pro cluster names in the 141-row
 *             catalog from PART 2 exactly, not old strand names)
 *   k12_2013: ABM, HUMSS, GAS (track ACAD)
 *             ICT, HE (track TVL -- the old 2013 TVL strand names)
 *
 * The missing old-curriculum STEM strand (track ACAD, code STEM,
 * curriculum k12_2013) is inserted here -- the whole reason the unique
 * index below has to widen: two rows now legitimately share (track_id,
 * code), disambiguated only by curriculum.
 *
 * Stays NULLABLE, deliberately, matching sections.curriculum: three live
 * creation paths (Admin\SpecializationController::store(), the
 * SpecializationsImport and TracksImport bulk-import classes) have no
 * curriculum concept in their form/file format at all and no way to answer
 * the question. Forcing NOT NULL here would mean fabricating a
 * classification for every specialization those paths create rather than
 * honestly recording "not yet classified" -- the same reasoning
 * sections.curriculum already applies. All 13 EXISTING rows are still
 * fully backfilled and non-null below; only future rows created through
 * those three paths start null until something sets it explicitly.
 */
return new class extends Migration
{
    private const SSHS_CODES = ['STEM', 'ASSH', 'BUSENT', 'SHW', 'ICTPROG', 'AGRIFOOD', 'HOSPTOUR'];
    private const K12_2013_CODES = ['ABM', 'HUMSS', 'GAS', 'ICT', 'HE'];

    public function up(): void
    {
        Schema::table('specializations', function (Blueprint $table) {
            $table->string('curriculum')->nullable()->after('code');
        });

        DB::table('specializations')->whereIn('code', self::SSHS_CODES)->update(['curriculum' => 'sshs']);
        DB::table('specializations')->whereIn('code', self::K12_2013_CODES)->update(['curriculum' => 'k12_2013']);

        $unclassified = DB::table('specializations')->whereNull('curriculum')->pluck('code');
        if ($unclassified->isNotEmpty()) {
            throw new \RuntimeException(
                'Refusing to continue -- specializations with unrecognised codes have no curriculum mapping: ' . $unclassified->implode(', ')
            );
        }

        // Create the new (track_id, code, curriculum) unique index BEFORE
        // dropping the old (track_id, code) one -- MySQL refuses to drop
        // the old index while it's the only one satisfying the
        // specializations_track_id_foreign key (which needs SOME index
        // with track_id as its leftmost column). Creating the replacement
        // first gives the FK an index to fall back to.
        Schema::table('specializations', function (Blueprint $table) {
            $table->unique(['track_id', 'code', 'curriculum'], 'specializations_track_id_code_curriculum_unique');
        });

        Schema::table('specializations', function (Blueprint $table) {
            $table->dropUnique('specializations_track_id_code_unique');
        });

        // This is a fix-up migration for EXISTING production data, not a
        // from-scratch seed -- a fresh environment (e.g. RefreshDatabase in
        // tests, where migrations run before any seeder) has no 'ACAD'
        // track and no specializations rows to fix up at all yet, so there
        // is nothing to insert. TracksAndSpecializationsSeeder /
        // DepedSubjectCatalogSeeder-equivalent seeding, when it runs, is
        // responsible for that environment's own data going forward.
        $academicTrackId = DB::table('tracks')->where('code', 'ACAD')->value('id');
        if ($academicTrackId !== null) {
            $now = now();
            DB::table('specializations')->insert([
                'track_id'   => $academicTrackId,
                'name'       => 'Science, Technology, Engineering, and Mathematics',
                'code'       => 'STEM',
                'curriculum' => 'k12_2013',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('specializations')->where('code', 'STEM')->where('curriculum', 'k12_2013')->delete();

        // Same MySQL FK-index ordering constraint as up(): create the old
        // 2-column index before dropping the 3-column one, since track_id's
        // foreign key needs SOME index with track_id leftmost at all times.
        Schema::table('specializations', function (Blueprint $table) {
            $table->unique(['track_id', 'code'], 'specializations_track_id_code_unique');
        });

        Schema::table('specializations', function (Blueprint $table) {
            $table->dropUnique('specializations_track_id_code_curriculum_unique');
        });

        Schema::table('specializations', function (Blueprint $table) {
            $table->dropColumn('curriculum');
        });
    }
};
