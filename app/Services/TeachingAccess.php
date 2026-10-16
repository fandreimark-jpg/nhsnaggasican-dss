<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * THE one place that answers "may this person read / edit this subject's
 * evidence in this section and term?" ("Subject Teacher and Class Record"
 * pass, 2026-09-28). Two separate responsibilities, never merged:
 *
 *  - ADVISORY (sections.adviser_id — the section row is year-specific):
 *    the adviser READS every applicable subject of that section for its
 *    school year. It grants no editing right on its own.
 *
 *  - TEACHING (teaching_assignments): the assigned teacher READS and
 *    EDITS that one subject in that one section for that one term.
 *    Adviser and Subject Teacher accounts can both hold assignments.
 *
 * Every read/write path of the Class Record, the item form, the upload
 * (detect/preview/import), the exports and the learner report calls this.
 * Whether the term itself accepts writes (open term, active year) is a
 * separate question: AcademicTerm::acceptsWrites(), checked alongside.
 */
class TeachingAccess
{
    public function isAdviserOf(User $user, Section $section): bool
    {
        return $user->isAdviser() && (int) $section->adviser_id === (int) $user->id;
    }

    /** An assignment exists for exactly this user, section, subject and term. */
    public function canEdit(User $user, Section $section, Subject|int $subject, int $term): bool
    {
        if (!$user->canTeach() || !$user->isActive()) {
            return false;
        }

        return TeachingAssignment::where('user_id', $user->id)
            ->where('section_id', $section->id)
            ->where('subject_id', $subject instanceof Subject ? $subject->id : $subject)
            ->where('grading_period', $term)
            ->where('school_year', $section->school_year)
            ->exists();
    }

    public function canRead(User $user, Section $section, Subject|int $subject, int $term): bool
    {
        return $this->isAdviserOf($user, $section) || $this->canEdit($user, $section, $subject, $term);
    }

    /** Can this user see ANYTHING in this section (advisory, or any assignment in it)? */
    public function canReadSection(User $user, Section $section): bool
    {
        return $this->isAdviserOf($user, $section)
            || ($user->canTeach() && TeachingAssignment::where('user_id', $user->id)->where('section_id', $section->id)->exists());
    }

    /**
     * Every section the user may open for a school year: advisory sections
     * plus sections holding one of their teaching assignments.
     *
     * @return Collection<int, Section>
     */
    public function readableSections(User $user, string $schoolYear): Collection
    {
        if (!$user->canTeach()) {
            return collect();
        }

        $taught = TeachingAssignment::where('user_id', $user->id)->where('school_year', $schoolYear)->pluck('section_id');

        return Section::where('school_year', $schoolYear)
            ->where(function ($q) use ($user, $taught) {
                $q->whereIn('id', $taught);
                if ($user->isAdviser()) {
                    $q->orWhere('adviser_id', $user->id);
                }
            })
            ->orderBy('grade_level')->orderBy('name')
            ->get();
    }

    /**
     * School years in which the user advised a section or held an
     * assignment — newest first. Previous years stay visible to the SAME
     * person only; nothing here widens access to another teacher's year.
     *
     * @return list<string>
     */
    public function schoolYearsFor(User $user): array
    {
        if (!$user->canTeach()) {
            return [];
        }

        $years = TeachingAssignment::where('user_id', $user->id)->distinct()->pluck('school_year');
        if ($user->isAdviser()) {
            $years = $years->merge(Section::where('adviser_id', $user->id)->distinct()->pluck('school_year'));
        }

        return $years->unique()->sortDesc()->values()->all();
    }

    /** The year to open by default: the requested one if the user has it, else the active year if they have it, else their latest. */
    public function resolveSchoolYear(User $user, ?string $requested): ?string
    {
        $years = $this->schoolYearsFor($user);
        if ($requested !== null && in_array($requested, $years, true)) {
            return $requested;
        }
        $active = Section::activeSchoolYear();

        return in_array($active, $years, true) ? $active : ($years[0] ?? null);
    }

    /**
     * Subjects the user may READ in this section and term: an adviser of
     * the section reads every applicable subject; anyone else only the
     * subjects assigned to them for this term (and still applicable).
     *
     * @return Collection<int, Subject>
     */
    public function readableSubjects(User $user, Section $section, int $term): Collection
    {
        $query = Subject::forSection($section, $term);

        if (!$this->isAdviserOf($user, $section)) {
            $query->whereIn('id', $this->editableSubjectIds($user, $section, $term));
        }

        return $query->orderBy('type')->orderBy('name')->get();
    }

    /** @return list<int> subjects this user is assigned to teach in this section and term */
    public function editableSubjectIds(User $user, Section $section, int $term): array
    {
        if (!$user->canTeach()) {
            return [];
        }

        return TeachingAssignment::where('user_id', $user->id)
            ->where('section_id', $section->id)
            ->where('grading_period', $term)
            ->where('school_year', $section->school_year)
            ->pluck('subject_id')->map(fn($id) => (int) $id)->all();
    }

    /** All assignments of a user for a school year, with section and subject — for "My Classes". */
    public function assignmentsFor(User $user, string $schoolYear): Collection
    {
        return TeachingAssignment::with(['section', 'subject'])
            ->where('user_id', $user->id)
            ->where('school_year', $schoolYear)
            ->get()
            ->sortBy(fn($a) => sprintf('%02d|%s|%s|%d', $a->section->grade_level, $a->section->name, $a->subject->name, $a->grading_period))
            ->values();
    }

    /** The teacher assigned to this subject in this section and term, if any. */
    public function teacherFor(Section $section, int $subjectId, int $term): ?User
    {
        return TeachingAssignment::with('teacher')
            ->where('section_id', $section->id)
            ->where('subject_id', $subjectId)
            ->where('grading_period', $term)
            ->first()?->teacher;
    }

    /**
     * Where a delivered intervention's "support assessment" link goes: the
     * details-only Add Assessment page when this user teaches that subject
     * in that section and term and the term is open; otherwise the Class
     * Record (read-only for an advisory view); null without a subject/term
     * or any access. Never grants anything — each page re-checks.
     *
     * @return array{url: string, label: string}|null
     */
    public function supportAssessmentLink(User $user, \App\Models\Intervention $intervention): ?array
    {
        $section = $intervention->contextSection();
        if (!$section || !$intervention->subject_id || !$intervention->grading_period) {
            return null;
        }
        $term = (int) $intervention->grading_period;
        if (!$this->canRead($user, $section, (int) $intervention->subject_id, $term)) {
            return null;
        }
        if ($this->canEdit($user, $section, (int) $intervention->subject_id, $term) && \App\Models\AcademicTerm::acceptsWrites($section->school_year, $term)) {
            return ['label' => 'Add Assessment', 'url' => route('teacher.assessments.create', array_filter([
                'section_id' => $section->id, 'subject_id' => $intervention->subject_id, 'period' => $term,
                'component'  => $intervention->focusComponent(),
            ]))];
        }

        return ['label' => 'Open Class Record', 'url' => route('teacher.class-record', [
            'section_id' => $section->id, 'subject_id' => $intervention->subject_id, 'period' => $term,
        ])];
    }

    /** The one wording used when an edit is refused for lack of an assignment. */
    public static function notAssignedMessage(?string $subjectName, Section $section, int $term): string
    {
        return 'You are not assigned to teach ' . ($subjectName ?? 'that subject') . ' in ' . $section->name
            . ' for Term ' . $term . ' (' . $section->school_year . '). Only the assigned teacher can encode or change '
            . 'its assessment records. Ask the Admin to record the teaching assignment if this is wrong.';
    }

    /** Label for AcademicYear selectors — unchanged strings, kept here so every page lists years the same way. */
    public static function yearOptions(array $years): array
    {
        $active = Section::activeSchoolYear();

        return array_map(fn($y) => ['value' => $y, 'label' => $y . ($y === $active ? ' (active)' : '')], $years);
    }
}
