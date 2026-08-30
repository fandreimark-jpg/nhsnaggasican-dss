<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            // weakest_subject only ever showed ONE subject (the lowest grade).
            // A student can fail 2+ subjects at once — the adviser/principal
            // needs to see all of them, not just the worst one, to know
            // exactly where to intervene.
            //
            // Stored as JSON: [{"name": "Filipino", "grade": 70.00}, ...]
            $table->text('failing_subjects')->nullable()->after('weakest_subject_grade');
        });
    }

    public function down(): void
    {
        Schema::table('risk_results', function (Blueprint $table) {
            $table->dropColumn('failing_subjects');
        });
    }
};