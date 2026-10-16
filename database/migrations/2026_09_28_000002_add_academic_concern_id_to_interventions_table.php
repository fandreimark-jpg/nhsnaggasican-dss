<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Rule-based academic monitoring" Phase 2E — an OPTIONAL link from an
 * intervention to the academic concern it was recorded from.
 *
 * Nullable: every existing intervention (and every intervention recorded
 * outside Academic Monitoring) stays valid with NULL — nothing is
 * backfilled. ON DELETE SET NULL: an intervention is history and must
 * survive if its concern is ever unavailable (concerns are never deleted
 * by the application, and their own foreign keys restrict deletion).
 *
 * Additive only; touches no existing value. down() drops the foreign key
 * and the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->foreignId('academic_concern_id')
                ->nullable()
                ->after('risk_result_id')
                ->constrained('academic_concerns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_concern_id');
        });
    }
};
