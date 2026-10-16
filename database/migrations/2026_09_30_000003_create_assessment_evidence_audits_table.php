<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Subject Teacher and Class Record" pass (2026-09-28) — an append-only
 * history of every change to one learner's evidence on one assessment
 * item: a score recorded, changed or cleared, and a Missing marker set or
 * removed. Written by model events on AssessmentScore and
 * AssessmentLearnerStatus (App\Services\EvidenceAudit), so every write
 * path — Class Record, the full-page item form, ECR/file import — is
 * covered without each one remembering to log.
 *
 * No foreign keys on purpose: an audit row must neither block nor be
 * cascaded away by a later delete of the item or learner it describes.
 * The item name, school year and term are copied onto the row for the
 * same reason. Additive; touches no existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_evidence_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assessment_id');
            $table->string('assessment_name')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('school_year', 9)->nullable();
            $table->unsignedTinyInteger('grading_period')->nullable();
            $table->decimal('old_score', 8, 2)->nullable();
            $table->decimal('new_score', 8, 2)->nullable();
            // completed | missing | not_yet_given (AssessmentStatusService)
            $table->string('old_status', 20);
            $table->string('new_status', 20);
            $table->string('source', 40)->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->dateTime('changed_at');

            $table->index(['assessment_id', 'student_id'], 'evidence_audits_item_learner_index');
            $table->index(['section_id', 'subject_id', 'school_year', 'grading_period'], 'evidence_audits_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_evidence_audits');
    }
};
