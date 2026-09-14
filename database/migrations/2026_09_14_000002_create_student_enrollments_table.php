<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Multi-school-year academic history" work order, PART 5 — student
 * IDENTITY (students: lrn, names, gender, birthdate) is separated from
 * student ENROLLMENT (this table: which section, grade level, and
 * curriculum a learner was in for one school year).
 *
 * Before this table, `students.section_id` was the ONLY record of where
 * a learner belonged, and it was mutable — moving a learner from Grade 11
 * Narra (2026-2027) to Grade 12 Agila (2027-2028) would silently erase
 * the fact that they were ever in Narra, and every historical screen
 * that resolved "section" through students.section_id would relabel the
 * old year's records with the new section.
 *
 * `students.section_id` is deliberately KEPT and still means "the
 * learner's current section" — dozens of call sites read it, and for the
 * active school year it always agrees with this table's row for that
 * year (StudentEnrollmentService keeps the two in sync). Historical
 * consumers read this table instead: Student::enrollmentFor($schoolYear),
 * Section::enrolledStudents().
 *
 * Backfill: exactly one row per student that currently has a section,
 * taken from that section's own school_year/grade_level/curriculum —
 * nothing is invented; a learner with no section gets no enrollment row.
 * One enrollment per learner per school year (unique) — a learner can be
 * in ONE section per year; a mid-year correction updates that row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->restrictOnDelete();
            $table->string('school_year');
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();
            $table->unsignedTinyInteger('grade_level');
            $table->string('curriculum')->nullable();
            $table->string('status')->default('enrolled'); // enrolled | completed | transferred | dropped
            $table->date('enrolled_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'school_year'], 'student_enrollments_student_year_unique');
            $table->index(['section_id', 'school_year'], 'student_enrollments_section_year_index');
            $table->index(['school_year', 'grade_level'], 'student_enrollments_year_grade_index');
        });

        $now = now();

        $rows = DB::table('students')
            ->join('sections', 'sections.id', '=', 'students.section_id')
            ->whereNotNull('students.section_id')
            ->select(
                'students.id as student_id',
                'students.section_id',
                'students.created_at as student_created_at',
                'sections.school_year',
                'sections.grade_level',
                'sections.curriculum'
            )
            ->orderBy('students.id')
            ->get();

        $yearIds = [];

        foreach ($rows as $row) {
            if (!array_key_exists($row->school_year, $yearIds)) {
                $yearId = DB::table('academic_years')->where('school_year', $row->school_year)->value('id');

                if (!$yearId) {
                    $yearId = DB::table('academic_years')->insertGetId([
                        'school_year' => $row->school_year,
                        'is_active'   => false,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]);
                }

                $yearIds[$row->school_year] = $yearId;
            }

            DB::table('student_enrollments')->insert([
                'student_id'       => $row->student_id,
                'academic_year_id' => $yearIds[$row->school_year],
                'school_year'      => $row->school_year,
                'section_id'       => $row->section_id,
                'grade_level'      => $row->grade_level,
                'curriculum'       => $row->curriculum,
                'status'           => 'enrolled',
                'enrolled_at'      => $row->student_created_at ? substr((string) $row->student_created_at, 0, 10) : null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};
