<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * "Master pass" PART 1 — the Adviser's "Awaiting the Principal's decision"
 * panel told every learner the DSS had recommended them, which was untrue
 * for the six interventions the Principal recorded directly through the
 * bulk dialog — their own reason text says "Recorded in bulk". `origin`
 * records WHO actually created the record: 'principal' (the only value
 * ever set today — every intervention in this system is created by a
 * Principal through the interface) or 'system', reserved for a future
 * automatic generator that does not exist yet — see
 * Intervention::isSystemGenerated() and the "do not create any code path
 * that sets origin => 'system'" constraint. Every existing row is
 * backfilled to 'principal' here since that is simply true of all of
 * them, not a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('created_by');
        });

        DB::table('interventions')->update(['origin' => 'principal']);
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
