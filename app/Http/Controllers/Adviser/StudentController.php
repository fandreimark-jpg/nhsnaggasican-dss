<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\Section;
use App\Services\MonitoringClassificationService;
use Illuminate\Http\Request;

/**
 * StudentController (Adviser)
 *
 * The adviser's roster of their own section — VIEW ONLY. Adding,
 * importing, editing and removing learners are Admin-only master-data
 * actions (see Admin\StudentController); the adviser edit action was
 * removed 2026-09-27.
 * Scoped to the adviser's section for security.
 */
class StudentController extends Controller
{
    /** TASK 5d of "clarity, progress, and visual design pass" — real pagination, same page size as every other paginated table in this app. */
    private const PER_PAGE = 25;

    /**
     * Show all students in the adviser's section.
     *
     * "Clarity, progress, and visual design pass" TASK 5d — this page
     * used to render the whole section roster in one unpaginated table
     * (fine for ~40 students, not for a larger section). ->withQueryString()
     * carries forward any filter this page gains in the future across
     * page changes, same convention as every other paginated table here
     * (Principal\StudentController, Principal\InterventionController).
     */
    public function index(Request $request)
    {
        // Get only the section assigned to this adviser
        $section  = Section::forAdviser(auth()->id());
        $students = $section
            ? Student::enrolledIn($section)->orderBy('last_name')->paginate(self::PER_PAGE)->withQueryString()
            // No section assigned — an empty paginator (not a plain
            // Collection) so the view can call ->total()/->hasPages()
            // unconditionally either way, same shape either branch.
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, self::PER_PAGE);

        // A concise monitoring overview per learner — the SAME service the
        // Principal's pages read (MonitoringClassificationService). The
        // official Term results and the full evidence are on Submit Report
        // ("View details"); nothing here changes a concern or a grade.
        $terms = AcademicTerm::termNumbers();
        $period = $section ? (int) $request->input('period', AcademicTerm::currentOpenTerm($section->school_year) ?? 1) : 1;
        if (!in_array($period, $terms, true)) {
            $period = $terms[0] ?? 1;
        }
        $monitoring = collect();
        $forReviewCount = 0;
        if ($section) {
            $classifier = app(MonitoringClassificationService::class);
            $all = $classifier->forStudents(Student::enrolledIn($section)->pluck('students.id'), $section->school_year, $period);
            $forReviewCount = $all->filter(fn($m) => $m->isForReview())->count();
            $monitoring = $all->only($students->pluck('id')->all());
        }

        return view('adviser.students', compact('students', 'section', 'terms', 'period', 'monitoring', 'forReviewCount'));
    }
}
