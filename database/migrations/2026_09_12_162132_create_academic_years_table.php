<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * SYSTEM_FIXES_AND_ML_AUDIT.md, "Remove Hardcoded Academic Year" —
     * an explicit, admin-configurable "which school year is active" flag,
     * so activating a new school year no longer depends on a section
     * having already been created in it (Section::activeSchoolYear()'s
     * existing insertion-order inference, kept as the fallback for
     * backward compatibility — see that method).
     *
     * Deliberately NOT given an academic_term FK: academic_term rows are
     * already joined to a school year by the same school_year STRING
     * every other table in this codebase uses (sections.school_year,
     * grades.school_year, risk_results.school_year, etc.) — adding a
     * second, parallel join key here would be the "two ways to ask the
     * same question" pattern this codebase's own CLAUDE.md repeatedly
     * flags as a source of silent drift, not a safety improvement.
     */
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('school_year')->unique(); // e.g. '2026-2027'
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
