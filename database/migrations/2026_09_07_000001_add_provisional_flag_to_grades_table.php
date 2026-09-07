<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 1 of "unblock verification" — a grade verified while its real
 * transmutation scheme has no matching band (see config('dss.
 * transmutation_fallback_scheme')) is computed from ANOTHER scheme's
 * bands instead and must stay findable: `is_provisional` marks it,
 * `provisional_scheme` records which scheme's bands actually produced
 * `grade` (never the same as the subject's real scheme — see
 * TransmutationService::resolve()). Both nullable/default-false so every
 * existing row is valid as-is. `dss:recompute-grades` clears both once
 * the real scheme's bands are seeded and a recompute lands on a real
 * match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->boolean('is_provisional')->default(false)->after('verified_at');
            $table->string('provisional_scheme')->nullable()->after('is_provisional');
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropColumn(['is_provisional', 'provisional_scheme']);
        });
    }
};
