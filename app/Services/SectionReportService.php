<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\Request;

/**
 * The section/grade/submission overview query shared by Admin and
 * Principal's Reports pages (both entirely read-only, same content —
 * unlike the dashboard, CLAUDE.md doesn't call for anything
 * Principal-specific here, just for Principal to HAVE a Reports page).
 * Extracted so the query logic exists in exactly one place instead of
 * being copy-pasted into a second controller.
 *
 * "Multi-school-year academic history" work order, PART 10 — the page
 * shows ONE school year at a time (the active one by default; any
 * previous year is selectable) and can be narrowed by term, grade
 * level, section, and subject, all working together. Every figure on
 * the page is read from that year's OWN records:
 *
 *  - the roster is the year's enrollment rows (Section::enrolledStudents),
 *    not students.section_id — a promoted learner still appears under
 *    their Grade 11 section when the Grade 11 year is selected;
 *  - grades, risk results, and report submissions are the rows carrying
 *    that school_year — never re-derived from the learner's current
 *    section or from AcademicYear::active().
 *
 * Eager-loaded in one pass per relation (no per-row queries).
 */
class SectionReportService
{
    /**
     * @return array{
     *   sections: \Illuminate\Support\Collection,
     *   gradeLevels: \Illuminate\Support\Collection,
     *   staleRiskBySchoolYear: array,
     *   schoolYear: string, schoolYears: \Illuminate\Support\Collection, isHistoricalYear: bool,
     *   gradingPeriod: ?int, subject: ?Subject, subjects: \Illuminate\Support\Collection,
     *   sectionOptions: \Illuminate\Support\Collection, selectedSectionId: ?int
     * }
     */
    public function getFilteredSections(Request|array|null $filters = null, ?string $legacySectionSearch = null): array
    {
        // Backward-compatible call shapes: (Request), (array), or the
        // original (gradeLevel, sectionSearch) pair.
        if ($filters instanceof Request) {
            $filters = $filters->all();
        } elseif (!is_array($filters)) {
            $filters = ['grade_level' => $filters, 'section_search' => $legacySectionSearch];
        }

        $schoolYear      = AcademicYear::resolveSelected($filters['school_year'] ?? null);
        $gradingPeriod   = isset($filters['grading_period']) && in_array((int) $filters['grading_period'], [1, 2, 3], true)
            ? (int) $filters['grading_period'] : null;
        $gradeLevel      = $filters['grade_level'] ?? null;
        $sectionSearch   = $filters['section_search'] ?? null;
        $selectedSectionId = !empty($filters['section_id']) ? (int) $filters['section_id'] : null;
        $subjectId       = !empty($filters['subject_id']) ? (int) $filters['subject_id'] : null;

        $subject = $subjectId ? Subject::find($subjectId) : null;

        $query = Section::where('school_year', $schoolYear)
            ->with([
                'adviser',
                'track',
                'specialization',
                // The roster AS ENROLLED that year (PART 5/6).
                'enrolledStudents' => fn($q) => $q->orderBy('last_name')->orderBy('first_name'),
                'enrolledStudents.grades' => fn($q) => $q->where('school_year', $schoolYear)
                    ->when($subject, fn($g) => $g->where('subject_id', $subject->id)),
                'enrolledStudents.riskResults' => fn($q) => $q->where('school_year', $schoolYear),
                'reportSubmissions' => fn($q) => $q->where('school_year', $schoolYear),
                'reportSubmissions.adviser',
            ])
            ->orderBy('grade_level')
            ->orderBy('name');

        if ($gradeLevel) {
            $query->where('grade_level', $gradeLevel);
        }

        if ($selectedSectionId) {
            $query->where('id', $selectedSectionId);
        }

        if ($sectionSearch) {
            $query->where('name', 'like', '%' . $sectionSearch . '%');
        }

        $sections = $query->get();

        // Grades/risk results on a student model are hasMany(student_id) —
        // filter each student's loaded grades down to THIS section's rows
        // so a learner who moved sections within a year never shows the
        // other section's grades here.
        foreach ($sections as $section) {
            foreach ($section->enrolledStudents as $student) {
                $student->setRelation('grades', $student->grades->where('section_id', $section->id)->values());
            }
        }

        $gradeLevels = Section::where('school_year', $schoolYear)
            ->select('grade_level')
            ->distinct()
            ->orderBy('grade_level')
            ->pluck('grade_level');

        $sectionOptions = Section::where('school_year', $schoolYear)
            ->orderBy('grade_level')->orderBy('name')
            ->get(['id', 'name', 'grade_level']);

        $subjects = Subject::orderBy('grade_level')->orderBy('type')->orderBy('name')->get(['id', 'name', 'grade_level', 'type']);

        // This page can span more than one school year, unlike the
        // single-active-year dashboards — keyed per year so the warning
        // can name exactly which year/term combination is affected. See
        // AcademicTerm::staleRiskTerms() and the "live in-term risk +
        // stale data guard" prompt.
        $staleRiskBySchoolYear = [];
        $stale = AcademicTerm::staleRiskTerms($schoolYear);
        if (!empty($stale)) {
            $staleRiskBySchoolYear[$schoolYear] = $stale;
        }

        return [
            'sections'              => $sections,
            'gradeLevels'           => $gradeLevels,
            'staleRiskBySchoolYear' => $staleRiskBySchoolYear,
            'schoolYear'            => $schoolYear,
            'schoolYears'           => AcademicYear::selectableSchoolYears(),
            'isHistoricalYear'      => $schoolYear !== Section::activeSchoolYear(),
            'gradingPeriod'         => $gradingPeriod,
            'subject'               => $subject,
            'subjects'              => $subjects,
            'sectionOptions'        => $sectionOptions,
            'selectedSectionId'     => $selectedSectionId,
        ];
    }
}
