<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded assessment form — tracks who uploaded it, for which
 * section/subject/term, and the FINAL verified column-to-component mapping
 * (adviser-corrected if needed) that was actually used to import it. This
 * is the audit trail that satisfies "the detection must be shown to the
 * Adviser for verification... ambiguous columns must not be silently
 * classified" — the mapping actually used is persisted, not just applied
 * and forgotten.
 *
 * The upload/verify/import WORKFLOW itself (detection, adviser correction,
 * preview) is P-5 — this migration only lays down where that workflow will
 * record its result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('sections')->onDelete('restrict');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('restrict');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->integer('grading_period');
            $table->string('school_year');
            $table->string('original_filename');

            // The column name -> assessment_components.key mapping that was
            // verified (and possibly corrected) by the adviser before import,
            // e.g. {"Quiz 1": "written_work", "Performance Task 1": "performance_task"}.
            $table->json('column_mapping')->nullable();

            $table->string('status')->default('pending_review'); // pending_review | imported | failed
            $table->unsignedInteger('imported_count')->nullable();
            $table->unsignedInteger('error_count')->nullable();
            $table->timestamps();

            $table->index(['section_id', 'subject_id', 'grading_period', 'school_year'], 'assessment_uploads_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_uploads');
    }
};
