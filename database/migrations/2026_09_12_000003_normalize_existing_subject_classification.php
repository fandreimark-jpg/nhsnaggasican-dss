<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Subject classification and grading weights cleanup" pass — a targeted,
 * reviewed correction of the exact live subjects found to be
 * misclassified during this pass, never a blanket re-derivation. Every
 * row touched here was individually checked against `deped_subject_catalog`
 * (the real DepEd Strengthened SHS instrument) before being written down;
 * nothing is guessed or pattern-matched.
 *
 * Scoped by exact (name, grade_level) match, not by ID, and touches
 * nothing else — a test's RefreshDatabase migrate run creates none of
 * these exact names via factories, so this is a no-op there, exactly as
 * intended for a live-data correction.
 *
 *  - Basic Calculus (Grade 11, elective): stored subject_group was
 *    `core_academic`, contradicting its own type=elective. Its catalog row
 *    (STEM cluster) is 20/50/30 — the same numbers as `core_academic`,
 *    which is exactly why this went unnoticed — but it is an Academic
 *    Elective, not Core, so it moves to the new `academic_other` group
 *    (see that migration). Linking `catalog_id` here also fixes a real
 *    grading bug, not just a label: the catalog row is TE-only (st1/st2
 *    null, te_share 100), so before this link it was being computed on an
 *    equal-thirds ST1/ST2/TE split it was never supposed to have.
 *  - Art Criticism and Creative Markets (Grade 11, elective): same
 *    contradiction. Its catalog row (Arts, Social Sciences, and Humanities
 *    cluster) is 20/60/20 — genuinely different numbers from
 *    `core_academic`'s 20/50/30, so this one was silently mis-GRADED, not
 *    just mis-labelled. Moved to `arts_sports_wellness` (already seeded,
 *    already the correct 20/60/20 bucket) and linked to its catalog row.
 *  - Community Engagement Solidarity and Citizenship (Grade 12, elective):
 *    Grade 12 stays on DO 8, s. 2015, which weighs by a SECTION's track
 *    (GradingEngine::resolveDo8GroupKey()) and never reads a subject's
 *    subject_group at all. Its stored `core_academic` value is not wrong
 *    the way the two Grade 11 rows above are — it is meaningless, a
 *    leftover from the Admin form always requiring a do015_2026 group even
 *    for a Grade 12 row. Set to null rather than left holding a value that
 *    implies a grading rule that was never actually applied.
 */
return new class extends Migration
{
    private const CORRECTIONS = [
        // [name, grade_level, catalog_course_title, new_subject_group]
        ['Basic Calculus', 11, 'Basic Calculus', 'academic_other'],
        ['Art Criticism and Creative Markets', 11, 'Art Criticism and Creative Markets', 'arts_sports_wellness'],
    ];

    public function up(): void
    {
        foreach (self::CORRECTIONS as [$name, $gradeLevel, $catalogTitle, $newGroup]) {
            $catalogId = DB::table('deped_subject_catalog')
                ->whereRaw('LOWER(course_title) = ?', [strtolower($catalogTitle)])
                ->value('id');

            DB::table('subjects')
                ->where('grade_level', $gradeLevel)
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->update([
                    'subject_group' => $newGroup,
                    'catalog_id'    => $catalogId,
                    'updated_at'    => now(),
                ]);
        }

        DB::table('subjects')
            ->where('grade_level', 12)
            ->whereRaw('LOWER(name) = ?', [strtolower('Community Engagement Solidarity and Citizenship')])
            ->update(['subject_group' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (self::CORRECTIONS as [$name, $gradeLevel, $catalogTitle, $newGroup]) {
            DB::table('subjects')
                ->where('grade_level', $gradeLevel)
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->update(['subject_group' => 'core_academic', 'catalog_id' => null, 'updated_at' => now()]);
        }

        DB::table('subjects')
            ->where('grade_level', 12)
            ->whereRaw('LOWER(name) = ?', [strtolower('Community Engagement Solidarity and Citizenship')])
            ->update(['subject_group' => 'core_academic', 'updated_at' => now()]);
    }
};
