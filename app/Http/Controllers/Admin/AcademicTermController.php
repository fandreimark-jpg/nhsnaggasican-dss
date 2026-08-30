<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
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
        return Section::orderByDesc('id')->value('school_year')
            ?? date('Y') . '-' . (date('Y') + 1);
    }

    public function index()
    {
        $schoolYear = $this->activeSchoolYear();
        AcademicTerm::ensureExistFor($schoolYear);

        $terms = AcademicTerm::where('school_year', $schoolYear)
            ->orderBy('term')
            ->get()
            ->map(function ($term) use ($schoolYear) {
                $term->completion = AcademicTerm::completionStatus($schoolYear, $term->term);
                return $term;
            });

        return view('admin.academic-terms', compact('terms', 'schoolYear'));
    }

    public function open(int $term)
    {
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

        // Close every term for this school year, then open only this one.
        AcademicTerm::where('school_year', $schoolYear)
            ->update(['is_open' => false, 'closed_at' => now()]);

        AcademicTerm::where('school_year', $schoolYear)
            ->where('term', $term)
            ->update(['is_open' => true, 'opened_at' => now(), 'closed_at' => null]);

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

        AcademicTerm::where('school_year', $schoolYear)
            ->where('term', $term)
            ->update(['is_open' => false, 'closed_at' => now()]);

        LogActivity::log(
            action:      'close_term',
            description: 'Closed Term ' . $term . ' for school year ' . $schoolYear,
            tableName:   'academic_terms',
            recordId:    null
        );

        return redirect()->route('admin.academic-terms')
            ->with('success', 'Term ' . $term . ' has been closed.');
    }
}