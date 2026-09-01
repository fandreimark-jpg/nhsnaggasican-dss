<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds account status ('is_active', following the boolean convention
 * already used by academic_terms.is_open — not a new 'status' string, to
 * avoid a second competing status system) plus a DB-level mechanism that
 * makes "only one active Admin" and "only one active Principal" an actual
 * database constraint, not just an application-level check-then-create.
 *
 * 'role_singleton_key' is a nullable column the User model keeps in sync
 * with (role, is_active) on every save (see User::boot()): it holds the
 * role name ('admin' or 'principal') only when that row is BOTH that role
 * AND active; otherwise it's null. A UNIQUE index on it then means the
 * database itself refuses a second simultaneously-active admin or
 * principal row — including under concurrent requests, since the second
 * INSERT/UPDATE fails atomically at the DB engine level regardless of any
 * race in the pre-check. Advisers always compute to null, and a unique
 * index treats multiple NULLs as distinct (standard SQL, both MySQL and
 * SQLite — no driver-specific generated-column syntax needed), so any
 * number of advisers (active or not) is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('role_singleton_key')->nullable()->unique()->after('is_active');
        });

        // Backfill existing rows. Every pre-existing row just got
        // is_active = true from the column default, so this only needs to
        // set role_singleton_key for existing admin/principal rows — and
        // defensively resolve it if this particular database somehow
        // already has more than one active admin or active principal
        // (would otherwise make the unique index below impossible to add):
        // keep the earliest-created (lowest id) row active, deactivate the
        // rest rather than delete them.
        foreach (['admin', 'principal'] as $role) {
            $activeIds = DB::table('users')
                ->where('role', $role)
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id');

            if ($activeIds->isEmpty()) {
                continue;
            }

            $keepId = $activeIds->first();

            DB::table('users')->where('id', $keepId)->update(['role_singleton_key' => $role]);

            if ($activeIds->count() > 1) {
                DB::table('users')
                    ->whereIn('id', $activeIds->slice(1))
                    ->update(['is_active' => false, 'role_singleton_key' => null]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'role_singleton_key']);
        });
    }
};
