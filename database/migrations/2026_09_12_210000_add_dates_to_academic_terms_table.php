<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic term editing — academic_terms previously had no
 * date fields at all (only school_year, term, is_open, opened_at,
 * closed_at — see that table's own migration). Admin > Academic Terms'
 * new Edit action lets an Admin record/correct a term's actual start/end
 * dates; both nullable, matching academic_years' own start_date/end_date
 * (also optional there) — a term with no dates recorded yet behaves
 * exactly as before this migration.
 *
 * Deliberately NOT touching `term` (1/2/3, the fixed grading-period
 * identity every Grade/Assessment/RiskResult row keys against) or
 * `school_year` — those stay immutable via this feature; see
 * AcademicTermController::update().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('term');
            $table->date('end_date')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date']);
        });
    }
};
