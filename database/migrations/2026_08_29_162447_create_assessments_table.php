<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATCH-UP MIGRATION.
 *
 * The `assessments` table (and `assessment_scores`, `users.email_verified_at`
 * — see the two migrations immediately after this one, same dates) already
 * exist in the real dev database under exactly these migration names, but
 * the migration FILES that created them were never committed to this repo
 * — an earlier exploratory session ran them directly against the dev DB
 * and the files were lost. That left the live database ahead of what any
 * committed migration could reproduce: a fresh clone or the SQLite test
 * database had neither table at all.
 *
 * This file (and the two after it) recreates those exact migrations by
 * name so: (1) on the real dev DB, `php artisan migrate` sees the name
 * already in the ledger and skips it — no re-run, no data risk; (2) on a
 * fresh database (tests, a new clone), it creates the identical structure,
 * bringing every environment back in sync. Verified against the live
 * schema via SHOW COLUMNS / SHOW INDEX / information_schema before writing
 * this, per the "identify reusable tables before creating new ones" rule
 * — this table is `assessments` the ITEM/definition (one row per
 * subject+section+term+name, e.g. "Quiz 1" worth 20 points), NOT a
 * per-student score. Per-student scores live in assessment_scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('restrict');
            $table->foreignId('section_id')->constrained('sections')->onDelete('restrict');
            $table->integer('grading_period');
            $table->string('school_year');
            $table->string('name');            // e.g. "Quiz 1", "Performance Task 2"
            $table->string('assessment_type'); // raw detected label, e.g. "Quiz"
            $table->enum('component', ['written_work', 'performance_task', 'examination']);
            $table->decimal('max_score', 6, 2);
            $table->string('import_batch_id')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();

            $table->unique(
                ['subject_id', 'section_id', 'grading_period', 'school_year', 'name'],
                'unique_assessment_per_period'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
