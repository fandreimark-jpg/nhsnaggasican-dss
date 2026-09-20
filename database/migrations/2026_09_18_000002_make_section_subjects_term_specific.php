<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Student identity and term-specific subject offerings" pass, PART 2 —
 * section_subjects becomes the SUBJECT OFFERING table: one row per
 * (section, subject, academic term). A subject stays master/curriculum
 * data in `subjects`; WHEN and WHERE it is taught is a row here.
 *
 *   SUBJECT MASTER + ACADEMIC YEAR + ACADEMIC TERM + SECTION = OFFERING
 *
 * The table name is kept for compatibility (every consumer already reads
 * it through Subject::forSection()/SectionElectiveStatus). It was created
 * in ECR alignment PART 6 keyed on (section, subject, school_year) with a
 * nullable starting_term/term_count pair describing which terms an
 * elective ran in; that pair is replaced by an explicit academic_term_id
 * foreign key, so "Term 1 offers General Mathematics and Term 2 does not"
 * is a fact the schema can hold directly. school_year is kept: it is the
 * join key every academic table in this codebase carries (CLAUDE.md,
 * design decision 4) and AcademicYear::hasDependentRecords() reads it.
 * No academic_year_id column is added — the year is unambiguous through
 * academic_terms.academic_year_id and through school_year itself.
 *
 * DATA MIGRATION — no row is deleted, and nothing about what any section
 * resolves to changes as a result of this migration alone:
 *
 *  1. Every pre-existing row is expanded into one row per term it
 *     covered, using the EXACT semantics SectionSubject::coversTerm()
 *     gave it: a null starting_term/term_count pair meant "every term",
 *     so it becomes three rows; (starting_term=2, term_count=2) becomes
 *     Term 2 + Term 3. The original row is reused for the first term.
 *  2. A section that had any pre-existing row was, under the old model,
 *     ALSO implicitly taking every core subject of its grade level (core
 *     subjects were never pivot rows — Subject::forSection() added them
 *     unconditionally). Under the new model a section with offering rows
 *     resolves ONLY its offering rows, so those core subjects are written
 *     as explicit rows for all three terms — otherwise the switch would
 *     silently drop them from that section. This is preserving what the
 *     system already resolved for that section, not inventing history.
 *  3. A section with NO pre-existing rows gets none here. It keeps
 *     resolving through the curriculum default (core subjects for its
 *     grade level, plus specialization electives for k12_2013) until an
 *     Admin assigns its subjects per term — see Subject::forSection() and
 *     SubjectOfferingService. Nothing is copied into terms from
 *     assessments/grades by this migration: which term a historical
 *     record belongs to is already on that record (grading_period), and
 *     SubjectOfferingService::syncFromAcademicHistory() records it as an
 *     offering at the moment an Admin first switches the section over,
 *     with the Admin in the loop — never silently here.
 *
 * On the live pilot database section_subjects had zero rows, so steps 1
 * and 2 are no-ops there.
 *
 * FOREIGN KEYS: section_id/subject_id were cascadeOnDelete; they become
 * restrictOnDelete. An offering is academic history the moment records
 * hang off it, and ProtectsAcademicHistory already refuses to delete a
 * section/subject that has offering rows at the model layer — the
 * database now agrees instead of quietly cascading on a raw delete.
 * academic_term_id is restrictOnDelete for the same reason (there is
 * deliberately no delete route for terms anyway).
 *
 * Existing migrations are not edited. Order of index operations follows
 * the MySQL rule this codebase already learned in PART 3a: the new unique
 * index (which keeps section_id leftmost, satisfying the section_id FK)
 * is created BEFORE the old one is dropped.
 */
return new class extends Migration
{
    private const NEW_UNIQUE = 'section_subjects_section_subject_term_unique';
    private const OLD_UNIQUE = 'section_subjects_section_id_subject_id_school_year_unique';

    /**
     * Every step is guarded so the migration can be re-run after a
     * partial failure — MySQL DDL is not transactional, so a step that
     * fails halfway must not leave the next `php artisan migrate` unable
     * to proceed.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('section_subjects', 'academic_term_id')) {
            Schema::table('section_subjects', function (Blueprint $table) {
                $table->unsignedBigInteger('academic_term_id')->nullable()->after('school_year');
            });
        }

        // Step 1a — point every pre-existing row at the FIRST term it
        // covered. Only an UPDATE here: the old (section, subject, year)
        // unique index is still in place, so the extra per-term rows
        // cannot be inserted until it has been replaced below.
        $pendingExpansions = $this->assignFirstTermToExistingRows();

        if (!Schema::hasIndex('section_subjects', self::NEW_UNIQUE)) {
            Schema::table('section_subjects', function (Blueprint $table) {
                $table->unique(['section_id', 'subject_id', 'academic_term_id'], self::NEW_UNIQUE);
            });
        }

        if (Schema::hasIndex('section_subjects', self::OLD_UNIQUE)) {
            Schema::table('section_subjects', function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        // Step 1b / step 2 — now that a subject may appear once per term.
        $this->insertRemainingTermRows($pendingExpansions);
        $this->recordImplicitCoreSubjectsForManagedSections();

        $remainingNull = DB::table('section_subjects')->whereNull('academic_term_id')->count();
        if ($remainingNull > 0) {
            throw new RuntimeException("section_subjects: {$remainingNull} row(s) could not be assigned an academic term — aborting before the column is made required.");
        }

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->unsignedBigInteger('academic_term_id')->nullable(false)->change();
        });

        if (!$this->hasForeignKeyOn('academic_term_id')) {
            Schema::table('section_subjects', function (Blueprint $table) {
                $table->foreign('academic_term_id')->references('id')->on('academic_terms')->restrictOnDelete();
            });
        }

        if (Schema::hasColumn('section_subjects', 'starting_term')) {
            Schema::table('section_subjects', function (Blueprint $table) {
                $table->dropColumn(['starting_term', 'term_count']);
            });
        }

        // cascade -> restrict on the two original foreign keys.
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropForeign(['subject_id']);
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->unsignedTinyInteger('starting_term')->nullable()->after('school_year');
            $table->unsignedTinyInteger('term_count')->nullable()->after('starting_term');
        });

        // Collapse per-term rows back to one row per (section, subject, year)
        // carrying the first term and a count — lossy only for
        // non-contiguous term sets, which the old model could not express.
        $groups = DB::table('section_subjects')
            ->join('academic_terms', 'academic_terms.id', '=', 'section_subjects.academic_term_id')
            ->select('section_subjects.id', 'section_subjects.section_id', 'section_subjects.subject_id', 'section_subjects.school_year', 'academic_terms.term')
            ->orderBy('academic_terms.term')
            ->get()
            ->groupBy(fn($row) => $row->section_id . '|' . $row->subject_id . '|' . $row->school_year);

        foreach ($groups as $rows) {
            $first = $rows->first();
            $terms = $rows->pluck('term')->map(fn($t) => (int) $t)->sort()->values();
            $everyTerm = $terms->count() === 3;

            DB::table('section_subjects')->where('id', $first->id)->update([
                'starting_term' => $everyTerm ? null : $terms->first(),
                'term_count'    => $everyTerm ? null : $terms->count(),
            ]);
            $otherIds = $rows->pluck('id')->reject(fn($id) => $id === $first->id)->all();
            if ($otherIds !== []) {
                DB::table('section_subjects')->whereIn('id', $otherIds)->delete();
            }
        }

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['academic_term_id']);
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->unique(['section_id', 'subject_id', 'school_year'], self::OLD_UNIQUE);
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropUnique(self::NEW_UNIQUE);
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropColumn('academic_term_id');
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->foreign('section_id')->references('id')->on('sections')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();
        });
    }

    /**
     * Step 1a of the class docblock. Returns the terms each row still
     * needs inserted for, keyed by the original row id.
     *
     * @return array<int, array{row: object, terms: int[]}>
     */
    private function assignFirstTermToExistingRows(): array
    {
        $pending = [];
        $rows = DB::table('section_subjects')->whereNull('academic_term_id')->orderBy('id')->get();

        foreach ($rows as $row) {
            $terms = $this->termsCoveredBy($row->starting_term ?? null, $row->term_count ?? null);
            $firstTerm = array_shift($terms);

            DB::table('section_subjects')->where('id', $row->id)->update([
                'academic_term_id' => $this->termId($row->school_year, $firstTerm),
            ]);

            if ($terms !== []) {
                $pending[$row->id] = ['row' => $row, 'terms' => $terms];
            }
        }

        return $pending;
    }

    /** Step 1b of the class docblock. */
    private function insertRemainingTermRows(array $pending): void
    {
        foreach ($pending as $entry) {
            $row = $entry['row'];
            foreach ($entry['terms'] as $term) {
                $termId = $this->termId($row->school_year, $term);
                $exists = DB::table('section_subjects')
                    ->where('section_id', $row->section_id)
                    ->where('subject_id', $row->subject_id)
                    ->where('academic_term_id', $termId)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('section_subjects')->insert([
                    'section_id'       => $row->section_id,
                    'subject_id'       => $row->subject_id,
                    'school_year'      => $row->school_year,
                    'academic_term_id' => $termId,
                    'created_at'       => $row->created_at,
                    'updated_at'       => now(),
                ]);
            }
        }
    }

    /** Step 2 of the class docblock. */
    private function recordImplicitCoreSubjectsForManagedSections(): void
    {
        $managed = DB::table('section_subjects')
            ->select('section_id', 'school_year')
            ->distinct()
            ->get();

        foreach ($managed as $entry) {
            $section = DB::table('sections')->where('id', $entry->section_id)->first();
            if (!$section) {
                continue;
            }

            $coreSubjectIds = DB::table('subjects')
                ->where('type', 'core')
                ->where('grade_level', $section->grade_level)
                ->pluck('id');

            foreach ($coreSubjectIds as $subjectId) {
                foreach ([1, 2, 3] as $term) {
                    $termId = $this->termId($entry->school_year, $term);
                    $exists = DB::table('section_subjects')
                        ->where('section_id', $entry->section_id)
                        ->where('subject_id', $subjectId)
                        ->where('academic_term_id', $termId)
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('section_subjects')->insert([
                        'section_id'       => $entry->section_id,
                        'subject_id'       => $subjectId,
                        'school_year'      => $entry->school_year,
                        'academic_term_id' => $termId,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                }
            }
        }
    }

    private function hasForeignKeyOn(string $column): bool
    {
        foreach (Schema::getForeignKeys('section_subjects') as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exactly SectionSubject::coversTerm()'s old rule: a null pair means
     * every term; otherwise the contiguous run starting at starting_term,
     * clipped to 1..3.
     *
     * @return int[]
     */
    private function termsCoveredBy($startingTerm, $termCount): array
    {
        if ($startingTerm === null || $termCount === null) {
            return [1, 2, 3];
        }

        $start = max(1, (int) $startingTerm);
        $end   = min(3, $start + (int) $termCount - 1);

        return $end >= $start ? range($start, $end) : [$start];
    }

    /**
     * The academic_terms row id for (school year, term). Rows normally
     * already exist (AcademicTerm::ensureExistFor() creates all three the
     * moment a year is touched); if one is missing it is created CLOSED —
     * a migration must not open a term, that is an Admin action.
     */
    private function termId(string $schoolYear, int $term): int
    {
        $id = DB::table('academic_terms')->where('school_year', $schoolYear)->where('term', $term)->value('id');
        if ($id) {
            return (int) $id;
        }

        $yearId = DB::table('academic_years')->where('school_year', $schoolYear)->value('id')
            ?? DB::table('academic_years')->insertGetId([
                'school_year' => $schoolYear, 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);

        return (int) DB::table('academic_terms')->insertGetId([
            'academic_year_id' => $yearId, 'school_year' => $schoolYear, 'term' => $term,
            'is_open' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
