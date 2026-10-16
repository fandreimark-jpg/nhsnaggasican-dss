<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Subject Teacher and Class Record" pass (2026-09-28) — the date an
 * assessment was given, shown on the full-page Add/Edit Assessment form
 * and the Class Record. NULLABLE and never backfilled: no existing
 * requirement makes it mandatory, and no date exists to copy for the
 * items already recorded (created_at is when the row was typed in, not
 * when the learners sat the item). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('assessments', 'assessment_date')) {
            Schema::table('assessments', function (Blueprint $table) {
                $table->date('assessment_date')->nullable()->after('max_score');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assessments', 'assessment_date')) {
            Schema::table('assessments', function (Blueprint $table) {
                $table->dropColumn('assessment_date');
            });
        }
    }
};
