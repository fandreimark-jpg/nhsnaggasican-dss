<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Subject Teacher and Class Record" pass (2026-09-28) — widens
 * users.role to allow 'subject_teacher' alongside 'adviser', 'admin' and
 * 'principal'. Additive: no existing row's role is read or changed, and
 * no account is created. Same MySQL-enum handling as
 * 2026_08_30_000003_widen_role_to_include_principal; on SQLite (tests)
 * that earlier migration already rebuilt `role` as a plain string, so
 * there is nothing to widen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('adviser','admin','principal','subject_teacher') NOT NULL");
        }
    }

    public function down(): void
    {
        // Narrowing would break real subject_teacher accounts — refuse
        // rather than silently corrupt or delete them.
        if (DB::table('users')->where('role', 'subject_teacher')->exists()) {
            throw new \RuntimeException('Cannot roll back: subject_teacher accounts exist. Reassign their role first.');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('adviser','admin','principal') NOT NULL");
        }
    }
};
