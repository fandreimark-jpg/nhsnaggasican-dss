<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ECR alignment" work order, PART 6 — mechanism only. Records exactly
 * which elective subjects a section takes, and for how many terms,
 * replacing "every elective matching the section's track" (see
 * CLAUDE.md, "Elective selection is per-cluster, not per-learner") for
 * curriculum = 'sshs' sections. k12_2013 sections are untouched — their
 * existing specialization-based elective match is not broken and this
 * table is never consulted for them (see Subject::forSection()).
 *
 * Ships EMPTY. Zero elective subjects exist in this database today, so
 * there is nothing to assign and nothing to seed — populating this table
 * is a data-entry problem for when the school's real roster and elective
 * choices arrive (blocked on Q1: does every learner in a section take the
 * same electives, or does each learner choose? — see
 * ECR_ALIGNMENT_WORK_ORDER.md Part 6), not a code problem to solve now.
 *
 * starting_term/term_count (both nullable, both 1-3) rather than a single
 * "runs for N terms" count: the work order's own text says the ECR
 * "refuses to let a two-term elective begin in Term 3", implying a
 * 2-term elective can legitimately start in Term 1 OR Term 2 for a given
 * section — assuming it always starts at Term 1 (or always ends at Term
 * 3) would be exactly the kind of invented structure this part is told
 * not to build. Both stay nullable because a pivot row can exist (a
 * subject IS assigned to this section) before anyone has recorded which
 * terms it runs in; SectionElectiveStatus::expectedSubjectsForTerm()
 * treats a null pair as "expected every term" — the same assumption
 * every consumer made before this table existed, not a new default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('school_year');
            $table->unsignedTinyInteger('starting_term')->nullable();
            $table->unsignedTinyInteger('term_count')->nullable();
            $table->timestamps();

            $table->unique(['section_id', 'subject_id', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_subjects');
    }
};
