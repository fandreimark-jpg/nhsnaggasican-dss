<?php

namespace App\Services;

/**
 * Guesses which grading component (Written Work / Performance Task /
 * Examination) an uploaded assessment form's column most likely belongs
 * to, based on its header text — per CLAUDE.md's classification examples:
 *
 *   Quiz 1, Quiz 2, Activity 1, Written Activity          -> Written Work
 *   Performance Task 1, Project, Presentation, Practical  -> Performance Task
 *   Exam, Periodical Exam, Final Exam                     -> Examination
 *
 * This is ONLY a guess shown to the adviser for verification — nothing
 * here writes to the database. An unmatched column returns null rather
 * than a fallback guess, per "ambiguous columns must not be silently
 * classified" — the adviser must choose explicitly in that case.
 *
 * Order matters: "Practical Activity" contains "activity" (a Written Work
 * keyword) but is actually a Performance Task, so Performance Task and
 * Examination keywords are checked before the broader Written Work ones.
 */
class AssessmentColumnClassifier
{
    private const PERFORMANCE_TASK_KEYWORDS = [
        'performance task', 'project', 'presentation', 'practical',
    ];

    private const EXAMINATION_KEYWORDS = [
        'periodical exam', 'final exam', 'exam',
    ];

    private const WRITTEN_WORK_KEYWORDS = [
        'written activity', 'quiz', 'activity', 'seatwork', 'assignment',
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
}
