<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;

/**
 * The contents of one section's term report AT THE MOMENT it is submitted
 * (2026-10-07). The same figures the Reports page showed live before —
 * per learner: the term average of the grade rows, every subject grade with
 * its official / failing / evidence-changed flags (ClassRecordService::
 * isOfficial(), InTermStatusService::isFailing(), SubjectResultSummary::
 * staleOfficial()) — frozen so later score changes, verification or a new
 * draft never alter what was submitted. No new grading rule lives here.
 */
class ReportSnapshotService
{
    public function build(Section $section, int $term): array
    {
        $students = Student::enrolledIn($section)->orderBy('last_name')->orderBy('first_name')->get();
        $grades = Grade::with('subject:id,name')
            ->where('section_id', $section->id)->where('school_year', $section->school_year)->where('grading_period', $term)
            ->whereIn('student_id', $students->pluck('id'))
            ->get();
        $official = $grades->filter(fn(Grade $g) => ClassRecordService::isOfficial($g))->values();
        $stale = SubjectResultSummary::staleOfficial($official)->pluck('id')->flip();
        $byStudent = $grades->groupBy('student_id');

        return [
            'section'     => ['id' => $section->id, 'name' => $section->name, 'grade_level' => $section->grade_level],
            'school_year' => $section->school_year,
            'term'        => $term,
            'students'    => $students->mapWithKeys(function (Student $s) use ($byStudent, $stale) {
                $rows = $byStudent->get($s->id, collect());

                return [$s->id => [
                    'name'    => $s->last_name . ', ' . $s->first_name,
                    'average' => $rows->isNotEmpty() ? round((float) $rows->avg('grade'), 2) : null,
                    'grades'  => $rows->sortBy(fn(Grade $g) => $g->subject?->name)->map(fn(Grade $g) => [
                        'subject_id' => $g->subject_id,
                        'subject'    => $g->subject?->name ?? 'Subject #' . $g->subject_id,
                        'grade'      => $g->grade !== null ? (float) $g->grade : null,
                        'official'   => ClassRecordService::isOfficial($g),
                        'failed'     => ClassRecordService::isOfficial($g) && InTermStatusService::isFailing($g),
                        'stale'      => isset($stale[$g->id]),
                    ])->values()->all(),
                ]];
            })->all(),
        ];
    }

    /**
     * Figures for one learner from a stored snapshot, optionally one subject.
     *
     * @return array{average: ?float, passed: int, failed: int, not_official: int, subjects: int, failed_subjects: list<array>, stale_subjects: list<string>}
     */
    public static function learnerFigures(?array $entry, ?int $subjectId = null): array
    {
        $grades = collect($entry['grades'] ?? [])->when($subjectId, fn($c) => $c->where('subject_id', $subjectId))->values();
        $official = $grades->where('official', true);
        $failed = $official->where('failed', true);

        return [
            'average'         => $subjectId ? ($grades->first()['grade'] ?? null) : ($entry['average'] ?? null),
            'subjects'        => $grades->count(),
            'passed'          => $official->count() - $failed->count(),
            'failed'          => $failed->count(),
            'not_official'    => $grades->count() - $official->count(),
            'failed_subjects' => $failed->values()->all(),
            'stale_subjects'  => $official->where('stale', true)->pluck('subject')->values()->all(),
        ];
    }
}
