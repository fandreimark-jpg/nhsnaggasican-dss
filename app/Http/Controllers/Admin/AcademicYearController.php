<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use Illuminate\Http\Request;

/**
 * AcademicYearController (Admin)
 *
 * SYSTEM_FIXES_AND_ML_AUDIT.md, "Remove Hardcoded Academic Year" — the
 * explicit control Section::activeSchoolYear() reads first (see that
 * method's docblock). Creating a year here does NOT activate it by
 * itself — activation is a separate, explicit action, same "nothing
 * changes until the Admin says so" posture AcademicTermController
 * already holds for opening/closing terms.
 */
class AcademicYearController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'school_year' => 'required|string|max:20|unique:academic_years,school_year',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
        ]);

        $year = AcademicYear::create([
            'school_year' => $request->school_year,
            'start_date'  => $request->start_date,
            'end_date'    => $request->end_date,
            'is_active'   => false,
        ]);

        LogActivity::log(
            'create_academic_year',
            'Created academic year ' . $year->school_year . ' (not yet activated)',
            'academic_years',
            $year->id
        );

        return redirect()->route('admin.academic-terms')
            ->with('success', 'Academic year ' . $year->school_year . ' created. Activate it when ready.');
    }

    /**
     * Activates one academic year, deactivating every other one first —
     * same "only one active at a time" rule AcademicTermController
     * applies to is_open. Also ensures Term 1/2/3 rows exist for the
     * newly-activated year, so the Academic Terms page and every other
     * consumer of "the active year's terms" has something to read
     * immediately, matching AcademicTermController::index()'s own
     * ensureExistFor() call.
     */
    public function activate(AcademicYear $academicYear)
    {
        AcademicYear::where('is_active', true)->update(['is_active' => false]);
        $academicYear->update(['is_active' => true]);

        AcademicTerm::ensureExistFor($academicYear->school_year);

        LogActivity::log(
            'activate_academic_year',
            'Activated academic year ' . $academicYear->school_year,
            'academic_years',
            $academicYear->id
        );

        return redirect()->route('admin.academic-terms')
            ->with('success', 'Academic year ' . $academicYear->school_year . ' is now active.');
    }
}
