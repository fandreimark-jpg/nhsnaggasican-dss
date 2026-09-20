<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Models\SubjectTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE PLACE "which subjects does this section take in this term" is
 * decided ("Subject applicability" refactor, 2026-09-20). Subject::
 * forSection() delegates here, so every Adviser, Principal and Admin
 * screen — and every write guard behind them — reads the same answer.
 *
 * The rule, from stored configuration only (nothing is inferred from a
 * file, a name, or a guess):
 *
 *   A subject applies to a section in a term when
 *     1. its grade level is the section's grade level, AND
 *     2. it reaches the section by one of three routes —
 *        - CORE: every core subject of the grade level, every section;
 *        - TRACK: an elective whose track (and specialization, when it
 *          names one) is the section's — the 2013-curriculum strand
 *          mechanism, kept for k12_2013 sections and for a section whose
 *          curriculum is not yet recorded; an SSHS section has no strands
 *          and never resolves electives this way;
 *        - SECTION CHOICE: an elective the Admin chose for THIS section
 *          (a section_subjects row for the section's school year) —
 *          the only way an SSHS elective reaches a section;
 *     AND
 *     3. the subject is TAUGHT in that term (a subject_terms row) — the
 *        Terms Taught configuration on Admin > Subjects.
 *
 * Applicability is not term STATUS. "Taught in Term 2" says nothing about
 * whether Term 2 is open for encoding — AcademicTerm::acceptsWrites()
 * still guards every write separately.
 *
 * query() builds this as SQL; appliesTo() is the same rule as a PHP
 * predicate over a hypothetical subject, used to check an Admin's EDIT
 * against existing academic history before it is saved. The two must not
 * drift; SubjectApplicabilityTest holds them against each other.
 */
class SubjectApplicabilityService
{
    public const SOURCE_CORE = 'core';
    public const SOURCE_TRACK = 'track';
    public const SOURCE_SECTION_CHOICE = 'section_choice';

    public const SOURCE_LABELS = [
        self::SOURCE_CORE           => 'Core subject of Grade level (curriculum configuration)',
        self::SOURCE_TRACK          => 'Elective matched by the section\'s track / specialization',
        self::SOURCE_SECTION_CHOICE => 'Elective chosen for this section',
    ];

    /** The term numbers a subject may be taught in — see AcademicTerm::termNumbers(). */
    public function termNumbers(): array
    {
        return AcademicTerm::termNumbers();
    }

    /**
     * Subjects applicable to $section — in $term, or in ANY term when
     * $term is null (year-wide views). A query builder: callers append
     * ->get()/->pluck()/->count() themselves, exactly as Subject::
     * forSection() always worked. Section choices and Terms Taught are
     * nested selects, so this is one query however it is consumed.
     */
    public function query(Section $section, ?int $term = null): Builder
    {
        $query = Subject::query()
            ->where('grade_level', $section->grade_level)
            ->where(function (Builder $reach) use ($section) {
                $reach->where('type', 'core')
                    ->orWhere(function (Builder $chosen) use ($section) {
                        $chosen->where('type', 'elective')
                            ->whereIn('id', SectionSubject::forSection($section)->select('subject_id'));
                    });

                if ($this->usesTrackElectives($section)) {
                    $reach->orWhere(function (Builder $strand) use ($section) {
                        $strand->where('type', 'elective')
                            ->where('track_id', $section->track_id)
                            ->where(function (Builder $spec) use ($section) {
                                $spec->whereNull('specialization_id')
                                     ->orWhere('specialization_id', $section->specialization_id);
                            });
                    });
                }
            });

        if ($term !== null) {
            $query->whereIn('id', SubjectTerm::where('term', $term)->select('subject_id'));
        }

        return $query;
    }

    /**
     * The same rule as query(), evaluated in PHP for a subject described
     * by $attributes (name, type, grade_level, track_id, specialization_id,
     * terms => int[]) — the shape an Admin edit is about to save.
     *
     * @param array{type: string, grade_level: int|string, track_id?: int|string|null, specialization_id?: int|string|null, terms: array<int, int>} $attributes
     */
    public function appliesTo(array $attributes, Section $section, ?int $term, bool $hasSectionChoice): bool
    {
        if ((int) $attributes['grade_level'] !== (int) $section->grade_level) {
            return false;
        }

        if ($term !== null && !in_array($term, array_map('intval', $attributes['terms'] ?? []), true)) {
            return false;
        }

        if ($attributes['type'] === 'core') {
            return true;
        }

        if ($hasSectionChoice) {
            return true;
        }

        if (!$this->usesTrackElectives($section)) {
            return false;
        }

        $trackId = $attributes['track_id'] ?? null;
        $specializationId = $attributes['specialization_id'] ?? null;

        return $trackId !== null
            && (int) $trackId === (int) $section->track_id
            && ($specializationId === null || (int) $specializationId === (int) $section->specialization_id);
    }

    /** How $subject reaches $section — one of the SOURCE_* constants, or null when it does not. */
    public function sourceFor(Subject $subject, Section $section): ?string
    {
        if ((int) $subject->grade_level !== (int) $section->grade_level) {
            return null;
        }

        if ($subject->type === 'core') {
            return self::SOURCE_CORE;
        }

        if ($this->usesTrackElectives($section)
            && $subject->track_id !== null
            && (int) $subject->track_id === (int) $section->track_id
            && ($subject->specialization_id === null || (int) $subject->specialization_id === (int) $section->specialization_id)) {
            return self::SOURCE_TRACK;
        }

        if (SectionSubject::forSection($section)->where('subject_id', $subject->id)->exists()) {
            return self::SOURCE_SECTION_CHOICE;
        }

        return null;
    }

    /**
     * Sections of $schoolYear that resolve $subject (in $term when given).
     * The inverse of query(), for Principal screens that start from a
     * subject — evaluated with the same predicate, never a second rule.
     *
     * @return Collection<int, Section>
     */
    public function sectionsOffering(Subject $subject, ?int $term, string $schoolYear): Collection
    {
        $chosenBy = SectionSubject::where('subject_id', $subject->id)
            ->where('school_year', $schoolYear)
            ->pluck('section_id')
            ->flip();

        $attributes = $this->attributesOf($subject);

        return Section::where('school_year', $schoolYear)
            ->orderBy('grade_level')->orderBy('name')
            ->get()
            ->filter(fn(Section $section) => $this->appliesTo($attributes, $section, $term, $chosenBy->has($section->id)))
            ->values();
    }

    /**
     * Elective subjects an Admin may still CHOOSE for $section: same grade
     * level, not already reaching the section by track or by an existing
     * choice. Core subjects are never listed — they apply on their own.
     *
     * @return Collection<int, Subject>
     */
    public function electiveChoiceCandidates(Section $section): Collection
    {
        $resolvedIds = $this->query($section)->pluck('id');

        return Subject::where('type', 'elective')
            ->where('grade_level', $section->grade_level)
            ->whereNotIn('id', $resolvedIds)
            ->with(['track', 'specialization', 'catalog', 'terms'])
            ->orderBy('name')
            ->get();
    }

    /**
     * HISTORY PROTECTION for an Admin edit. Every (section, term) that
     * already holds academic records for $subject — grades, assessment
     * items, uploads, interventions, or a risk result naming it as the
     * weakest subject — is re-checked against the configuration the edit
     * would save. Any pair the new configuration no longer resolves is a
     * conflict: saving it would put existing records on a subject the
     * section is no longer taught, and history must never fall out of
     * view. The caller blocks the edit and names each pair.
     *
     * @param array{type: string, grade_level: int|string, track_id?: mixed, specialization_id?: mixed, terms: array<int, int>} $newAttributes
     * @return Collection<int, array{section: Section, term: int, counts: Collection<string, int>}>
     */
    public function historyConflicts(Subject $subject, array $newAttributes): Collection
    {
        $pairs = collect();
        foreach (['grades', 'assessments', 'assessment_uploads', 'interventions'] as $table) {
            $pairs = $pairs->merge(
                DB::table($table)
                    ->where('subject_id', $subject->id)
                    ->whereNotNull('section_id')
                    ->whereNotNull('grading_period')
                    ->select('section_id', 'grading_period', DB::raw('COUNT(*) as n'))
                    ->groupBy('section_id', 'grading_period')
                    ->get()
                    ->map(fn($row) => ['table' => $table, 'section_id' => (int) $row->section_id, 'term' => (int) $row->grading_period, 'n' => (int) $row->n])
            );
        }
        $pairs = $pairs->merge(
            DB::table('risk_results')
                ->where('weakest_subject_id', $subject->id)
                ->whereNotNull('section_id')
                ->select('section_id', 'grading_period', DB::raw('COUNT(*) as n'))
                ->groupBy('section_id', 'grading_period')
                ->get()
                ->map(fn($row) => ['table' => 'risk_results', 'section_id' => (int) $row->section_id, 'term' => (int) $row->grading_period, 'n' => (int) $row->n])
        );

        if ($pairs->isEmpty()) {
            return collect();
        }

        $sections = Section::whereIn('id', $pairs->pluck('section_id')->unique())->get()->keyBy('id');
        $chosenBy = SectionSubject::where('subject_id', $subject->id)->pluck('section_id')->flip();

        return $pairs
            ->groupBy(fn($p) => $p['section_id'] . '|' . $p['term'])
            ->map(function (Collection $group) use ($sections, $chosenBy, $newAttributes) {
                $sectionId = $group->first()['section_id'];
                $term = $group->first()['term'];
                $section = $sections->get($sectionId);
                if (!$section || $this->appliesTo($newAttributes, $section, $term, $chosenBy->has($sectionId))) {
                    return null;
                }

                return [
                    'section' => $section,
                    'term'    => $term,
                    'counts'  => $group->groupBy('table')->map(fn(Collection $rows) => $rows->sum('n')),
                ];
            })
            ->filter()
            ->sortBy(fn($c) => $c['section']->name . $c['term'])
            ->values();
    }

    /** @return array{type: string, grade_level: int, track_id: ?int, specialization_id: ?int, terms: array<int, int>} */
    public function attributesOf(Subject $subject): array
    {
        return [
            'type'              => $subject->type,
            'grade_level'       => (int) $subject->grade_level,
            'track_id'          => $subject->track_id,
            'specialization_id' => $subject->specialization_id,
            'terms'             => $subject->termNumbers(),
        ];
    }

    /**
     * Whether electives reach this section through its track /
     * specialization (the 2013-curriculum strand a section chose). An
     * SSHS section has no strands — its electives are section choices
     * only. A section whose curriculum is not recorded keeps the strand
     * route, exactly as Subject::forSection() always did for it.
     */
    public function usesTrackElectives(Section $section): bool
    {
        return $section->curriculum !== 'sshs';
    }
}
