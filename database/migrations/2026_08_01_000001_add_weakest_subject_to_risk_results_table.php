<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            // The specific subject this student is struggling with the most —
            // turns a generic "Moderate Risk" tag into an actionable focus area
            // for the principal/adviser, without needing to retrain the model.
            $table->string('weakest_subject')->nullable()->after('risk_level');
            $table->decimal('weakest_subject_grade', 5, 2)->nullable()->after('weakest_subject');
        });
    }

    public function down(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            $table->dropColumn(['weakest_subject', 'weakest_subject_grade']);
        });
    }
};