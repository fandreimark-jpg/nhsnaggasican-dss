<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Pre-demo full-system audit" (2026-09-20), Phase 29 — two invariants that
 * were enforced only by form validation get a database constraint:
 *
 *   subjects (name, grade_level)            — Admin\SubjectController's
 *                                             Rule::unique, now also the DB's
 *   sections (name, grade_level, school_year) — no rule existed anywhere; two
 *                                             "Curie" sections in one year
 *                                             were accepted
 *
 * Additive and forward-only: it adds two unique indexes and touches no row.
 * It REFUSES to run if the live data already violates either invariant, so
 * it can never fail half-way or hide a duplicate — the duplicates are named
 * for a human to resolve first. Rollback drops the two indexes only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dupSubjects = DB::table('subjects')
            ->selectRaw('LOWER(name) as n, grade_level as g, COUNT(*) as c')
            ->groupBy('n', 'g')->having('c', '>', 1)->get();
        $dupSections = DB::table('sections')
            ->selectRaw('LOWER(name) as n, grade_level as g, school_year as y, COUNT(*) as c')
            ->groupBy('n', 'g', 'y')->having('c', '>', 1)->get();

        if ($dupSubjects->isNotEmpty() || $dupSections->isNotEmpty()) {
            throw new RuntimeException(
                'Refusing to add unique indexes: duplicates exist. Subjects: '
                . $dupSubjects->map(fn($d) => "{$d->n} (Grade {$d->g}) x{$d->c}")->implode(', ')
                . ' | Sections: '
                . $dupSections->map(fn($d) => "{$d->n} (Grade {$d->g}, {$d->y}) x{$d->c}")->implode(', ')
                . '. Resolve them in Admin, then re-run.'
            );
        }

        if (!$this->hasIndex('subjects', 'subjects_name_grade_level_unique')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->unique(['name', 'grade_level'], 'subjects_name_grade_level_unique');
            });
        }

        if (!$this->hasIndex('sections', 'sections_name_grade_level_school_year_unique')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->unique(['name', 'grade_level', 'school_year'], 'sections_name_grade_level_school_year_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('subjects', 'subjects_name_grade_level_unique')) {
            Schema::table('subjects', fn(Blueprint $table) => $table->dropUnique('subjects_name_grade_level_unique'));
        }
        if ($this->hasIndex('sections', 'sections_name_grade_level_school_year_unique')) {
            Schema::table('sections', fn(Blueprint $table) => $table->dropUnique('sections_name_grade_level_school_year_unique'));
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn($i) => $i['name'] === $index);
    }
};
