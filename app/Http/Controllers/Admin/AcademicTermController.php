<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;

/**
 * AcademicTermController (Admin)
 *
 * Controls which grading period is open for encoding, system-wide.
 * A term cannot be opened until the term before it is 100% encoded
 * across every section — this is enforced here, not just suggested.
 */
class AcademicTermController extends Controller
{
    private function activeSchoolYear(): string
    {
        return Section::activeSchoolYear();
    }

    /**
     * "Multi-school-year academic history" work order, PARTS 2-4 — the
     * term panel shows the ACTIVE year's terms by default; a completed
     * (or upcoming) year can be selected with ?school_year= to review
     * its terms read-only. Only the active year's terms can be opened
     * or closed here (see open()/close()) — a historical year's terms
     * are shown with their recorded open/closed state and dates, and
     * every Adviser write into them is refused server-side regardless
     * (AcademicTerm::acceptsWrites()).
     */
    public function index(Request $request)
    {
        $activeSchoolYear = $this->activeSchoolYear();
        AcademicTerm::ensureExistFor($activeSchoolYear);

        $schoolYear = AcademicYear::resolveSelected($request->input('school_year'));
        $isActiveYear = $schoolYear === $activeSchoolYear;

        $terms = AcademicTerm::where('school_year', $schoolYear)
            ->orderBy('term')
            ->get()
            ->map(function ($term) use ($schoolYear) {
                $term->completion = AcademicTerm::completionStatus($schoolYear, $term->term);
                // TASK 4 of "dashboard structure and upload safeguards" —
                // shown for EVERY section (not just incomplete ones), so
                // the subjects x students gap is visible before anyone
                // attempts to open the next term, not just after refusal.
                $term->capacity = AcademicTerm::sectionCapacityBreakdown($schoolYear, $term->term);
                return $term;
            });

        $academicYears = AcademicYear::orderByDesc('school_year')->get();
        $academicYears->each(function (AcademicYear $year) {
            $year->rename_blocked = AcademicYear::hasDependentRecords($year->school_year);
            $year->lifecycle = $year->lifecycleStatus();
            $year->record_counts = [
                'sections'    => Section::where('school_year', $year->school_year)->count(),
                'enrollments' => \App\Models\StudentEnrollment::where('school_year', $year->school_year)->count(),
                'grades'      => \App\Models\Grade::where('school_year', $year->school_year)->count(),
                'reports'     => \App\Models\ReportSubmission::where('school_year', $year->school_year)->count(),
            ];
        });

        $schoolYears = AcademicYear::selectableSchoolYears();

        return view('admin.academic-terms', compact(
            'terms', 'schoolYear', 'academicYears', 'activeSchoolYear', 'isActiveYear', 'schoolYears'
        ));
    }

    public function open(int $term)
    {
        abort_unless(in_array($term, [1, 2, 3], true), 404);
        $schoolYear = $this->activeSchoolYear();
        AcademicTerm::ensureExistFor($schoolYear);

        // Term 1 has nothing before it, so it can always open.
        if ($term > 1) {
            $previousTerm = $term - 1;
            $status = AcademicTerm::completionStatus($schoolYear, $previousTerm);

            if (!$status['complete']) {
                $sectionList = collect($status['incomplete_sections'])
                    ->map(fn($s) => $s['section'] . ' (' . $s['encoded'] . '/' . $s['expected'] . ')')
                    ->implode('; ');

                return redirect()->route('admin.academic-terms')
                    ->with('error', 'Cannot open Term ' . $term . ' — Term ' . $previousTerm .
                        ' is not fully encoded yet. Incomplete: ' . $sectionList);
            }
        }

        // Close whichever term is currently open (should be at most one),
        // stamping closed_at only for it. Updating ALL terms unconditionally
        // would overwrite the closed_at of terms that were already closed
        // earlier — destroying the true history of when each one closed.
        \Illuminate\Support\Facades\DB::transaction(function () use ($schoolYear, $term) {
            AcademicTerm::where('school_year', $schoolYear)->orderBy('term')->lockForUpdate()->get();
            AcademicTerm::where('school_year', $schoolYear)
                ->where('is_open', true)
                ->update(['is_open' => false, 'closed_at' => now()]);

            AcademicTerm::where('school_year', $schoolYear)
                ->where('term', $term)
                ->update(['is_open' => true, 'opened_at' => now(), 'closed_at' => null]);
        });

        LogActivity::log(
            action:      'open_term',
            description: 'Opened Term ' . $term . ' for school year ' . $schoolYear,
            tableName:   'academic_terms',
            recordId:    null
        );

        return redirect()->route('admin.academic-terms')
            ->with('success', 'Term ' . $term . ' is now open for encoding.');
    }

    public function close(int $term)
    {
        $schoolYear = $this->activeSchoolYear();

        $record = AcademicTerm::where('school_year', $schoolYear)
            ->where('term', $term)
            ->first();

        if (!$record) {
            return redirect()->route('admin.academic-terms')
                ->with('error', 'Term ' . $term . ' does not exist for school year ' . $schoolYear . '.');
        }

        if (!$record->is_open) {
            return redirect()->route('admin.academic-terms')
                ->with('error', 'Term ' . $term . ' is already closed.');
        }

        $record->update(['is_open' => false, 'closed_at' => now()]);

        LogActivity::log(
            action:      'close_term',
            description: 'Closed Term ' . $term . ' for school year ' . $schoolYear,
            tableName:   'academic_terms',
            recordId:    null
        );

        return redirect()->route('admin.academic-terms')
            ->with('success', 'Term ' . $term . ' has been closed.');
    }

    /**
     * Corrects a term's recorded start/end dates only. `term` (1/2/3,
     * every Grade/Assessment/RiskResult row's grading_period join key)
     * and `school_year` are NOT editable here — they are the term's fixed
     * identity, not a label. There is no "Term Name" field to rename
     * either: the system reads strictly as Term 1/2/3 everywhere
     * (AcademicTerm::ensureExistFor(), completionStatus(), etc.), so
     * introducing an arbitrary display name here would let it drift from
     * what every other screen already assumes.
     */
    public function update(Request $request, AcademicTerm $academicTerm)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => ['nullable', 'date', ...($request->filled('start_date') ? ['after:start_date'] : [])],
            'term' => 'prohibited',
            'term_number' => 'prohibited',
            'school_year' => 'prohibited',
            'academic_year_id' => 'prohibited',
            'is_open' => 'prohibited',
        ]);

        $academicTerm->update([
            'start_date' => $request->start_date,
            'end_date'   => $request->end_date,
        ]);

        LogActivity::log(
            'update_academic_term',
            'Updated Term ' . $academicTerm->term . ' (' . $academicTerm->school_year . ') dates',
            'academic_terms',
            $academicTerm->id
        );

        return redirect()->route('admin.academic-terms')
            ->with('success', 'Academic term updated successfully.');
    }
}
