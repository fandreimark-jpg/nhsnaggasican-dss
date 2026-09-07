<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 3 of "close the delivery loop" needs to know which TERM an
 * intervention's within-term comparison is scoped to — but `interventions`
 * has never had a grading_period column (see
 * Principal\InterventionController::store()'s docblock from an earlier
 * task: "term-level precision isn't something the schema can answer...
 * subject-level is the finest granularity that's actually determinable").
 * That gap is exactly what blocks compareWithinTerm() from being
 * well-defined, so this adds the column the earlier task deliberately
 * deferred. Nullable and purely additive — existing rows (recorded
 * before this migration) simply have no within-term comparison available,
 * same as they'd have no comparison for any other missing field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->unsignedTinyInteger('grading_period')->nullable()->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('grading_period');
        });
    }
};
