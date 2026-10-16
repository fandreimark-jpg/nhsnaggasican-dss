<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\TeachingAssignmentHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin > Teaching Assignments ("Subject Teacher and Class Record" pass,
 * refined 2026-09-28). Who teaches which subject in which section, per
 * term — the only thing that grants the right to encode assessment
 * evidence (App\Services\TeachingAccess).
 *
 *  - Assign: one current teacher per section/subject/term; never replaces
 *    an existing teacher silently.
 *  - Edit / Change Teacher: replaces the teacher (reason required, stale
 *    edits refused, atomic). Evidence stays exactly where it is; who
 *    entered it is never rewritten. Section/subject/term can be corrected
 *    only while that class holds NO assessment evidence.
 *  - Remove: ends responsibility (reason required); scores and assessments
 *    are never touched, and the assignment is archived in
 *    teaching_assignment_history.
 *
 * Nothing is inferred: classes with evidence but no teacher are LISTED
 * (with who entered it) for the Admin to decide. A school year before the
 * active one is historical and is not changed here.
 */
class TeachingAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $years = AcademicYear::orderByDesc('school_year')->pluck('school_year');
        $active = Section::activeSchoolYear();
        $schoolYear = $years->contains($request->input('school_year')) ? $request->input('school_year') : $active;

        $sections = Section::where('school_year', $schoolYear)->with('adviser')->orderBy('grade_level')->orderBy('name')->get();
        $assignments = TeachingAssignment::with(['teacher', 'section', 'subject', 'assignedBy'])
            ->where('school_year', $schoolYear)
            ->get()
            ->sortBy(fn($a) => sprintf('%02d|%s|%s|%d', $a->section->grade_level, $a->section->name, $a->subject->name, $a->grading_period))
            ->values();

        $teachers = $this->eligibleTeachers();
        $subjects = Subject::orderBy('grade_level')->orderBy('name')->get();

        // Evidence with no responsible teacher recorded — reported, never guessed.
        $assigned = $assignments->map(fn($a) => $a->section_id . ':' . $a->subject_id . ':' . $a->grading_period)->flip();
        $unassigned = Assessment::where('school_year', $schoolYear)
            ->select('section_id', 'subject_id', 'grading_period', DB::raw('COUNT(*) as items'))
            ->groupBy('section_id', 'subject_id', 'grading_period')
            ->get()
            ->reject(fn($row) => $assigned->has($row->section_id . ':' . $row->subject_id . ':' . $row->grading_period))
            ->map(function ($row) use ($schoolYear) {
                $uploaders = Assessment::where('school_year', $schoolYear)->where('section_id', $row->section_id)
                    ->where('subject_id', $row->subject_id)->where('grading_period', $row->grading_period)
                    ->whereNotNull('uploaded_by')->distinct()->pluck('uploaded_by');

                return [
                    'section'   => Section::find($row->section_id),
                    'subject'   => Subject::find($row->subject_id),
                    'term'      => (int) $row->grading_period,
                    'items'     => (int) $row->items,
                    'uploaders' => User::whereIn('id', $uploaders)->get()->map(fn($u) => $u->full_name)->all(),
                ];
            })->values();

        $history = TeachingAssignmentHistory::with(['oldTeacher', 'newTeacher', 'changedBy', 'section', 'subject'])
            ->where('school_year', $schoolYear)->orderByDesc('changed_at')->orderByDesc('id')->limit(100)->get();

        return view('admin.teaching-assignments', [
            'years'       => $years,
            'schoolYear'  => $schoolYear,
            'editable'    => $this->yearIsEditable($schoolYear),
            'sections'    => $sections,
            'assignments' => $assignments,
            'teachers'    => $teachers,
            'subjects'    => $subjects,
            'terms'       => AcademicTerm::termNumbers(),
            'unassigned'  => $unassigned,
            'history'     => $history,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'    => $this->teacherRule(),
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'terms'      => ['required', 'array', 'min:1'],
            'terms.*'    => ['integer', Rule::in(AcademicTerm::termNumbers())],
        ], $this->messages());

        $section = Section::findOrFail($validated['section_id']);
        $subject = Subject::findOrFail($validated['subject_id']);
        $teacher = User::findOrFail($validated['user_id']);
        $back = redirect()->route('admin.teaching-assignments', ['school_year' => $section->school_year]);

        if (!$this->yearIsEditable($section->school_year)) {
            return $back->withErrors(['section_id' => "School Year {$section->school_year} is before the active school year; its assignments are historical and are not changed here."])->withInput();
        }

        $terms = array_values(array_unique(array_map('intval', $validated['terms'])));
        sort($terms);
        $problems = [];
        foreach ($terms as $term) {
            if (!Subject::forSection($section, $term)->whereKey($subject->id)->exists()) {
                $problems[] = "{$subject->name} is not applicable to {$section->name} in Term {$term} (check the subject's grade level, track and Terms Taught in Admin > Subjects).";
                continue;
            }
            $existing = TeachingAssignment::with('teacher')->where('section_id', $section->id)
                ->where('subject_id', $subject->id)->where('grading_period', $term)->first();
            if ($existing && $existing->user_id !== $teacher->id) {
                $problems[] = "{$subject->name} in {$section->name}, Term {$term} is already assigned to {$existing->teacher->full_name}. Use Edit / Change Teacher on that assignment — it is never replaced silently.";
            }
        }
        if ($problems) {
            return $back->withErrors(['terms' => implode(' ', $problems)])->withInput();
        }

        $created = DB::transaction(function () use ($terms, $teacher, $section, $subject) {
            $created = 0;
            foreach ($terms as $term) {
                $row = TeachingAssignment::firstOrCreate(
                    ['section_id' => $section->id, 'subject_id' => $subject->id, 'grading_period' => $term],
                    ['user_id' => $teacher->id, 'school_year' => $section->school_year, 'assigned_by' => auth()->id()]
                );
                if ($row->wasRecentlyCreated) {
                    TeachingAssignmentHistory::record($row, TeachingAssignmentHistory::ASSIGNED, null, $teacher->id, null);
                    $created++;
                }
            }

            return $created;
        });

        LogActivity::log('assign_teacher', 'Assigned ' . $teacher->full_name . ' to teach ' . $subject->name . ' in ' . $section->name
            . ' (' . $section->school_year . '), Term(s) ' . implode(', ', $terms), 'teaching_assignments', null);

        return $back->with('success', $created > 0
            ? "Assigned {$teacher->full_name} to {$subject->name} — {$section->name}, Term " . implode(', ', $terms) . '.'
            : 'That assignment already exists — nothing changed.');
    }

    public function edit(TeachingAssignment $assignment)
    {
        $assignment->load(['teacher', 'section', 'subject']);
        abort_unless($this->yearIsEditable($assignment->school_year), 403, 'Assignments of a past school year are historical.');

        $evidence = $this->evidenceCount($assignment);

        return view('admin.teaching-assignment-edit', [
            'assignment' => $assignment,
            'evidence'   => $evidence,
            'teachers'   => $this->eligibleTeachers(),
            'sections'   => Section::where('school_year', $assignment->school_year)->orderBy('grade_level')->orderBy('name')->get(),
            'subjects'   => Subject::orderBy('grade_level')->orderBy('name')->get(),
            'terms'      => AcademicTerm::termNumbers(),
            'history'    => TeachingAssignmentHistory::with(['oldTeacher', 'newTeacher', 'changedBy'])
                ->where('teaching_assignment_id', $assignment->id)->orderByDesc('changed_at')->orderByDesc('id')->get(),
        ]);
    }

    /**
     * Change the teacher and/or (only while the class holds no evidence)
     * correct the section/subject/term. Atomic: the row is locked, its
     * version compared with the one the form was opened with, and every
     * rule re-checked inside the transaction.
     */
    public function update(Request $request, TeachingAssignment $assignment)
    {
        $validated = $request->validate([
            'user_id'        => $this->teacherRule(),
            'section_id'     => ['required', 'integer', 'exists:sections,id'],
            'subject_id'     => ['required', 'integer', 'exists:subjects,id'],
            'grading_period' => ['required', 'integer', Rule::in(AcademicTerm::termNumbers())],
            'reason'         => ['required', 'string', 'min:5', 'max:500'],
            'version'        => ['required', 'string'],
        ], $this->messages() + ['reason.required' => 'Give a reason for this change — it is kept in the assignment history.']);

        $edit = redirect()->route('admin.teaching-assignments.edit', $assignment->id);
        if (!$this->yearIsEditable($assignment->school_year)) {
            return $edit->with('error', "School Year {$assignment->school_year} is historical; its teaching assignments are kept as they were.");
        }

        try {
            $result = DB::transaction(function () use ($validated, $assignment) {
                $row = TeachingAssignment::whereKey($assignment->id)->lockForUpdate()->first();
                if (!$row) {
                    return ['error' => 'This assignment was removed by another Admin after you opened it. Nothing was changed.'];
                }
                if ($this->version($row) !== $validated['version']) {
                    return ['error' => 'This assignment was changed by another Admin after you opened it. Nothing was changed — review the current values and try again.'];
                }

                $newTeacher = User::findOrFail($validated['user_id']);
                $newSection = Section::findOrFail($validated['section_id']);
                $newScope = [(int) $newSection->id, (int) $validated['subject_id'], (int) $validated['grading_period']];
                $oldScope = [(int) $row->section_id, (int) $row->subject_id, (int) $row->grading_period];
                $scopeChanged = $newScope !== $oldScope;
                $teacherChanged = (int) $row->user_id !== (int) $newTeacher->id;

                if (!$scopeChanged && !$teacherChanged) {
                    return ['error' => 'Nothing to change — choose another teacher, or correct the class.'];
                }

                if ($scopeChanged) {
                    if ($this->evidenceCount($row) > 0) {
                        return ['error' => 'This class already holds assessment evidence, so its section, subject and term cannot be changed — that would relabel recorded scores. Only the teacher can be changed.'];
                    }
                    if ($newSection->school_year !== $row->school_year) {
                        return ['error' => 'An assignment cannot be moved to another school year.'];
                    }
                    if (!Subject::forSection($newSection, $newScope[2])->whereKey($newScope[1])->exists()) {
                        return ['error' => 'That subject is not applicable to ' . $newSection->name . ' in Term ' . $newScope[2] . '.'];
                    }
                    $clash = TeachingAssignment::where('section_id', $newScope[0])->where('subject_id', $newScope[1])
                        ->where('grading_period', $newScope[2])->whereKeyNot($row->id)->exists();
                    if ($clash) {
                        return ['error' => 'That section, subject and term already has an assigned teacher.'];
                    }
                }

                $oldUserId = (int) $row->user_id;
                $previous = ['section_id' => $oldScope[0], 'subject_id' => $oldScope[1], 'grading_period' => $oldScope[2]];
                $row->user_id = $newTeacher->id;
                $row->section_id = $newScope[0];
                $row->subject_id = $newScope[1];
                $row->grading_period = $newScope[2];
                $row->assigned_by = auth()->id();
                $row->save();

                if ($scopeChanged) {
                    TeachingAssignmentHistory::record($row, TeachingAssignmentHistory::SCOPE_CORRECTED, $oldUserId, $row->user_id, $validated['reason'], $previous);
                }
                if ($teacherChanged) {
                    TeachingAssignmentHistory::record($row, TeachingAssignmentHistory::REASSIGNED, $oldUserId, $row->user_id, $validated['reason']);
                }

                return ['row' => $row->load(['teacher', 'section', 'subject']), 'old' => User::find($oldUserId), 'scope' => $scopeChanged, 'teacher' => $teacherChanged];
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $result = ['error' => 'That section, subject and term already has an assigned teacher.'];
        }

        if (isset($result['error'])) {
            return $edit->withErrors(['assignment' => $result['error']])->withInput();
        }

        $row = $result['row'];
        LogActivity::log('reassign_teacher', ($result['teacher'] ? 'Replaced ' . $result['old']?->full_name . ' with ' . $row->teacher->full_name : 'Kept ' . $row->teacher->full_name)
            . ' for ' . $row->subject->name . ' in ' . $row->section->name . ' (' . $row->school_year . '), Term ' . $row->grading_period
            . ($result['scope'] ? ' (scope corrected)' : '') . ' — reason: ' . $validated['reason'], 'teaching_assignments', $row->id);

        return redirect()->route('admin.teaching-assignments', ['school_year' => $row->school_year])
            ->with('success', $result['teacher']
                ? "{$row->teacher->full_name} now teaches {$row->subject->name} in {$row->section->name}, Term {$row->grading_period}. Recorded scores are unchanged and keep who entered them."
                : 'Assignment corrected.');
    }

    public function destroy(Request $request, TeachingAssignment $assignment)
    {
        $back = redirect()->route('admin.teaching-assignments', ['school_year' => $assignment->school_year]);
        if (!$this->yearIsEditable($assignment->school_year)) {
            return $back->with('error', "School Year {$assignment->school_year} is historical; its teaching assignments are kept as they were.");
        }
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Give a reason for removing this assignment.']);

        $assignment->load(['teacher', 'section', 'subject']);
        DB::transaction(function () use ($assignment, $request) {
            TeachingAssignmentHistory::record($assignment, TeachingAssignmentHistory::REVOKED, $assignment->user_id, null, $request->input('reason'));
            $assignment->delete();
        });
        LogActivity::log('unassign_teacher', 'Removed ' . $assignment->teacher->full_name . ' from ' . $assignment->subject->name . ' in '
            . $assignment->section->name . ' (' . $assignment->school_year . '), Term ' . $assignment->grading_period
            . ' — recorded evidence is unchanged — reason: ' . $request->input('reason'), 'teaching_assignments', $assignment->id);

        return $back->with('success', 'Assignment removed. Recorded scores are unchanged; nobody can encode this class until another teacher is assigned.');
    }

    /** Assessment items already recorded for exactly this class. */
    private function evidenceCount(TeachingAssignment $a): int
    {
        return Assessment::where('section_id', $a->section_id)->where('subject_id', $a->subject_id)
            ->where('grading_period', $a->grading_period)->where('school_year', $a->school_year)->count();
    }

    /** The row's version as the edit form saw it (optimistic locking). */
    public static function versionOf(TeachingAssignment $a): string
    {
        return sha1(implode('|', [$a->id, $a->user_id, $a->section_id, $a->subject_id, $a->grading_period, optional($a->updated_at)->format('Y-m-d H:i:s.u')]));
    }

    private function version(TeachingAssignment $a): string
    {
        return self::versionOf($a);
    }

    private function eligibleTeachers()
    {
        return User::whereIn('role', User::TEACHING_ROLES)->where('is_active', true)->orderBy('last_name')->orderBy('first_name')->get();
    }

    private function teacherRule(): array
    {
        return ['required', 'integer', Rule::exists('users', 'id')->where(fn($q) => $q->whereIn('role', User::TEACHING_ROLES)->where('is_active', true))];
    }

    private function messages(): array
    {
        return [
            'user_id.exists' => 'Choose an active Adviser or Subject Teacher account.',
            'terms.required' => 'Choose at least one term.',
        ];
    }

    /** The active school year and any later one; never an earlier (historical) year. */
    private function yearIsEditable(string $schoolYear): bool
    {
        return strcmp($schoolYear, Section::activeSchoolYear()) >= 0;
    }
}
