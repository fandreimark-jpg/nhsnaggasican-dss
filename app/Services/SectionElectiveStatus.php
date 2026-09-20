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
 * "Subject applicability" refactor (2026-09-20) — Subject::forSection(
 * $section, $term) (SubjectApplicabilityService) answers "what does this
 * section take in this term" from the subject configuration: core
 * subjects of the grade level, track-matched electives (k12_2013), and
 * the section's own elective choices (section_subjects), each only in
 * the terms the subject is TAUGHT. This class keeps its two questions
 * and carries no term arithmetic of its own. isFullyConfigured() still
 * reports an SSHS section that has electives available in its track but
 * no elective choice recorded as NOT READY.
 */
class SectionElectiveStatus
{
    /**
     * "Configured" means "someone has made the assignment decision for
     * this section" — any offering row at all. A section with none is
     * still correctly zero when its track has no electives to offer
     * (nothing to decide), and only sshs sections are ever at issue:
     * k12_2013's specialization-based elective match was never broken.
     */
    public function isFullyConfigured(Section $section): bool
    {
        if (SectionSubject::forSection($section)->exists()) {
            return true; // At least one elective was chosen for this section — the decision has been made.
        }

        if ($section->curriculum !== 'sshs') {
            return true; // k12_2013's mechanism isn't broken; nothing to configure here.
        }

        $hasElectivesAvailable = Subject::where('type', 'elective')
            ->where('grade_level', $section->grade_level)
            ->where('track_id', $section->track_id)
            ->exists();

        return !$hasElectivesAvailable; // Genuinely nothing to assign — zero offering rows is correct.
    }

    /**
     * Exactly Subject::forSection($section, $term) — the subjects offered
     * to this section in this term, or the curriculum default for a
     * section with no offerings yet. Kept as the named entry point every
     * "how many grades should exist" consumer already calls.
     */
    public function expectedSubjectsForTerm(Section $section, int $term): Collection
    {
        return Subject::forSection($section, $term)->orderBy('type')->orderBy('name')->get();
    }

    public function expectedGradeCount(Section $section, int $term): int
    {
        $studentCount = Student::enrolledIn($section)->count();
        return $studentCount * $this->expectedSubjectsForTerm($section, $term)->count();
    }
}
