<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * risk_results.weakest_subject is just a display string (the subject
 * NAME at the time the report was submitted) — there's no way to look up
 * assessment-component evidence for it without a real foreign key. This
 * adds one, purely additively, so the DSS can be extended (P-8) to show
 * WHICH COMPONENT within that subject is dragging it down, reusing
 * PerformanceAnalysisService instead of building a parallel DSS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            $table->foreignId('weakest_subject_id')->nullable()->after('weakest_subject')
                  ->constrained('subjects')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            $table->dropForeign(['weakest_subject_id']);
            $table->dropColumn('weakest_subject_id');
        });
    }
};
