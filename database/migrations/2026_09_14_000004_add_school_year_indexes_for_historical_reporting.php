<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Multi-school-year academic history" work order, PART 17 — the
 * historical Reports/Principal filters now query by school_year FIRST
 * (then term), across every year the school has run. The existing
 * indexes on these tables all lead with a student/section/subject id
 * (their unique keys), so a "this school year, this term" scan had no
 * index to use once more than one year exists. Plain secondary indexes
 * only — no uniqueness is added or changed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->index(['school_year', 'grading_period'], 'grades_year_period_index');
        });

        Schema::table('report_submissions', function (Blueprint $table) {
            $table->index(['school_year', 'grading_period'], 'report_submissions_year_period_index');
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->index(['school_year', 'grading_period'], 'assessments_year_period_index');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->index(['school_year', 'grade_level'], 'sections_year_grade_index');
            $table->index(['adviser_id', 'school_year'], 'sections_adviser_year_index');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex('sections_adviser_year_index');
            $table->dropIndex('sections_year_grade_index');
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropIndex('assessments_year_period_index');
        });

        Schema::table('report_submissions', function (Blueprint $table) {
            $table->dropIndex('report_submissions_year_period_index');
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->dropIndex('grades_year_period_index');
        });
    }
};
