<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Multi-school-year academic history" work order, PARTS 6, 11, 12 —
 * a risk result and an intervention must carry the section a learner
 * was in WHEN the record was made, not resolve it through
 * students.section_id at read time (which becomes the learner's Grade
 * 12 section the moment they are promoted, relabeling every Grade 11
 * record).
 *
 * risk_results already had school_year + grading_period; it gains
 * section_id. interventions had grading_period but neither school_year
 * nor section_id; it gains both. All nullable, all additive — an old row
 * whose context genuinely cannot be determined stays null rather than
 * being guessed.
 *
 * Backfill order, most trustworthy source first:
 *  - risk_results.section_id: the section on the grades this result was
 *    computed from (same student / school_year / grading_period); else
 *    the student's current section ONLY IF that section belongs to the
 *    same school_year as the result (so a stale result from an earlier
 *    year is never stamped with a later year's section).
 *  - interventions.school_year: the linked risk result's school_year;
 *    else the student's current section's school_year.
 *  - interventions.section_id: the linked risk result's section (after
 *    the step above); else the student's current section ONLY IF its
 *    school_year matches the intervention's resolved school_year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            $table->foreignId('section_id')
                ->nullable()
                ->after('student_id')
                ->constrained('sections')
                ->restrictOnDelete();
        });

        Schema::table('interventions', function (Blueprint $table) {
            $table->string('school_year')->nullable()->after('grading_period');
            $table->foreignId('section_id')
                ->nullable()
                ->after('school_year')
                ->constrained('sections')
                ->restrictOnDelete();
            $table->index(['school_year', 'grading_period'], 'interventions_year_period_index');
        });

        // --- risk_results.section_id -------------------------------------
        foreach (DB::table('risk_results')->whereNull('section_id')->get() as $risk) {
            $sectionId = DB::table('grades')
                ->where('student_id', $risk->student_id)
                ->where('school_year', $risk->school_year)
                ->where('grading_period', $risk->grading_period)
                ->value('section_id');

            if (!$sectionId) {
                $current = DB::table('students')
                    ->join('sections', 'sections.id', '=', 'students.section_id')
                    ->where('students.id', $risk->student_id)
                    ->select('sections.id', 'sections.school_year')
                    ->first();

                if ($current && $current->school_year === $risk->school_year) {
                    $sectionId = $current->id;
                }
            }

            if ($sectionId) {
                DB::table('risk_results')->where('id', $risk->id)->update(['section_id' => $sectionId]);
            }
        }

        // --- interventions.school_year / section_id ----------------------
        foreach (DB::table('interventions')->whereNull('school_year')->orWhereNull('section_id')->get() as $intervention) {
            $risk = $intervention->risk_result_id
                ? DB::table('risk_results')->where('id', $intervention->risk_result_id)->first()
                : null;

            $current = DB::table('students')
                ->join('sections', 'sections.id', '=', 'students.section_id')
                ->where('students.id', $intervention->student_id)
                ->select('sections.id', 'sections.school_year')
                ->first();

            $schoolYear = $intervention->school_year
                ?? $risk?->school_year
                ?? $current?->school_year;

            $sectionId = $intervention->section_id
                ?? $risk?->section_id
                ?? (($current && $schoolYear && $current->school_year === $schoolYear) ? $current->id : null);

            DB::table('interventions')->where('id', $intervention->id)->update([
                'school_year' => $schoolYear,
                'section_id'  => $sectionId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropIndex('interventions_year_period_index');
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn('school_year');
        });

        Schema::table('risk_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
