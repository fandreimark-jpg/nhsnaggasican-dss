<?php

use App\Models\DepedSubjectCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "ECR alignment" work order, PART 2a — the official DepEd Strengthened SHS
 * Electronic Class Record's subject catalog (`HELPER!J7:AC161`, `ECRSHS2026`,
 * `2026_v1.0`), 141 rows, per-subject weights. Sits ABOVE `subject_group_weights`
 * in GradingEngine's resolution order (catalog row, if linked, wins; otherwise
 * the existing 6-bucket `subject_group_weights` lookup is unchanged) — this
 * table never replaces that one, which represents DO 015 Table 10, a
 * different authority that can disagree with the catalog on purpose (see
 * CLAUDE.md's "rule on conflicting evidence").
 *
 * Seeded here from database/seeders/deped_sshs_catalog.csv — the extraction of
 * HELPER!J7:AC161 — via DepedSubjectCatalog::rowsFromCsv(), rather than 141
 * rows hand-copied into a PHP literal, which is exactly the kind of
 * transcription risk this feature exists to prevent for a government
 * instrument. DepedSubjectCatalogSeeder re-runs the same parse, idempotently,
 * for when DepEd ships a future version and the CSV is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deped_subject_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('scheme');
            $table->string('track');
            $table->string('cluster');
            $table->string('course_title');
            $table->unsignedInteger('total_hours')->nullable();
            $table->string('grade_levels'); // e.g. "11,12" or "12" -- derived from g11/g12 presence, never from the printed grade_lvl text
            $table->unsignedTinyInteger('g11_terms')->nullable();
            $table->decimal('g11_units_per_term', 5, 2)->nullable();
            $table->decimal('g11_units_per_year', 5, 2)->nullable();
            $table->unsignedTinyInteger('g12_terms')->nullable();
            $table->decimal('g12_units_per_term', 5, 2)->nullable();
            $table->decimal('g12_units_per_year', 5, 2)->nullable();
            $table->decimal('ww_weight', 5, 2)->nullable();
            $table->decimal('pt_weight', 5, 2)->nullable();
            $table->decimal('ex_weight', 5, 2)->nullable(); // null = no Examination component at all, never 0
            $table->decimal('st1_share', 5, 2)->nullable();
            $table->decimal('st2_share', 5, 2)->nullable();
            $table->decimal('te_share', 5, 2)->nullable();
            $table->boolean('teacher_supplied')->default(false); // true only for the 2 "OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM" rows
            $table->timestamps();

            // The only repeated course_title (the 2 teacher-supplied rows)
            // differs by track -- every other row is unique on course_title
            // alone, but the constraint is (scheme, course_title, track)
            // per the work order's own spec.
            $table->unique(['scheme', 'course_title', 'track']);
        });

        $now = now();
        foreach (DepedSubjectCatalog::rowsFromCsv() as $row) {
            DB::table('deped_subject_catalog')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
        }

        $count = DB::table('deped_subject_catalog')->count();
        if ($count !== 141) {
            throw new \RuntimeException("Expected to seed 141 deped_subject_catalog rows, got {$count} -- check database/seeders/deped_sshs_catalog.csv.");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deped_subject_catalog');
    }
};
