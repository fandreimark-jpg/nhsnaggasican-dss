<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Subject classification and grading weights cleanup" pass.
 *
 * `subjects.subject_group` carried a DB-level DEFAULT 'core_academic' (see
 * 2026_09_06_000002_add_subject_group_to_subjects_table.php) so that any
 * INSERT omitting the column — a factory, an older import file — silently
 * got Core Academic. That silent default is exactly what let an elective
 * end up classified as `core_academic` with nothing in the code path ever
 * deciding it belonged there (see the "academic_other" migration's
 * docblock for the concrete case).
 *
 * The application layer no longer relies on this: SubjectsImport and
 * Admin\SubjectController both now require an explicit, type-consistent
 * subject_group for a Grade 11 subject (SubjectGroupWeight::
 * classificationError()) and force it to null for a Grade 12 subject
 * (subject_group has no meaning there — see GradingEngine::
 * resolveDo8GroupKey()). Dropping the column default here means an
 * unclassified subject stays unclassified — visibly null — rather than
 * silently becoming Core Academic the moment something forgets to set it.
 *
 * The column itself stays nullable and untouched otherwise; only the
 * DEFAULT clause is removed. SQLite (every test environment) never
 * actually applies a column default unless a row omits the column AND
 * the schema is created fresh with one — Schema::create() in this
 * codebase's migrations never relies on it being present, so this
 * migration is a MySQL-only statement and a no-op on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE subjects ALTER COLUMN subject_group DROP DEFAULT');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE subjects ALTER COLUMN subject_group SET DEFAULT 'core_academic'");
        }
    }
};
