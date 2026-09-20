<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Services\GradingEngine;
use App\Services\SubjectApplicabilityService;
use App\Services\SubjectOfferingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin > Sections > Subjects — a RESOLVED, informational view ("Subject
 * applicability" refactor, 2026-09-20): which subjects this section
 * takes in each academic term, as SubjectApplicabilityService resolves
 * them from Admin > Subjects' configuration, with the source of each
 * (core of the grade level, matched by track/specialization, or chosen
 * for this section) and its resolved grading profile.
 *
 * The per-term "Assign Subject" workflow that used to live here is gone
 * — a normal curriculum subject is never assigned section by section
 * any more. The one action left is the one the curriculum cannot decide:
 * choosing an ELECTIVE for a section that has no strand to match it by
 * (an SSHS section). A choice applies in every term the subject is
 * taught; removing one is refused while academic records exist for it.
 */
class SectionSubjectController extends Controller
{
    public function __construct(
        private SubjectApplicabilityService $applicability = new SubjectApplicabilityService(),
        private SubjectOfferingService $offerings = new SubjectOfferingService(),
        private GradingEngine $gradingEngine = new GradingEngine()
    ) {
    }

    public function index(Request $request, Section $section)
    {
        $section->load(['track', 'specialization', 'adviser']);
        AcademicTerm::ensureExistFor($section->school_year);

        $termNumbers = $this->applicability->termNumbers();
        $term = (int) $request->input('term', AcademicTerm::currentOpenTerm($section->school_year) ?? $termNumbers[0]);
        if (!in_array($term, $termNumbers, true)) {
            $term = $termNumbers[0];
        }

        // Every subject the section resolves in ANY term, once, then
        // filtered per term in memory — one query for the year, not one
        // per term tab.
        $yearSubjects = Subject::forSection($section)
            ->with(['track', 'specialization', 'catalog', 'terms'])
            ->orderBy('type')->orderBy('name')
            ->get();

        $history = $this->historyByTerm($section);
        $chosenIds = SectionSubject::forSection($section)->pluck('subject_id')->flip();

        $subjectsForTerm = $yearSubjects
            ->filter(fn(Subject $subject) => $subject->isTaughtIn($term))
            ->map(function (Subject $subject) use ($section, $term, $history, $chosenIds) {
                $subject->source = $this->sourceOf($subject, $section, $chosenIds->has($subject->id));
                $subject->source_label = SubjectApplicabilityService::SOURCE_LABELS[$subject->source] ?? $subject->source;
                $subject->profile = $this->profileFor($section, $subject);
                $subject->history = collect($history[$subject->id][$term] ?? [])->filter();
                $subject->history_any_term = collect($history[$subject->id] ?? [])->flatten()->sum() > 0;
                return $subject;
            })
            ->values();

        $countsPerTerm = [];
        foreach ($termNumbers as $t) {
            $countsPerTerm[$t] = $yearSubjects->filter(fn(Subject $s) => $s->isTaughtIn($t))->count();
        }

        $choiceCandidates = $this->applicability->electiveChoiceCandidates($section)
            ->map(function (Subject $subject) use ($section) {
                $subject->profile = $this->profileFor($section, $subject);
                return $subject;
            });

        return view('admin.section-subjects', [
            'section'          => $section,
            'term'             => $term,
            'termNumbers'      => $termNumbers,
            'openTerm'         => AcademicTerm::currentOpenTerm($section->school_year),
            'subjects'         => $subjectsForTerm,
            'countsPerTerm'    => $countsPerTerm,
            'choiceCandidates' => $choiceCandidates,
            'usesTrackElectives' => $this->applicability->usesTrackElectives($section),
        ]);
    }

    /** Records an elective as this section's choice — see SubjectOfferingService::chooseElective(). */
    public function store(Request $request, Section $section)
    {
        $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
        ]);

        $subject = Subject::with('terms')->findOrFail($request->integer('subject_id'));

        try {
            $rows = $this->offerings->chooseElective($section, $subject);
        } catch (ValidationException $e) {
            return redirect()->route('admin.sections.subjects', ['section' => $section->id, 'term' => $request->input('term')])
                ->withErrors($e->errors(), 'assign')
                ->withInput();
        }

        LogActivity::log(
            'choose_section_elective',
            "Chose elective {$subject->name} for {$section->name}, {$section->school_year} (taught in {$subject->termsLabel()})",
            'section_subjects',
            $rows->first()?->id
        );

        return redirect()->route('admin.sections.subjects', ['section' => $section->id, 'term' => $request->input('term')])
            ->with('success', "{$subject->name} is now a chosen elective for {$section->name} — it applies in {$subject->termsLabel()}, as configured under Admin > Subjects.");
    }

    /** Removes an elective choice (every term of it) — refused while academic records exist for it. */
    public function destroy(Request $request, Section $section, Subject $subject)
    {
        try {
            $this->offerings->removeElectiveChoice($section, $subject);
        } catch (ValidationException $e) {
            return redirect()->route('admin.sections.subjects', ['section' => $section->id, 'term' => $request->input('term')])
                ->with('error', collect($e->errors())->flatten()->first());
        }

        LogActivity::log(
            'remove_section_elective',
            "Removed elective choice {$subject->name} from {$section->name}, {$section->school_year}",
            'section_subjects',
            null
        );

        return redirect()->route('admin.sections.subjects', ['section' => $section->id, 'term' => $request->input('term')])
            ->with('success', "{$subject->name} is no longer a chosen elective for {$section->name}.");
    }

    private function sourceOf(Subject $subject, Section $section, bool $chosen): string
    {
        if ($subject->type === 'core') {
            return SubjectApplicabilityService::SOURCE_CORE;
        }

        if ($this->applicability->usesTrackElectives($section)
            && $subject->track_id !== null
            && (int) $subject->track_id === (int) $section->track_id
            && ($subject->specialization_id === null || (int) $subject->specialization_id === (int) $section->specialization_id)) {
            return SubjectApplicabilityService::SOURCE_TRACK;
        }

        return $chosen ? SubjectApplicabilityService::SOURCE_SECTION_CHOICE : 'unknown';
    }

    /**
     * Academic record counts for this section, keyed subject_id => term =>
     * [table => n] — one grouped query per table for the whole page, so
     * "has records" never costs a query per row.
     */
    private function historyByTerm(Section $section): array
    {
        $out = [];
        foreach (['grades', 'assessments', 'assessment_uploads', 'interventions'] as $table) {
            $rows = DB::table($table)
                ->where('section_id', $section->id)
                ->where('school_year', $section->school_year)
                ->whereNotNull('subject_id')
                ->select('subject_id', 'grading_period', DB::raw('COUNT(*) as n'))
                ->groupBy('subject_id', 'grading_period')
                ->get();
            foreach ($rows as $row) {
                $out[(int) $row->subject_id][(int) $row->grading_period][$table] = (int) $row->n;
            }
        }

        return $out;
    }

    private function profileFor(Section $section, Subject $subject): array
    {
        try {
            $profile = $this->gradingEngine->resolveWeightProfile($section, $subject);

            return [
                'ww'          => $profile['ww_weight'],
                'pt'          => $profile['pt_weight'],
                'ex'          => $profile['ex_weight'],
                'source'      => $profile['source_label'],
                'group_label' => $profile['source'] === 'catalog'
                    ? ($profile['catalog']->cluster ?? 'DepEd catalog')
                    : SubjectGroupWeight::labelFor($profile['group_key']),
                'error'       => null,
            ];
        } catch (\RuntimeException $e) {
            return [
                'ww' => null, 'pt' => null, 'ex' => null,
                'source'      => 'Not resolvable',
                'group_label' => SubjectGroupWeight::labelFor($subject->subject_group),
                'error'       => $e->getMessage(),
            ];
        }
    }
}
