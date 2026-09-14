<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    /**
     * Academic year editing — 'YYYY-YYYY' with the second
     * year immediately following the first (e.g. 2027-2028, never
     * 2027-2029). Shared by store() (a new row always gets this check)
     * and update() (only when the school_year value is actually being
     * changed — see that method for why).
     */
    private function assertWellFormedSchoolYear(string $schoolYear): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m)) {
            return "School year must be in the format YYYY-YYYY, e.g. 2027-2028.";
        }

        if ((int) $m[2] !== (int) $m[1] + 1) {
            return "The second year must immediately follow the first, e.g. 2027-2028, not {$schoolYear}.";
        }

        return null;
    }

    public function store(Request $request)
    {
        $request->validate([
            'school_year' => 'required|string|max:20|unique:academic_years,school_year',
            'start_date'  => 'nullable|date',
            'end_date'    => ['nullable', 'date', ...($request->filled('start_date') ? ['after:start_date'] : [])],
        ]);

        if ($error = $this->assertWellFormedSchoolYear($request->school_year)) {
            return back()->withErrors(['school_year' => $error])->withInput();
        }

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
        if ($academicYear->is_active) {
            return redirect()->route('admin.academic-terms')
                ->with('error', 'Academic year ' . $academicYear->school_year . ' is already the active school year.');
        }

        $previous = AcademicYear::active();

        // "Multi-school-year academic history" work order, PART 2 — the
        // model does the switch inside a transaction with the rows
        // locked, so two simultaneous activations cannot both succeed.
        // Nothing under the outgoing year is renamed, moved, or deleted;
        // only its still-open term (if any) is closed, and that is logged
        // below so the audit trail shows exactly what changed.
        $result = $academicYear->activate();

        LogActivity::log(
            'activate_academic_year',
            'Activated academic year ' . $academicYear->school_year
                . ($previous ? ' (previous active year: ' . $previous->school_year . ', now completed)' : ''),
            'academic_years',
            $academicYear->id
        );

        foreach ($result['closed_terms'] as $closed) {
            LogActivity::log(
                'close_term',
                'Closed Term ' . $closed['term'] . ' for school year ' . $closed['school_year']
                    . ' because ' . $academicYear->school_year . ' was activated',
                'academic_terms',
                null
            );
        }

        $message = 'Academic year ' . $academicYear->school_year . ' is now active.';
        if ($previous) {
            $message .= ' ' . $previous->school_year . ' is now a completed school year — its records remain available as historical records.';
        }

        return redirect()->route('admin.academic-terms')->with('success', $message);
    }

    /**
     * Corrects an academic year's school_year label and/or dates.
     *
     * Renaming (changing school_year to a different value) is only
     * permitted when NOTHING references the year's current label yet —
     * see AcademicYear::hasDependentRecords(). school_year is a plain
     * string join key across sections/assessments/grades/risk_results/
     * report_submissions/etc. (no FK — see academic_years' own migration),
     * so renaming a referenced year would either orphan every one of
     * those rows or require rewriting all of them at once; this codebase
     * prefers refusing the rename over either. Dates may always be
     * corrected regardless — they carry no join semantics anywhere.
     */
    public function update(Request $request, AcademicYear $academicYear)
    {
        $request->validate([
            'school_year' => ['required', 'string', 'max:20', Rule::unique('academic_years', 'school_year')->ignore($academicYear->id)],
            'start_date'  => 'nullable|date',
            'end_date'    => ['nullable', 'date', ...($request->filled('start_date') ? ['after:start_date'] : [])],
        ]);

        $newSchoolYear = trim($request->school_year);
        $isRenaming = $newSchoolYear !== $academicYear->school_year;

        if ($isRenaming) {
            if ($error = $this->assertWellFormedSchoolYear($newSchoolYear)) {
                return back()->withErrors(['school_year' => $error])->withInput();
            }

            if (AcademicYear::hasDependentRecords($academicYear->school_year)) {
                return back()->withErrors([
                    'school_year' => 'School year cannot be renamed because academic records already reference it. You may still edit its dates.',
                ])->withInput();
            }
        }

        $academicYear->update([
            'school_year' => $newSchoolYear,
            'start_date'  => $request->start_date,
            'end_date'    => $request->end_date,
        ]);

        LogActivity::log(
            'update_academic_year',
            'Updated academic year ' . $academicYear->school_year,
            'academic_years',
            $academicYear->id
        );

        return redirect()->route('admin.academic-terms')
            ->with('success', 'Academic year updated successfully.');
    }
}
