<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            // True when the failing-subject rule changed the ML model's
            // original classification (e.g. ML said "low" but the student
            // failed a subject, so the rule bumped it to "moderate"/"high").
            // Lets the adviser/admin see WHY a result looks the way it does —
            // "the model said X, but the rule overrode it" vs. "the model
            // said X and nothing overrode it" — instead of one flat label.
            $table->boolean('was_overridden')->default(false)->after('risk_level');

            // What the ML model originally said, before any override.
            // Kept alongside the final risk_level so the override is
            // auditable — you can always see both the "before" and "after".
            $table->string('ml_risk_level')->nullable()->after('was_overridden');
        });
    }

    public function down(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            $table->dropColumn(['was_overridden', 'ml_risk_level']);
        });
    }
};