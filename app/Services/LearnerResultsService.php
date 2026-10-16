<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Intervention;
use App\Models\Section;
use Illuminate\Support\Collection;

/**
 * Each learner's result per subject for one section and term — read, never
 * decided here ("Subject Teacher and Class Record" pass). Used by the
 * Adviser's Advisory Overview and the individual learner report so both
 * say the same thing:
 *
 *   Official — Passed / Failed   a verified, non-provisional grade
 *                                (SubjectResultSummary's rule; Failed via
 *                                InTermStatusService::isFailing())
 *   Not yet official             a grade row that is not verified
 *   Provisional                  GradingEngine computed a grade from the
 *                                evidence; nobody has verified it
 *   Partial Evidence             some scored items, a required component
 *                                still has none
 *   No Evidence Yet              no scored item at all — never "passing"
 *
 * No threshold, severity or prediction is added. Concerns come from
 * MonitoringClassificationService (open academic_concerns) — the one source.
 */
class LearnerResultsService
{
    public const OFFICIAL_PASSED = 'official_passed';
    public const OFFICIAL_FAILED = 'official_failed';
    public const NOT_OFFICIAL = 'not_official';
    public const PROVISIONAL = 'provisional';
    public const PARTIAL = 'partial';
    public const NO_EVIDENCE = 'no_evidence';

    public function __construct(private ?PerformanceAnalysisService $analysis = null)
    {
        $this->analysis ??= new PerformanceAnalysisService();
    }

    /**
     * @param Collection<int, \App\Models\Student> $students
     * @param Collection<int, \App\Models\Subject> $subjects
     * @return array<int, array<int, array{state: string, label: string, grade: ?float, computed: array}>> [student_id][subject_id]
     */
    public function forSection(Section $section, Collection $students, Collection $subjects, int $term): array
    {
        $grades = Grade::where('section_id', $section->id)
            ->where('grading_period', $term)
            ->where('school_year', $section->school_year)
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy(fn($g) => $g->student_id . ':' . $g->subject_id);

        $engine = $this->analysis->engine();
        $results = [];
        foreach ($students as $student) {
            foreach ($subjects as $subject) {
                $grade = $grades->get($student->id . ':' . $subject->id);
                $computed = $engine->computeGrade($student, $subject, $section, $term, $section->school_year);
                $computed['item_count'] = $engine->scoredItemCount($student, $subject, $section, $term, $section->school_year);
                $results[$student->id][$subject->id] = $this->resultFor($grade, $computed);
            }
        }

        return $results;
    }

    /** @return array{state: string, label: string, grade: ?float, computed: array} */
    public function resultFor(?Grade $grade, array $computed): array
    {
        if (ClassRecordService::isOfficial($grade)) {
            $failed = InTermStatusService::isFailing($grade);
            $stale = ClassRecordService::evidenceChanged($grade, $computed);

            return ['state' => $failed ? self::OFFICIAL_FAILED : self::OFFICIAL_PASSED,
                'label' => 'Official — ' . ($failed ? 'Failed' : 'Passed') . ($stale ? ' · evidence changed — re-verify' : ''),
                'grade' => (float) $grade->grade, 'computed' => $computed, 'stale' => $stale];
        }
        if ($grade !== null && $grade->grade !== null) {
            return ['state' => self::NOT_OFFICIAL, 'label' => 'Not yet official', 'grade' => (float) $grade->grade, 'computed' => $computed];
        }
        if ($computed['complete'] && $computed['computed_grade'] !== null) {
            return ['state' => self::PROVISIONAL, 'label' => 'Provisional (computed, not verified)',
                'grade' => $computed['transmuted_grade'] ?? $computed['computed_grade'], 'computed' => $computed];
        }
        if (($computed['item_count'] ?? 0) > 0) {
            return ['state' => self::PARTIAL, 'label' => 'Partial Evidence', 'grade' => null, 'computed' => $computed];
        }

        return ['state' => self::NO_EVIDENCE, 'label' => 'No Evidence Yet', 'grade' => null, 'computed' => $computed];
    }

    /**
     * Evidence completeness across a learner's subjects — an indicator,
     * not a status: "No Current Concern" never implies it is complete.
     *
     * @param array<int, array{state: string}> $bySubject
     * @return array{label: string, official: int, with_evidence: int, total: int}
     */
    public static function completeness(array $bySubject): array
    {
        $total = count($bySubject);
        $official = count(array_filter($bySubject, fn($r) => in_array($r['state'], [self::OFFICIAL_PASSED, self::OFFICIAL_FAILED], true)));
        $withEvidence = count(array_filter($bySubject, fn($r) => $r['state'] !== self::NO_EVIDENCE));

        $label = match (true) {
            $total === 0            => 'No subjects',
            $withEvidence === 0     => 'No Evidence Yet',
            $official === $total    => 'Official results for all subjects',
            default                 => 'Partial Evidence',
        };

        return ['label' => $label, 'official' => $official, 'with_evidence' => $withEvidence, 'total' => $total];
    }

    /** Plain-language follow-up state of one intervention. Delivered never means resolved. */
    public static function followUp(Intervention $i): string
    {
        return match (true) {
            $i->awaitingDecision()      => 'Awaiting the Principal\'s decision',
            $i->delivered_at !== null   => 'Delivered ' . $i->delivered_at->format('M j, Y'),
            $i->acknowledged_at !== null => 'Acknowledged — not yet delivered',
            default                     => 'Decided — not yet acknowledged',
        };
    }
}
