<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * grades is queried heavily by (section_id, grading_period, school_year)
 * together — GradeController::index, ReportController::show/submit, and
 * AcademicTerm::completionStatus all filter on exactly this triple, but
 * only student_id/subject_id-leading indexes existed (via the FK and the
 * unique constraint). risk_results is now filtered by school_year first
 * (see the Admin dashboard's latestPerStudent scoping fix), which the
 * existing (student_id, grading_period, school_year) unique index can't
 * serve as a leftmost prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->index(['section_id', 'grading_period', 'school_year'], 'grades_section_period_year_index');
        });

        Schema::table('risk_results', function (Blueprint $table) {
            $table->index(['school_year', 'student_id'], 'risk_results_year_student_index');
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropIndex('grades_section_period_year_index');
        });

        Schema::table('risk_results', function (Blueprint $table) {
            $table->dropIndex('risk_results_year_student_index');
        });
    }
};
