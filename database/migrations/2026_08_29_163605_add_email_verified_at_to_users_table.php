<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATCH-UP MIGRATION — see the note in
 * 2026_08_29_162447_create_assessments_table for why this exists.
 *
 * Adds the standard nullable email_verified_at column. Note: this app's
 * User model does NOT implement MustVerifyEmail and nothing currently
 * sets this column (accounts are admin-created, not self-registered —
 * see AuthenticationTest) — it exists in the live DB from the same
 * exploratory session as the assessment tables, and is captured here
 * only to keep the schema reproducible. It is NOT wired into any
 * verification flow by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
