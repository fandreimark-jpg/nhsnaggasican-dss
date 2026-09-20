<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Student identity and term-specific subject offerings" pass, PART 1 —
 * birthdate is no longer part of the learner record. The required
 * learner format is lrn / last_name / first_name / middle_name / gender,
 * and nothing in the application reads, validates, imports, exports, or
 * displays a birthdate any more (every reference was removed in the same
 * pass — Student model, both StudentControllers, StudentsImport,
 * EcrReaderService's draft-roster export, the two Students views, and
 * modal.js).
 *
 * Only this one column is touched. Every other students column, every
 * students row, and every academic table that references students
 * (grades, assessment_scores, risk_results, interventions,
 * student_enrollments) is left exactly as it was — dropping a column
 * never deletes rows. The original create_students_table migration is
 * NOT edited (it has already run everywhere); this is a new migration,
 * guarded so it is a no-op on any database where the column is already
 * gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('students', 'birthdate')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('birthdate');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('students', 'birthdate')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            // Restored as nullable, matching the original definition — the
            // values themselves are not recoverable by a rollback.
            $table->date('birthdate')->nullable()->after('gender');
        });
    }
};
