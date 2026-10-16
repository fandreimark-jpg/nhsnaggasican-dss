<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Services\ClassRecordService;
use App\Services\TeachingAccess;
use Illuminate\Http\Request;

/**
 * The RETIRED Adviser "Assessments / Assessment Evidence" page
 * ("Class Record consolidation", 2026-09-28). It is no longer a page:
 * `GET /adviser/assessments` only turns an old bookmark or link into the
 * matching Class Record, with every selection re-checked against what the
 * adviser may read. Its capabilities live on:
 *
 *   assessment list, scores, Missing marks   -> Class Record
 *   official-grade Verify / Verify All        -> Verify Grades (adviser.verify-grades)
 *   cross-subject advisory summary            -> Advisory Overview
 *
 * Nothing is written here and no URL from the request is followed as-is.
 */
class AssessmentController extends Controller
{
    public function index(Request $request, TeachingAccess $access)
    {
        $user = auth()->user();
        $section = Section::forAdviser($user->id);
        if (!$section) {
            return redirect()->route('teacher.classes');
        }

        $terms = AcademicTerm::termNumbers();
        $period = in_array((int) $request->input('period'), $terms, true)
            ? (int) $request->input('period')
            : (AcademicTerm::currentOpenTerm($section->school_year) ?? ($terms[0] ?? 1));

        // Only a subject this adviser may read in that section and term is
        // carried over; anything else is dropped rather than trusted.
        $subject = $access->readableSubjects($user, $section, $period)->firstWhere('id', (int) $request->input('subject_id'));

        // The Interventions page's old "add a support assessment" link: the
        // details-only Add Assessment page for the assigned teacher, else
        // the (read-only) Class Record.
        if ($request->boolean('add_item') && $subject
            && $access->canEdit($user, $section, $subject, $period) && AcademicTerm::acceptsWrites($section->school_year, $period)) {
            $component = array_key_exists($request->input('component'), ClassRecordService::COMPONENTS) ? $request->input('component') : null;

            return redirect()->route('teacher.assessments.create', array_filter([
                'section_id' => $section->id, 'subject_id' => $subject->id, 'period' => $period, 'component' => $component,
            ]));
        }

        return redirect()->route('teacher.class-record', array_filter([
            'school_year' => $section->school_year,
            'section_id'  => $section->id,
            'subject_id'  => $subject?->id,
            'period'      => $period,
        ]));
    }
}
