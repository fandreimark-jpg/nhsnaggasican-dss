<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Close the delivery loop" (Task 2): acknowledging that a decision was
 * SEEN (2026_09_04_000001) still left no record of it actually being
 * CARRIED OUT — the extra performance task, remedial session, or parent
 * conference the Principal decided on. delivered_at/delivered_by/
 * delivery_notes is that record — a record of action taken, not a
 * decision (see Adviser\InterventionController::markDelivered() — the
 * adviser still cannot touch status/recommended_type/principal_notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('acknowledged_by');
            $table->foreignId('delivered_by')->nullable()->after('delivered_at')
                  ->constrained('users')->onDelete('set null');
            $table->text('delivery_notes')->nullable()->after('delivered_by');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropForeign(['delivered_by']);
            $table->dropColumn(['delivered_at', 'delivered_by', 'delivery_notes']);
        });
    }
};
