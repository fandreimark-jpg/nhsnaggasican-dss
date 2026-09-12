<?php

namespace App\Services;

use App\Models\Section;
use App\Models\Subject;

/**
 * "Do not import formula results blindly" -- compares the Grade 12
 * workbook's OWN computed Term Grade (Grade12EcrReaderService::
 * excelComputedGrades(), read as a reference value only, never written
 * as a student's official grade) against GradingEngine's independently
 * computed result for the SAME imported raw assessment scores. Run
 * AFTER import() has written the raw scores -- GradingEngine reads from
 * assessment_scores/assessments, not from the Excel file, so there is
 * nothing to compare until the raw data actually exists in the database.
 *
 * A MISMATCH is never resolved automatically in either direction: the
 * DSS's own computed grade stays what GradingEngine produced (it is
 * never overwritten by the Excel figure), and the discrepancy is
 * surfaced for a human to look at, not hidden.
 */
class Grade12DiscrepancyChecker
{
    public function __construct(
        private Grade12EcrReaderService $reader = new Grade12EcrReaderService(),
        private GradingEngine $engine = new GradingEngine()
    ) {
    }

    /**
     * @return array<int, array{
     *     student_id: int, name: string, excel_term_grade: ?float,
     *     dss_term_grade: ?float, status: string,
     * }>
     */
    public function compare(string $filePath, Section $section, Subject $subject, int $gradingPeriod, string $schoolYear): array
    {
        $reference = $this->reader->excelComputedGrades($filePath, $gradingPeriod, $section->id);
        $results = [];

        foreach ($reference as $entry) {
            if (!$entry['student']) {
                continue; // unresolved learner -- nothing in the DSS to compare against
            }

            $dss = $this->engine->computeGrade($entry['student'], $subject, $section, $gradingPeriod, $schoolYear);
            $dssGrade = $dss['transmuted_grade'] ?? null;
            $excelGrade = $entry['term_grade'];

            $status = match (true) {
                $excelGrade === null || $dssGrade === null => 'incomplete',
                abs($excelGrade - $dssGrade) < 0.01 => 'match',
                default => 'mismatch',
            };

            $results[] = [
                'student_id'       => $entry['student']->id,
                'name'             => $entry['student']->last_name . ', ' . $entry['student']->first_name,
                'excel_term_grade' => $excelGrade,
                'dss_term_grade'   => $dssGrade,
                'status'           => $status,
            ];
        }

        return $results;
    }
}
