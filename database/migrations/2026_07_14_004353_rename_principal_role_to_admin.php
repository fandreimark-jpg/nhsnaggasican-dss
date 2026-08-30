<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the 'principal' role to 'admin' throughout the database.
 *
 * Done in 3 steps because MySQL enum columns can't just have a value
 * swapped out directly — if we removed 'principal' from the allowed
 * list while existing rows still had 'principal' saved, those rows
 * would break. So:
 *   1. Temporarily allow BOTH 'principal' and 'admin' as valid values.
 *   2. Update every existing row that says 'principal' to say 'admin'.
 *   3. Now that no row uses 'principal' anymore, remove it from the
 *      allowed list for good.
 *
 * The raw ENUM ALTER TABLE syntax is MySQL-only. SQLite (used for the
 * automated test suite) has no ENUM type — the original create_users_table
 * migration's enum() compiles there as a plain string column with a CHECK
 * constraint — so on non-MySQL connections this rebuilds the column as a
 * plain string instead, migrating the data the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Step 1: widen the enum temporarily to accept both old and new values
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('adviser','principal','admin') NOT NULL");

            // Step 2: migrate existing data
            DB::table('users')->where('role', 'principal')->update(['role' => 'admin']);

            // Step 3: narrow the enum back down — 'principal' is no longer valid
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('adviser','admin') NOT NULL");
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role_tmp')->nullable()->after('role');
        });

        DB::table('users')->update([
            'role_tmp' => DB::raw("CASE WHEN role = 'principal' THEN 'admin' ELSE role END"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('role_tmp', 'role');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Reverse the same 3 steps, in case this migration ever needs to be rolled back
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('adviser','admin','principal') NOT NULL");
            DB::table('users')->where('role', 'admin')->update(['role' => 'principal']);
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('adviser','principal') NOT NULL");
            return;
        }

        // Note: the reverse mapping (admin -> principal) is inherently lossy
        // on non-MySQL drivers, since 'admin' rows could have originally been
        // either 'admin' or 'principal'. Not a concern for the test suite,
        // which always starts from a fresh in-memory database.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_tmp')->nullable()->after('role');
        });

        DB::table('users')->update([
            'role_tmp' => DB::raw("CASE WHEN role = 'admin' THEN 'principal' ELSE role END"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('role_tmp', 'role');
        });
    }
};
