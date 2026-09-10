<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "ECR alignment" work order, PART 3a — this column, not the grade level,
 * becomes what selects the grading scheme (see TransmutationService::
 * schemeFor()'s new optional $curriculum parameter). Nullable: a section
 * with no curriculum set falls back to schemeFor()'s existing grade-level
 * inference, unchanged -- see that method for why this is safe rather than
 * a silent wrong answer.
 *
 * Backfilled for every EXISTING section using the exact same inference
 * schemeFor() already applies today, so this migration provably changes
 * nothing about any already-computed grade: grade_level 11 in a school
 * year starting 2026 or later -> sshs; everything else -> k12_2013.
 *
 * Sections created by a future migration/import (Part 7 -- loading the
 * client's real Shakespeare/Curie/ABM/HUMSS/STEM sections) must set this
 * column explicitly rather than relying on this same inference by luck --
 * see ECR_ALIGNMENT_WORK_ORDER.md Part 7's note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('curriculum')->nullable()->after('grade_level');
        });

        foreach (DB::table('sections')->select('id', 'grade_level', 'school_year')->get() as $section) {
            $startYear = (int) substr($section->school_year, 0, 4);
            $curriculum = ($section->grade_level == 11 && $startYear >= 2026) ? 'sshs' : 'k12_2013';

            DB::table('sections')->where('id', $section->id)->update(['curriculum' => $curriculum]);
        }
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('curriculum');
        });
    }
};
