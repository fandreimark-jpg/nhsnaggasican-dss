<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;

/**
 * The read-only Decision Support dashboard data (risk distribution, at-risk
 * students, performance trends, academic honors) used by BOTH the Admin
 * dashboard and the Principal dashboard. Extracted here so the two roles
 * share one computation instead of the Principal view drifting out of sync
 * with whatever the Admin dashboard does.
 *
 * Everything here is read-only by construction — it only ever queries data,
 * never writes it — matching the rule that Principal may view but not
 * modify academic records.
 */
class DashboardAnalyticsService
{
    /**
     * Everything needed to render the main dashboard body (summary cards,
     * risk distribution, section chart, academic honors) for the currently
     * active school year.
     */
    public function getSummaryData(): array
    {
        $totalStudents = Student::count();
        $totalSections = Section::count();
        $totalAdvisers = User::where('role', 'adviser')->count();

        $schoolYear = Section::activeSchoolYear();

        $latestPerStudent = RiskResult::whereIn('id',
            RiskResult::where('school_year', $schoolYear)
                ->selectRaw('MAX(id) as id')
                ->groupBy('student_id')
                ->pluck('id')
        )->get();

        $lowRisk      = $latestPerStudent->where('risk_level', 'low')->count();
        $moderateRisk = $latestPerStudent->where('risk_level', 'moderate')->count();
        $highRisk     = $latestPerStudent->where('risk_level', 'high')->count();

        $sections = Section::with([
            'students.riskResults' => fn($q) => $q->where('school_year', $schoolYear),
            'adviser',
            'track',
            'specialization',
        ])->get();

        $termTrends = [];
        $allTermAvgs = Grade::selectRaw('grading_period, AVG(grade) as avg_grade')
            ->groupBy('grading_period')
            ->pluck('avg_grade', 'grading_period');

        foreach ([1, 2, 3] as $term) {
            $termTrends[] = isset($allTermAvgs[$term])
                ? round($allTermAvgs[$term], 2)
                : null;
        }

        $sectionRiskData = $sections->map(function ($section) {
            $low = $moderate = $high = 0;
            foreach ($section->students as $student) {
                $latest = $student->riskResults->sortByDesc('grading_period')->first();
                if ($latest) {
                    match ($latest->risk_level) {
                        'low'      => $low++,
                        'moderate' => $moderate++,
                        'high'     => $high++,
                        default    => null,
                    };
                }
            }
            return [
                'section'  => $section->name,
                'low'      => $low,
                'moderate' => $moderate,
                'high'     => $high,
            ];
        });

        $allStudentsWithRisk = Student::with(['section', 'riskResults' => fn($q) => $q->where('school_year', $schoolYear)])
            ->whereHas('riskResults', fn($q) => $q->where('school_year', $schoolYear))
            ->get()
            ->map(function ($student) {
                $latest = $student->riskResults->sortByDesc('grading_period')->first();
                return [
                    'name'    => $student->last_name . ', ' . $student->first_name,
                    'section' => $student->section->name ?? '—',
                    'average' => $latest->average_grade ?? null,
                ];
            })
            ->filter(fn($s) => $s['average'] !== null);

        return [
            'totalStudents'   => $totalStudents,
            'totalSections'   => $totalSections,
            'totalAdvisers'   => $totalAdvisers,
            'lowRisk'         => $lowRisk,
            'moderateRisk'    => $moderateRisk,
            'highRisk'        => $highRisk,
            'sections'        => $sections,
            'termTrends'      => $termTrends,
            'sectionRiskData' => $sectionRiskData,
            'highestHonors'   => $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 98)->values(),
            'highHonors'      => $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 95 && $s['average'] < 98)->values(),
            'withHonors'      => $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 90 && $s['average'] < 95)->values(),
        ];
    }

    /**
     * Everything needed to render the "Students Needing Attention" widget —
     * students, filtered by the ar_grade_level / ar_section_search query
     * params if present, sorted by urgency. Shared by a normal page load
     * and an AJAX filter refresh so both compute this identically.
     */
    public function getAtRiskStudentsData(): array
    {
        $atRiskGradeLevel = request('ar_grade_level');
        $atRiskSection    = request('ar_section_search');

        $atRiskStudents = Student::with(['section', 'riskResults'])
        ->whereHas('riskResults')
        ->when($atRiskGradeLevel, fn($q) =>
            $q->whereHas('section', fn($s) => $s->where('grade_level', $atRiskGradeLevel))
        )
        ->when($atRiskSection, fn($q) =>
            $q->whereHas('section', fn($s) => $s->where('name', $atRiskSection))
        )
        ->get()
        ->map(function ($student) {
            $history    = $student->riskResults->sortBy('grading_period')->values();
            $latestRisk = $history->last();

            return [
                'name'                  => $student->last_name . ', ' . $student->first_name,
                'section'               => $student->section->name ?? '—',
                'grade_level'           => $student->section->grade_level ?? null,
                'average'               => $latestRisk->average_grade ?? '—',
                'risk_level'            => $latestRisk->risk_level ?? '—',
                'weakest_subject'       => $latestRisk->weakest_subject ?? null,
                'weakest_subject_grade' => $latestRisk->weakest_subject_grade ?? null,
                'failing_subjects'      => $latestRisk->failing_subjects ?? [],
                'confidence'            => $latestRisk->confidence ?? null,
                'was_overridden'        => $latestRisk->was_overridden ?? false,
                'ml_risk_level'         => $latestRisk->ml_risk_level ?? null,
                'trend'                 => $this->computeTrend($history),
                'consecutive_decline'   => $this->computeConsecutiveDecline($history),
                'subject_declines'      => $this->computeSubjectDeclines($student->id, $history),
            ];
        })
        ->filter(fn($s) => in_array($s['risk_level'], ['moderate', 'high']))
        ->sortBy([
            fn($s) => $s['risk_level'] === 'high' ? 0 : 1,
            fn($s) => $s['consecutive_decline'] ? 0 : 1,
            fn($s) => is_numeric($s['average']) ? $s['average'] : 999,
        ])
        ->values();

        $atRiskStudentsTotal = $atRiskStudents->count();

        $atRiskGradeLevels = Section::select('grade_level')
            ->distinct()
            ->orderBy('grade_level')
            ->pluck('grade_level');

        $atRiskSections = Section::select('id', 'name', 'grade_level')
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get();

        return compact('atRiskStudents', 'atRiskStudentsTotal', 'atRiskGradeLevels', 'atRiskSections');
    }

    /**
     * Compare a student's average grade across grading periods to see how
     * their performance is actually moving — not just a single snapshot.
     *
     * $history = the student's riskResults, already sorted oldest → newest.
     *
     * Returns one of: 'improving', 'declining', 'stable', or null (null
     * when there's only one term on record — nothing to compare yet).
     */
    public function computeTrend($history): ?string
    {
        if ($history->count() < 2) {
            return null;
        }

        $previous = $history[$history->count() - 2]->average_grade;
        $current  = $history[$history->count() - 1]->average_grade;
        $diff     = $current - $previous;

        if ($diff > 1) {
            return 'improving';
        }
        if ($diff < -1) {
            return 'declining';
        }
        return 'stable';
    }

    /**
     * Two consecutive declining terms, regardless of the current
     * risk_level — catches a student quietly sliding downward before
     * they cross into "High Risk". Needs at least 3 terms on record.
     */
    public function computeConsecutiveDecline($history): bool
    {
        if ($history->count() < 3) {
            return false;
        }

        $n = $history->count();

        $latestDiff = $history[$n - 1]->average_grade - $history[$n - 2]->average_grade;
        $priorDiff  = $history[$n - 2]->average_grade - $history[$n - 3]->average_grade;

        return $latestDiff < -1 && $priorDiff < -1;
    }

    /**
     * Subjects that dropped 5+ points since the last term, worst decline
     * first — catches a subject-specific decline that the overall average
     * could otherwise mask.
     */
    public function computeSubjectDeclines(int $studentId, $history): array
    {
        if ($history->count() < 2) {
            return [];
        }

        $n = $history->count();
        $previousResult = $history[$n - 2];
        $currentResult  = $history[$n - 1];

        $previousGrades = Grade::where('student_id', $studentId)
            ->where('grading_period', $previousResult->grading_period)
            ->where('school_year', $previousResult->school_year)
            ->pluck('grade', 'subject_id');

        $currentGrades = Grade::where('student_id', $studentId)
            ->where('grading_period', $currentResult->grading_period)
            ->where('school_year', $currentResult->school_year)
            ->with('subject')
            ->get();

        $declines = [];

        foreach ($currentGrades as $g) {
            $prev = $previousGrades[$g->subject_id] ?? null;

            if ($prev === null) {
                continue;
            }

            $diff = $g->grade - $prev;

            if ($diff <= -5) {
                $declines[] = [
                    'subject' => $g->subject->name ?? 'Unknown',
                    'from'    => $prev,
                    'to'      => $g->grade,
                    'diff'    => $diff,
                ];
            }
        }

        usort($declines, fn($a, $b) => $a['diff'] <=> $b['diff']);

        return $declines;
    }
}
