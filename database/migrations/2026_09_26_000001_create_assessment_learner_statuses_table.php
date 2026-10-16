<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Rule-based academic monitoring" Phase 2A — Assessment Status Foundation.
 *
 * An explicit, adviser-recorded learner status on one assessment item,
 * kept OUT of assessment_scores on purpose: assessment_scores.score is
 * NOT NULL and GradingEngine casts every row it reads to a float, so a
 * nullable score would be read as 0 — exactly the "blank is not zero"
 * rule this project protects. A marker here is never read by
 * GradingEngine at all.
 *
 * The three display states are DERIVED, never stored redundantly
 * (App\Services\AssessmentStatusService):
 *   a score row exists            -> Completed
 *   else a 'missing' row exists   -> Missing
 *   else                          -> Not Yet Given / No Evidence Yet
 *
 * 'missing' is the only value on purpose. Any other reason for a blank
 * (excused, exempt, make-up pending) is a school policy nobody has
 * decided; widening the enum is a later additive migration.
 *
 * Additive only: creates one new table, touches no existing row.
 * down() drops only this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_learner_statuses', function (Blueprint $table) {
            $table->id();
            // Same delete behaviour as assessment_scores: a marker has no
            // meaning without its item or its learner.
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('status', ['missing']);
            // Same as assessments.uploaded_by: who recorded it is audit
            // history, so an account that recorded one is disabled, not deleted.
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id'], 'unique_learner_status_per_assessment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_learner_statuses');
    }
};
