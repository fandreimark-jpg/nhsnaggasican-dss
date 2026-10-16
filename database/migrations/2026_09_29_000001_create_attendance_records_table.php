<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Term-level attendance evidence, recorded by the section's adviser.
 *
 * One row per learner per school year per term. The day counts are the
 * figures the adviser already keeps on the school forms (days of school,
 * present, absent, tardy) and are all NULLABLE: a count nobody entered is
 * "not recorded", never 0 — the same "blank is not zero" rule the
 * assessment evidence follows.
 *
 * concern_flagged is the adviser's EXPLICIT statement that the learner's
 * attendance is a concern. It is the only thing the Attendance Concern
 * monitoring rule reads: there is no absence count, percentage or other
 * numeric threshold anywhere, because the school has approved none.
 *
 * Attendance is never part of a grade. GradingEngine does not read this
 * table.
 *
 * Additive only: creates one new table, touches no existing row.
 * down() drops only this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            // Restrict, like the other academic history: a learner or
            // section with attendance evidence is not silently erased.
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();
            $table->string('school_year', 9);
            $table->unsignedTinyInteger('grading_period');

            $table->unsignedSmallInteger('school_days')->nullable();
            $table->unsignedSmallInteger('days_present')->nullable();
            $table->unsignedSmallInteger('days_absent')->nullable();
            $table->unsignedSmallInteger('days_tardy')->nullable();

            $table->boolean('concern_flagged')->default(false);
            $table->text('concern_note')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'school_year', 'grading_period'], 'unique_attendance_per_learner_term');
            $table->index(['section_id', 'school_year', 'grading_period'], 'attendance_section_term_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
