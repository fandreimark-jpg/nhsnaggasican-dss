<?php

namespace App\Services;

/**
 * Guesses which grading component (Written Work / Performance Task /
 * Examination) an uploaded assessment form's column most likely belongs
 * to, based on its header text — per CLAUDE.md's classification examples:
 *
 *   Quiz 1, Quiz 2, Activity 1, Written Activity          -> Written Work
 *   Performance Task 1, Project, Presentation, Practical  -> Performance Task
 *   Exam, Periodical Exam, Final Exam, Summative Test     -> Examination
 *
 * This is ONLY a guess shown to the adviser for verification — nothing
 * here writes to the database. An unmatched column returns null rather
 * than a fallback guess, per "ambiguous columns must not be silently
 * classified" — the adviser must choose explicitly in that case.
 *
 * Order matters: "Practical Activity" contains "activity" (a Written Work
 * keyword) but is actually a Performance Task, so Performance Task and
 * Examination keywords are checked before the broader Written Work ones.
 *
 * "Summative Test" moved to Examination (from Written Work) under DO
 * 015, s. 2026: it's part of the Examination component there, not
 * Written Work — see ExamRoleShare/GradingEngine::examinationPercentage()
 * for how it's weighted within the component. "Long Test" and "Unit
 * Test" were removed from Written Work entirely rather than moved to
 * Examination — schools use those names for both kinds of assessment,
 * and a wrong guess in either direction is worse than asking; they now
 * return null so the adviser classifies them on the Verify screen.
 */
class AssessmentColumnClassifier
{
    private const PERFORMANCE_TASK_KEYWORDS = [
        'performance task', 'project', 'presentation', 'practical',
    ];

    private const EXAMINATION_KEYWORDS = [
        'summative test', 'periodical exam', 'term exam', 'final exam', 'exam',
    ];

    private const WRITTEN_WORK_KEYWORDS = [
        'written work', 'written activity',
        'quiz', 'activity', 'seatwork', 'assignment',
    ];

    public function classify(string $columnName): ?string
    {
        $normalized = strtolower(trim($columnName));

        if ($normalized === '') {
            return null;
        }

        foreach (self::PERFORMANCE_TASK_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'performance_task';
            }
        }

        foreach (self::EXAMINATION_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'examination';
            }
        }

        foreach (self::WRITTEN_WORK_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'written_work';
            }
        }

        return null;
    }

    /**
     * Which Examination role (see ExamRoleShare/assessments.exam_role) a
     * column's header text most likely names — a guess shown to the
     * adviser on the Verify screen for confirmation, same spirit as
     * classify() itself. Only meaningful when classify() returned
     * 'examination'; callers are not required to check that first, but
     * a column that wouldn't classify as Examination at all is unlikely
     * to match any of these either.
     */
    public function classifyExamRole(string $columnName): ?string
    {
        $normalized = strtolower(trim($columnName));

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'summative test 1') || str_contains($normalized, 'st1')) {
            return 'st1';
        }

        if (str_contains($normalized, 'summative test 2') || str_contains($normalized, 'st2')) {
            return 'st2';
        }

        if (str_contains($normalized, 'term exam')) {
            return 'term_exam';
        }

        return null;
    }
}
