<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Close the intervention loop" (Task 1): the adviser who actually
 * delivers a Principal's decision had no way to see it existed, let
 * alone confirm they'd seen it. acknowledged_at/acknowledged_by is a
 * RECEIPT, not a decision — the adviser may not change status,
 * recommended_type, or principal_notes (see Adviser\InterventionController
 * — only the Principal moves an intervention through its lifecycle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('decided_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')
                  ->constrained('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropForeign(['acknowledged_by']);
            $table->dropColumn(['acknowledged_by', 'acknowledged_at']);
        });
    }
};
