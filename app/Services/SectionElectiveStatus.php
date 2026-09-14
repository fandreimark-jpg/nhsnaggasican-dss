<?php

namespace App\Services;

use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * "ECR alignment" work order, PART 6 — the single place every "how many
 * grades should exist for this section" consumer reads from, so the four
 * call sites that used to each compute `students->count() *
 * subjects->count()` independently share one source of truth instead of
 * drifting the way CLAUDE.md's "Part 3 sweep" already found other
 * duplicated figures doing.
 *
 * Two questions, kept separate on purpose:
 *
 *   - isFullyConfigured() answers "is a zero-electives result from
 *     Subject::forSection() actually correct, or just not set up yet?"
 *     A section whose track has no electives to offer is CORRECTLY at
 *     zero — there is nothing to assign. A section whose track DOES
 *     offer electives but has zero section_subject rows is INCORRECTLY
 *     at zero — nobody has assigned them yet, and every consumer must
 *     treat that as NOT READY, not as complete. See CLAUDE.md, "A zero
 *     from Subject::forSection() means two different things," for the
 *     fuller writeup of why collapsing these two into one "zero" was
 *     exactly the bug this part exists to fix.
 *
 *   - expectedSubjectsForTerm() / expectedGradeCount() answer "how many
 *     grades are actually expected THIS term" — core subjects always
 *     count; an elective counts only in the term(s) its section_subject
 *     assignment covers, not automatically in all three the way a flat
 *     `subjects->count() * 3` would assume.
 *
 * Only curriculum = 'sshs' sections read section_subject at all.
 * k12_2013 sections' specialization-based elective match is not broken
 * (see CLAUDE.md, "Elective selection is per-cluster, not per-learner")
 * and this class must never touch that path.
 */
class SectionElectiveStatus
{
    public function isFullyConfigured(Section $section): bool
    {
        if ($section->curriculum !== 'sshs') {
            return true; // k12_2013's mechanism isn't broken; nothing to configure here.
        }

        $hasElectivesAvailable = Subject::where('type', 'elective')
            ->where('grade_level', $section->grade_level)
            ->where('track_id', $section->track_id)
            ->exists();

        if (!$hasElectivesAvailable) {
            return true; // Genuinely nothing to assign — zero pivot rows is correct.
        }

        return SectionSubject::where('section_id', $section->id)
            ->where('school_year', $section->school_year)
            ->exists();
    }

    /**
     * Core subjects always included; an elective is included only when
     * its section_subject assignment covers $term (or carries no term
     * data yet, which is treated as "expected every term" — see
     * SectionSubject::coversTerm()). k12_2013 sections get
     * Subject::forSection()'s result unfiltered — that curriculum's
     * electives were never pivot-tracked and this method must not
     * change what they resolve to.
     */
    public function expectedSubjectsForTerm(Section $section, int $term): Collection
    {
        $subjects = Subject::forSection($section)->get();

        if ($section->curriculum !== 'sshs') {
            return $subjects;
        }

        $assignments = SectionSubject::where('section_id', $section->id)
            ->where('school_year', $section->school_year)
            ->get()
            ->keyBy('subject_id');

        return $subjects->filter(function (Subject $subject) use ($term, $assignments) {
            if ($subject->type === 'core') {
                return true;
            }

            $assignment = $assignments->get($subject->id);
            return !$assignment || $assignment->coversTerm($term);
        })->values();
    }

    public function expectedGradeCount(Section $section, int $term): int
    {
        $studentCount = Student::enrolledIn($section)->count();
        return $studentCount * $this->expectedSubjectsForTerm($section, $term)->count();
    }
}
