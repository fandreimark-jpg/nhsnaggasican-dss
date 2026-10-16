<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Services\TeachingAccess;
use Illuminate\Http\Request;

/**
 * My Classes — the landing page of a Subject Teacher (and a list an
 * Adviser can open too): the advisory section and every subject/section/
 * term assignment for the selected school year, each linking to its Class
 * Record. Only the user's OWN years and assignments are listed.
 */
class ClassesController extends Controller
{
    public function index(Request $request, TeachingAccess $access)
    {
        $user = auth()->user();
        $schoolYears = $access->schoolYearsFor($user);
        $schoolYear = $access->resolveSchoolYear($user, $request->input('school_year'));

        $assignments = $schoolYear ? $access->assignmentsFor($user, $schoolYear) : collect();
        $advisory = $schoolYear && $user->isAdviser()
            ? Section::where('adviser_id', $user->id)->where('school_year', $schoolYear)->orderBy('name')->get()
            : collect();

        // One row per section + subject, listing the assigned terms.
        $classes = $assignments->groupBy(fn($a) => $a->section_id . ':' . $a->subject_id)
            ->map(fn($rows) => [
                'section' => $rows->first()->section,
                'subject' => $rows->first()->subject,
                'terms'   => $rows->pluck('grading_period')->map(fn($t) => (int) $t)->sort()->values()->all(),
            ])->values();

        $openTerm = $schoolYear ? AcademicTerm::currentOpenTerm($schoolYear) : null;

        return view('teacher.classes', compact('schoolYears', 'schoolYear', 'classes', 'advisory', 'openTerm'));
    }
}
