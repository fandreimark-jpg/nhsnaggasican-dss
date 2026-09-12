<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SYSTEM_FIXES_AND_ML_AUDIT.md, "Track description" — nullable:
        // every existing track has none yet, and school-provided track
        // data (manual create, TracksImport) may not always supply one.
        Schema::table('tracks', function (Blueprint $table) {
            $table->text('description')->nullable()->after('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
