<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Subject applicability" refactor (2026-09-20) — TERMS TAUGHT.
 *
 * A subject is master data valid across school years (Admin > Subjects is
 * not year-scoped), and every academic table keys its term as the integer
 * `grading_period` 1..3 — so a subject's terms are stored the same way:
 * one row per (subject, term number). The set of valid term numbers is
 * whatever `academic_terms.term` holds (see AcademicTerm::termNumbers()),
 * not a constant baked into this table.
 *
 * A year-specific FK to academic_terms was considered and rejected: it
 * would make every subject's configuration expire each school year and
 * have to be re-entered — the repetitive per-term work this refactor
 * removes from the Sections page, moved one screen over.
 *
 * BACKFILL — NOTHING INVENTED. Before this table existed, every subject
 * resolved to a section in EVERY term (Subject::forSection() ignored the
 * term on the curriculum-default path, and section_subjects was empty on
 * the live database). Writing rows for every existing term number per
 * subject therefore preserves exactly what each subject resolves to
 * today. On the live database this also matches DepEd's catalog, which
 * lists all three existing subjects as 3-term subjects. A subject that
 * should run in fewer terms is narrowed by the Admin on Admin > Subjects,
 * which is now the one place that decision is made.
 *
 * Forward-only and non-destructive: creates one table, touches no
 * existing row. Rollback drops only the new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subject_terms')) {
            Schema::create('subject_terms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->unsignedTinyInteger('term');
                $table->timestamps();
                $table->unique(['subject_id', 'term'], 'subject_terms_subject_term_unique');
                $table->index(['term', 'subject_id'], 'subject_terms_term_subject_index');
            });
        }

        // Every term number the system knows; the three the pilot runs on
        // if academic_terms is empty (a fresh install before any year exists).
        $termNumbers = DB::table('academic_terms')->distinct()->orderBy('term')->pluck('term')->map(fn($t) => (int) $t)->all();
        if ($termNumbers === []) {
            $termNumbers = [1, 2, 3];
        }

        $now = now();
        foreach (DB::table('subjects')->pluck('id') as $subjectId) {
            $existing = DB::table('subject_terms')->where('subject_id', $subjectId)->pluck('term')->map(fn($t) => (int) $t)->all();
            foreach ($termNumbers as $term) {
                if (in_array($term, $existing, true)) {
                    continue;
                }
                DB::table('subject_terms')->insert([
                    'subject_id' => $subjectId,
                    'term'       => $term,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_terms');
    }
};
