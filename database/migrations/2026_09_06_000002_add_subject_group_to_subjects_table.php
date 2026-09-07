<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which subject_group_weights row applies to this subject (see that
 * table's migration). Nullable, but defaulted to 'core_academic' — MySQL
 * applies a column default to every existing row the moment it's added,
 * so this single migration both backfills the subjects already in the
 * database and covers any future INSERT that doesn't specify one (a
 * factory, an older import file with no subject_group column at all —
 * see SubjectsImport). 'core_academic' is the safest default: it is
 * DO 015, s. 2026's most common subject group and the one every existing
 * seeded/imported subject in this codebase actually is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('subject_group')->nullable()->default('core_academic')->after('grade_level');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('subject_group');
        });
    }
};
