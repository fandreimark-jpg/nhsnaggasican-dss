<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Clarity, progress, and visual design pass" TASK 3c — a genuine group
 * delivery (one activity delivered to several learners at once) is now
 * allowed alongside individual delivery — see Adviser\InterventionController::
 * markDeliveredGroup(). delivery_mode records which kind THIS delivery
 * was; delivery_group_id ties every intervention delivered in the same
 * act together so the UI can say "delivered as a group activity (N
 * learners)" instead of presenting a shared note as if it were written
 * for one child alone. Both are nullable-safe no-ops for every existing
 * row: default 'individual' with a null group id describes every
 * delivery recorded before this migration exactly as it actually
 * happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('delivery_mode', 20)->default('individual')->after('delivery_notes');
            $table->string('delivery_group_id', 36)->nullable()->after('delivery_mode');
            $table->index('delivery_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropIndex(['delivery_group_id']);
            $table->dropColumn(['delivery_mode', 'delivery_group_id']);
        });
    }
};
