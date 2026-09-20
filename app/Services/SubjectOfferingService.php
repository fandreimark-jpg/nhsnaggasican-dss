<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SECTION ELECTIVE CHOICES ("Subject applicability" refactor, 2026-09-20).
 *
 * Before this refactor the Admin assigned every subject a section takes,
 * one section and one term at a time, into section_subjects, and the
 * first such row switched the section off the curriculum entirely
 * ("term-managed"). That repetition is gone: WHICH subjects apply and
 * WHEN is now Admin > Subjects' configuration, resolved by
 * SubjectApplicabilityService for every section automatically.
 *
 * What remains genuinely per section is an ELECTIVE CHOICE — the one
 * decision the curriculum cannot make for a section: which elective(s)
 * an SSHS section (which has no strand to match on) actually takes. This
 * service records and removes those choices in section_subjects, which
 * keeps its schema untouched (one row per section/subject/academic term,
 * unique, FKs restrict) and its academic-history protection. A choice is
 * written for every term the subject is taught, so the rows continue to
 * describe exactly which (section, subject, term) the section resolves —
 * the resolver itself reads "this section chose this subject" and lets
 * the subject's Terms Taught decide the terms.
 *
 * Nothing here is written by an upload: the prescribed ECR validates
 * against this configuration and never changes it (see
 * EcrSubjectTermResolver).
 */
class SubjectOfferingService
{
    public function __construct(private SubjectApplicabilityService $applicability = new SubjectApplicabilityService())
    {
    }

    /**
     * Records an elective as chosen for $section, in every term the
     * subject is taught. Refuses a core subject (it applies on its own),
     * a subject of another grade level, and a duplicate choice.
     *
     * @return Collection<int, SectionSubject> the rows written
     */
    public function chooseElective(Section $section, Subject $subject): Collection
    {
        if ($subject->type !== 'elective') {
            throw ValidationException::withMessages([
                'subject_id' => "{$subject->name} is a core subject — it applies to every Grade {$subject->grade_level} section automatically and is not chosen per section.",
            ]);
        }

        if ((int) $subject->grade_level !== (int) $section->grade_level) {
            throw ValidationException::withMessages([
                'subject_id' => "{$subject->name} is a Grade {$subject->grade_level} subject and cannot be chosen for {$section->name} (Grade {$section->grade_level}).",
            ]);
        }

        if ($this->applicability->query($section)->where('id', $subject->id)->exists()) {
            throw ValidationException::withMessages([
                'subject_id' => "{$subject->name} already applies to {$section->name}.",
            ]);
        }

        $terms = $subject->termNumbers();
        if ($terms === []) {
            throw ValidationException::withMessages([
                'subject_id' => "{$subject->name} has no Terms Taught configured yet — set them under Admin > Subjects first.",
            ]);
        }

        return DB::transaction(function () use ($section, $subject, $terms) {
            $rows = collect();
            foreach ($terms as $term) {
                $rows->push(SectionSubject::firstOrCreate([
                    'section_id'       => $section->id,
                    'subject_id'       => $subject->id,
                    'academic_term_id' => $this->academicTermId($section->school_year, $term),
                ], [
                    'school_year'      => $section->school_year,
                ]));
            }

            return $rows;
        });
    }

    /**
     * Removes a section's elective choice. Blocked, naming the records,
     * when any term of it already carries academic history — the same
     * protection SectionSubject::hasAcademicReferences() gives a single row.
     */
    public function removeElectiveChoice(Section $section, Subject $subject): void
    {
        $rows = SectionSubject::forSection($section)->where('subject_id', $subject->id)->get();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'subject_id' => "{$subject->name} is not a section choice for {$section->name}.",
            ]);
        }

        $counts = [];
        foreach ($rows as $row) {
            foreach ($row->academicReferenceCounts() as $table => $n) {
                $counts[$table] = ($counts[$table] ?? 0) + $n;
            }
        }
        $counts = collect($counts)->filter();

        if ($counts->isNotEmpty()) {
            $what = $counts->map(fn($n, $table) => "{$n} " . str_replace('_', ' ', $table))->implode(', ');
            throw ValidationException::withMessages([
                'deletion' => "{$subject->name} cannot be removed from {$section->name} — academic records already exist for it ({$what}). Leave it in place; narrow its Terms Taught under Admin > Subjects if it should stop in a later term.",
            ]);
        }

        DB::transaction(fn() => $rows->each->delete());
    }

    /**
     * The one wording every Adviser write path refuses with when a
     * subject is not applicable to the section in the requested term.
     */
    public function notOfferedMessage(Subject|string $subject, Section $section, int $term): string
    {
        $name = $subject instanceof Subject ? $subject->name : $subject;

        return "{$name} is not applicable to {$section->name} in Term {$term}.";
    }

    /** The academic_terms row id for a (school year, term number), creating the year's terms if needed. */
    public function academicTermId(string $schoolYear, int $term): int
    {
        $id = AcademicTerm::where('school_year', $schoolYear)->where('term', $term)->value('id');
        if ($id) {
            return (int) $id;
        }

        AcademicTerm::ensureExistFor($schoolYear);

        return (int) AcademicTerm::where('school_year', $schoolYear)->where('term', $term)->value('id');
    }
}
