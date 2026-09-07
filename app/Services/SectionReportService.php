<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Section;

/**
 * The section/grade/submission overview query shared by Admin and
 * Principal's Reports pages (both entirely read-only, same content —
 * unlike the dashboard, CLAUDE.md doesn't call for anything
 * Principal-specific here, just for Principal to HAVE a Reports page).
 * Extracted so the query logic exists in exactly one place instead of
 * being copy-pasted into a second controller.
 */
class SectionReportService
{
    /** @return array{sections: \Illuminate\Support\Collection, gradeLevels: \Illuminate\Support\Collection} */
    public function getFilteredSections(?string $gradeLevel, ?string $sectionSearch): array
    {
        $query = Section::with([
            'adviser',
            'track',
            'specialization',
            'students.grades',
            'students.riskResults',
            'reportSubmissions',
        ])->orderBy('grade_level');

        if ($gradeLevel) {
            $query->where('grade_level', $gradeLevel);
        }

        if ($sectionSearch) {
            $query->where('name', 'like', '%' . $sectionSearch . '%');
        }

        $sections = $query->get();

        $gradeLevels = Section::select('grade_level')
            ->distinct()
            ->orderBy('grade_level')
            ->pluck('grade_level');

        // This page can span more than one school year, unlike the
        // single-active-year dashboards — keyed per year so the warning
        // can name exactly which year/term combination is affected. See
        // AcademicTerm::staleRiskTerms() and the "live in-term risk +
        // stale data guard" prompt.
        $staleRiskBySchoolYear = [];
        foreach ($sections->pluck('school_year')->unique() as $schoolYear) {
            $stale = AcademicTerm::staleRiskTerms($schoolYear);
            if (!empty($stale)) {
                $staleRiskBySchoolYear[$schoolYear] = $stale;
            }
        }

        return compact('sections', 'gradeLevels', 'staleRiskBySchoolYear');
    }
}
