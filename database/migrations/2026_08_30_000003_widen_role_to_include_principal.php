<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reinstates 'principal' as a valid users.role value, alongside the
 * existing 'adviser' and 'admin' (a prior migration,
 * rename_principal_role_to_admin, deliberately merged Principal into Admin
 * and already ran against the real dev DB — this does NOT reverse that
 * data migration or touch any existing row's role; it only widens the
 * allowed set so NEW principal accounts can be created going forward).
 *
 * Same MySQL-enum-vs-SQLite-CHECK-constraint handling as that prior
 * migration: MySQL enum columns require the raw ALTER TABLE syntax;
 * SQLite (used for tests) has no ENUM type, so the string+CHECK column
 * just needs rebuilding — no data migration needed here since we're only
 * ADDING an allowed value, not removing or renaming one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('adviser','admin','principal') NOT NULL");
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role_tmp')->nullable()->after('role');
        });

        DB::table('users')->update(['role_tmp' => DB::raw('role')]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('role_tmp', 'role');
        });
    }

    public function down(): void
    {
        // Narrowing back to 2 roles would break any 'principal' rows
        // created in the meantime — refuse rather than silently corrupt
        // or delete real accounts. Reassign them manually first if this
        // rollback is genuinely needed.
        if (DB::table('users')->where('role', 'principal')->exists()) {
            throw new \RuntimeException(
                'Cannot roll back: principal accounts exist. Reassign their role first.'
            );
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('adviser','admin') NOT NULL");
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role_tmp')->nullable()->after('role');
        });

        DB::table('users')->update(['role_tmp' => DB::raw('role')]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('role_tmp', 'role');
        });
    }
};
