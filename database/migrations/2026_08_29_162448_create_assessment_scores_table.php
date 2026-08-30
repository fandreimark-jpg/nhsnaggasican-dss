<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATCH-UP MIGRATION — see the note in 2026_08_29_162447_create_assessments_table.
 *
 * One row per student per assessment item — the actual earned score.
 * Kept separate from `assessments` (the item definition) so max_score,
 * name, and component aren't repeated on every student's row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->decimal('score', 6, 2);
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id'], 'unique_score_per_student_per_assessment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_scores');
    }
};
