<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Section;
use App\Models\ReportSubmission;

/**
 * DashboardController (Adviser)
 *
 * Handles the Adviser Dashboard.
 * Shows the adviser's section overview — total students,
 * grades encoded, pending submissions, and student risk levels.
 */
class DashboardController extends Controller
{
    public function index()
    {
        // Get the section assigned to the logged-in adviser
        $section = Section::where('adviser_id', auth()->id())
            ->with(['track', 'specialization'])
            ->first();

        // If no section assigned — show empty dashboard
        $totalStudents = $section
            ? Student::where('section_id', $section->id)->count()
            : 0;

        // Get subjects for this section (core + elective filtered by track/spec)
        $subjects = $section
            ? $this->getSectionSubjects($section)
            : collect();

        // Total grades expected per term = students × subjects
        $totalExpectedPerTerm = $totalStudents * $subjects->count();

        // Count grades encoded per term — used for progress tracking
        $term1Count = $section ? Grade::where('section_id', $section->id)
            ->where('grading_period', 1)
            ->where('school_year', $section->school_year)
            ->count() : 0;

        $term2Count = $section ? Grade::where('section_id', $section->id)
            ->where('grading_period', 2)
            ->where('school_year', $section->school_year)
            ->count() : 0;

        $term3Count = $section ? Grade::where('section_id', $section->id)
            ->where('grading_period', 3)
            ->where('school_year', $section->school_year)
            ->count() : 0;

        $totalGradesEncoded = $term1Count + $term2Count + $term3Count;

        // Load submissions keyed by grading_period for easy lookup in the view
        $submissions = $section ? ReportSubmission::where('section_id', $section->id)
            ->where('school_year', $section->school_year)
            ->get()
            ->keyBy('grading_period') : collect();

        // Count terms that are complete but not yet submitted
        $pendingCount = 0;
        foreach ([1, 2, 3] as $term) {
            $termCount = match($term) {
                1 => $term1Count,
                2 => $term2Count,
                3 => $term3Count,
            };
            $isComplete  = $totalExpectedPerTerm > 0 && $termCount >= $totalExpectedPerTerm;
            $isSubmitted = isset($submissions[$term]);
            if ($isComplete && !$isSubmitted) {
                $pendingCount++;
            }
        }

        // Load students with their latest risk results for the overview table
        $students = $section
            ? Student::where('section_id', $section->id)
                ->with(['riskResults' => function ($q) use ($section) {
                    $q->where('school_year', $section->school_year)
                      ->orderBy('grading_period', 'desc'); // latest term first
                }])
                ->orderBy('last_name')
                ->get()
            : collect();

        // Trend per student — compares the two most recent terms so the
        // adviser can see whether a student is improving or declining,
        // not just their current snapshot.
        $trends = $students->mapWithKeys(function ($student) {
            $history = $student->riskResults->sortBy('grading_period')->values();
            return [$student->id => $this->computeTrend($history)];
        });

        return view('adviser.dashboard', compact(
            'section', 'totalStudents', 'totalGradesEncoded',
            'pendingCount', 'submissions', 'students', 'trends',
            'totalExpectedPerTerm',
            'term1Count', 'term2Count', 'term3Count'
        ));
    }

    /**
     * Same trend logic as the Admin dashboard — 'improving', 'declining',
     * 'stable', or null when there's fewer than 2 terms to compare.
     */
    private function computeTrend($history): ?string
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
     * Get subjects for a section filtered by grade level, track, and specialization.
     * Core subjects apply to all sections. Elective subjects are track/spec specific.
     */
    private function getSectionSubjects(Section $section)
    {
        return Subject::where('grade_level', $section->grade_level)
            ->where(function ($query) use ($section) {
                $query->where('type', 'core')
                    ->orWhere(function ($q) use ($section) {
                        $q->where('type', 'elective')
                          ->where('track_id', $section->track_id)
                          ->where(function ($q2) use ($section) {
                              $q2->whereNull('specialization_id')
                                 ->orWhere('specialization_id', $section->specialization_id);
                          });
                    });
            })
            ->get();
    }
}