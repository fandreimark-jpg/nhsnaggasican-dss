<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Subject Teacher and Class Record" pass (2026-09-28) — WHO TEACHES a
 * subject in a section, per term. Separate from advisory responsibility
 * (sections.adviser_id): an adviser may teach subjects in any section,
 * and advising a section grants no score-editing right by itself.
 *
 * One row per (section, subject, term): one responsible teacher per
 * subject/section/term. `school_year` is stored like every academic table
 * (CLAUDE.md design decision 4); a section is itself year-specific, so a
 * row is historical by construction and a learner's promotion never
 * rewrites it.
 *
 * Additive: creates one table and backfills NOTHING. Assignments are not
 * guessed from who uploaded old scores — the Admin records them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('school_year', 9);
            $table->unsignedTinyInteger('grading_period');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['section_id', 'subject_id', 'grading_period'], 'unique_teacher_per_section_subject_term');
            $table->index(['user_id', 'school_year'], 'teaching_assignments_user_year_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_assignments');
    }
};
