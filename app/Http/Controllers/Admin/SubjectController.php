<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\DepedSubjectCatalog;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\Track;
use App\Services\GradingEngine;
use App\Services\SubjectApplicabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin > Subjects — THE place a subject's academic applicability is
 * configured ("Subject applicability" refactor, 2026-09-20): grade level,
 * type, track / specialization for an elective, subject group (DO 015
 * grading weights, Grade 11 only), and TERMS TAUGHT. Every section then
 * resolves its subjects from this configuration automatically through
 * SubjectApplicabilityService — nothing is assigned section by section
 * and term by term any more.
 *
 * Grading weights are never typed here: they are resolved from the DepEd
 * catalog (exact name match, linked automatically on save) or the
 * subject group, and shown read-only on the list.
 *
 * The bulk "Import Subjects" upload that used to live here was REMOVED —
 * no official file format for subject master data exists at the school
 * or at DepEd, and inventing one is exactly what CLAUDE.md's "no custom
 * spreadsheet" rule forbids. Add/Edit is the way subjects are managed.
 */
class SubjectController extends Controller
{
    public function __construct(private SubjectApplicabilityService $applicability = new SubjectApplicabilityService())
    {
    }

    /**
     * Every subject group a subject may be assigned, Grade 11 or Grade 12
     * (SubjectGroupWeight::allGroups() — the one authoritative list, the
     * DO 015, s. 2026 Table 10 groups). Scoped to that scheme on purpose
     * (never a do8_* row) — see CLAUDE.md, "The five do8_* rows must never
     * be human-selectable": those are computed from a section's track.
     */
    private function availableSubjectGroups()
    {
        return collect(SubjectGroupWeight::allGroups());
    }

    public function index()
    {
        $subjects = Subject::with(['track', 'specialization', 'catalog', 'terms'])
            ->when(request('type'), fn($q) => $q->where('type', request('type')))
            ->when(request('subject_group_check'), fn($q) => $q->whereIn('id', Subject::withSuspectSubjectGroup()->pluck('id')))
            ->orderBy('grade_level')
            ->orderBy('type') // core subjects first
            ->orderBy('name')
            ->get();

        // Grading Profile — resolved, read-only, by THE grading engine
        // ("Grading policy display" pass, 2026-09-20). GradingEngine::
        // resolveSubjectProfile() runs resolveWeightProfile() — the exact
        // path computeGrade() takes — for every section context the subject
        // can be graded in (DO 015 needs none; DO 8 reads the section's
        // track). The page no longer carries its own copy of the
        // resolution order, and a Grade 12 subject shows the same figures
        // its assessments are graded with. A subject whose contexts
        // disagree (a core subject when tracks with different DO 8
        // branches exist) is shown as "resolved by section context" with
        // every variant, never one figure picked for it.
        $engine = new GradingEngine();
        $subjects->each(function (Subject $subject) use ($engine) {
            try {
                $resolution = $engine->resolveSubjectProfile($subject);
            } catch (\Throwable $e) {
                // An unclassified subject (no catalog row, no group) throws
                // in SubjectGroupWeight::resolve() — that is "Not configured".
                $subject->grading_weights_display = null;
                return;
            }

            $label = fn(array $p) => $p['source'] === 'catalog'
                ? 'DepEd Strengthened SHS catalog (exact subject match)'
                : ($p['scheme'] === 'do8_2015' ? 'DO 8, s. 2015 — ' : 'DO 015, s. 2026 — ') . SubjectGroupWeight::labelFor($p['group_key']);

            if (!$resolution['resolved']) {
                $subject->grading_weights_display = [
                    'source'   => 'section_context',
                    'variants' => array_map(fn($v) => [
                        'track' => ($v['track'] ?? '(no track)') . ' / ' . match ($v['curriculum'] ?? null) { 'sshs' => 'Strengthened SHS', 'k12_2013' => '2013 curriculum', default => 'curriculum unset' },
                        'ww' => $v['profile']['ww_weight'], 'pt' => $v['profile']['pt_weight'], 'ex' => $v['profile']['ex_weight'],
                        'label' => $label($v['profile']),
                    ], $resolution['variants']),
                ];
                return;
            }

            $p = $resolution['profile'];
            $subject->grading_weights_display = [
                'source' => $p['source'], 'scheme' => $p['scheme'], 'group_key' => $p['group_key'],
                'ww' => $p['ww_weight'], 'pt' => $p['pt_weight'], 'ex' => $p['ex_weight'],
                'label' => $label($p),
            ];
        });

        $tracks          = Track::with('specializations')->orderBy('name')->get();
        $specializations = Specialization::with('track')->orderBy('name')->get();
        $subjectGroups   = $this->availableSubjectGroups();
        $termNumbers     = $this->applicability->termNumbers();

        return view('admin.subjects', compact('subjects', 'tracks', 'specializations', 'subjectGroups', 'termNumbers'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if ($error = SubjectGroupWeight::classificationError($data['type'], (int) $data['grade_level'], $data['subject_group'])) {
            return back()->withErrors(['subject_group' => $error])->withInput();
        }

        $subject = DB::transaction(function () use ($data) {
            $subject = Subject::create($this->attributesToStore($data));
            $subject->syncTerms($data['terms']);

            return $subject;
        });

        LogActivity::log(
            'create_subject',
            "Created subject {$subject->name} (Grade {$subject->grade_level}, {$subject->type}; terms taught: {$subject->termsLabel()})",
            'subjects',
            $subject->id
        );

        return redirect()->route('admin.subjects')
            ->with('success', 'Subject added successfully!' . $this->catalogNote($subject));
    }

    public function update(Request $request, $id)
    {
        $subject = Subject::with('terms')->findOrFail($id);

        $data = $this->validated($request, $subject);

        if ($error = SubjectGroupWeight::classificationError($data['type'], (int) $data['grade_level'], $data['subject_group'])) {
            return back()->withErrors(['subject_group' => $error])->withInput();
        }

        // HISTORY PROTECTION — an edit that would take this subject away
        // from a section and term that already holds records for it
        // (removing a term it was taught in, changing its grade level,
        // moving an elective to another track/specialization, turning an
        // elective into a core subject of a grade level it was not
        // taught in, ...) is refused, naming every affected pair. The
        // records are never deleted, orphaned or hidden; the Admin keeps
        // the applicability that history depends on.
        $attributes = $this->attributesToStore($data, $subject) + ['terms' => $data['terms']];
        $conflicts = $this->applicability->historyConflicts($subject, $attributes);

        if ($conflicts->isNotEmpty()) {
            $named = $conflicts->map(fn($c) => "{$c['section']->name} Term {$c['term']} ("
                . $c['counts']->map(fn($n, $table) => "{$n} " . str_replace('_', ' ', $table))->implode(', ') . ')')
                ->implode('; ');

            return back()->withErrors([
                'terms' => "This change cannot be saved because academic records already exist for {$subject->name} where it would no longer apply: {$named}. Keep the grade level, track/specialization and Terms Taught those records depend on.",
            ])->withInput();
        }

        DB::transaction(function () use ($subject, $data) {
            $subject->update($this->attributesToStore($data, $subject));
            $subject->syncTerms($data['terms']);
        });

        LogActivity::log(
            'update_subject',
            "Updated subject {$subject->name} (Grade {$subject->grade_level}, {$subject->type}; terms taught: {$subject->fresh('terms')->termsLabel()})",
            'subjects',
            $subject->id
        );

        return redirect()->route('admin.subjects')
            ->with('success', 'Subject updated successfully!' . $this->catalogNote($subject->fresh()));
    }

    public function destroy($id)
    {
        $subject = Subject::findOrFail($id);

        if ($subject->grades()->exists()) {
            return redirect()->route('admin.subjects')
                ->with('error', 'Cannot delete subject with existing grades.');
        }

        $subject->delete();

        return redirect()->route('admin.subjects')
            ->with('success', 'Subject deleted successfully!');
    }

    /**
     * The one validation both store() and update() run. Terms Taught is a
     * list of term numbers that must each exist in academic_terms
     * (AcademicTerm::termNumbers()), at least one of them; a duplicate
     * name at the same grade level is refused (the rule the removed bulk
     * importer enforced, kept alive on the form).
     *
     * @return array{name: string, type: string, grade_level: int, subject_group: ?string, track_id: ?int, specialization_id: ?int, terms: array<int, int>}
     */
    private function validated(Request $request, ?Subject $existing = null): array
    {
        $termNumbers = $this->applicability->termNumbers();

        $request->validate([
            'name'              => ['required', 'string', 'max:255',
                Rule::unique('subjects', 'name')
                    ->where(fn($q) => $q->where('grade_level', (int) $request->grade_level))
                    ->ignore($existing?->id)],
            'type'              => 'required|in:core,elective',
            'grade_level'       => 'required|in:11,12',
            'subject_group'     => 'nullable|string',
            'track_id'          => 'nullable|exists:tracks,id',
            'specialization_id' => 'nullable|exists:specializations,id',
            'terms'             => 'required|array|min:1',
            'terms.*'           => ['integer', Rule::in($termNumbers)],
        ], [
            'name.unique'   => 'A subject with this name already exists for that grade level.',
            'terms.required' => 'Select at least one term this subject is taught in.',
            'terms.min'      => 'Select at least one term this subject is taught in.',
            'terms.*.in'     => 'One of the selected terms does not exist. Terms are ' . implode(', ', array_map(fn($t) => 'Term ' . $t, $termNumbers)) . '.',
        ]);

        // A specialization must belong to the chosen track — an elective
        // cannot point at a strand of a different track.
        $trackId = $request->type === 'elective' && $request->filled('track_id') ? (int) $request->track_id : null;
        $specializationId = $request->type === 'elective' && $request->filled('specialization_id') ? (int) $request->specialization_id : null;

        if ($specializationId !== null) {
            $spec = Specialization::find($specializationId);
            if ($trackId === null || !$spec || (int) $spec->track_id !== $trackId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'specialization_id' => 'The selected specialization does not belong to the selected track.',
                ]);
            }
        }

        // The RAW submitted group is what classificationError() judges —
        // the same rule for Grade 11 and Grade 12 ("Subject Group for both
        // grade levels" pass); an empty select is null, never a default.
        $rawSubjectGroup = $request->subject_group !== '' ? $request->subject_group : null;

        return [
            'name'              => $request->name,
            'type'              => $request->type,
            'grade_level'       => (int) $request->grade_level,
            'subject_group'     => $rawSubjectGroup,
            'track_id'          => $trackId,
            'specialization_id' => $specializationId,
            'terms'             => collect($request->input('terms'))->map(fn($t) => (int) $t)->unique()->sort()->values()->all(),
        ];
    }

    /**
     * The columns written to subjects. The DepEd catalog link is resolved
     * by exact, case-insensitive name when the subject has none yet (the
     * same rule the removed bulk importer applied to every row); an
     * existing link is never re-pointed or dropped by an edit.
     */
    private function attributesToStore(array $data, ?Subject $existing = null): array
    {
        return [
            'name'              => $data['name'],
            'type'              => $data['type'],
            'grade_level'       => $data['grade_level'],
            'subject_group'     => $data['subject_group'],
            'track_id'          => $data['track_id'],
            'specialization_id' => $data['specialization_id'],
            'catalog_id'        => $existing?->catalog_id
                ?? DepedSubjectCatalog::whereRaw('LOWER(course_title) = ?', [mb_strtolower(trim($data['name']))])->value('id'),
        ];
    }

    private function catalogNote(Subject $subject): string
    {
        return $subject->catalog_id
            ? " {$subject->name} matches the DepEd Strengthened SHS catalog by name and was linked to it — its grading weights come from the catalog."
            : '';
    }
}
