<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Multi-school-year academic history" work order, PART 3 — an academic
 * term must belong to a specific Academic Year record, not merely carry
 * the year's label. `academic_terms.school_year` (the string join key
 * every Grade/Assessment/RiskResult/ReportSubmission row also carries)
 * is kept exactly as it was — it is what every existing query reads —
 * and `academic_year_id` is ADDED alongside it, nullable, so the
 * relationship exists without rewriting any consumer.
 *
 * Backfill: for every distinct school_year already present in
 * academic_terms, an academic_years row is created if one doesn't exist
 * (inactive — activation is always an explicit Admin action, never a
 * migration side effect), then every term row is linked to its year.
 * No term row is renamed, re-numbered, opened, or closed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('id')
                ->constrained('academic_years')
                ->restrictOnDelete();
        });

        $now = now();

        foreach (DB::table('academic_terms')->distinct()->pluck('school_year') as $schoolYear) {
            $yearId = DB::table('academic_years')->where('school_year', $schoolYear)->value('id');

            if (!$yearId) {
                $yearId = DB::table('academic_years')->insertGetId([
                    'school_year' => $schoolYear,
                    'is_active'   => false,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }

            DB::table('academic_terms')
                ->where('school_year', $schoolYear)
                ->whereNull('academic_year_id')
                ->update(['academic_year_id' => $yearId]);
        }
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};
