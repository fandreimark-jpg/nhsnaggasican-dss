<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Rule-based academic monitoring" Phase 2E — an optional Principal review
 * note beside the existing reviewed_by / reviewed_at.
 *
 * Nullable TEXT, no default: a review never requires a note, and every
 * existing concern stays valid (NULL = no note). TEXT has no implicit
 * default on any MySQL/MariaDB version (the 2B TIMESTAMP lesson does not
 * apply) and maps to TEXT on SQLite.
 *
 * Additive only; touches no existing value. down() drops only this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_concerns', function (Blueprint $table) {
            $table->text('review_note')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('academic_concerns', function (Blueprint $table) {
            $table->dropColumn('review_note');
        });
    }
};
