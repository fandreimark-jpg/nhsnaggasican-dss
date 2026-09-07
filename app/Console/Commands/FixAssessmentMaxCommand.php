<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use Illuminate\Console\Command;

/**
 * One-off data repair for a bad max_score entered at upload time — NOT a
 * migration, since this is wrong data on one dev database, not a schema
 * change (per CLAUDE.md's database safety rules).
 *
 * Usage:
 *   php artisan dss:fix-assessment-max {subject_id} {grading_period} "Name=Max" ["Name=Max" ...]
 *
 * Example (the General Mathematics Term 1 incident):
 *   php artisan dss:fix-assessment-max 1 1 "Quiz 3=25" "Written Activity 1=15" \
 *       "Performance Task 3=40" "Group Project=30" "Periodical Exam=60"
 *
 * Raising a max_score does NOT retroactively bring back scores that were
 * rejected at import time under the old (wrong) max — those rows were never
 * written to assessment_scores. After running this command, re-import the
 * original file; updateOrCreate() will fill in whatever is now missing.
 */
class FixAssessmentMaxCommand extends Command
{
    protected $signature = 'dss:fix-assessment-max
        {subject : Subject ID}
        {grading_period : Grading period / term (1, 2, or 3)}
        {pairs* : One or more "Assessment Name=NewMax" pairs}';

    protected $description = 'Correct a wrong max_score on existing Assessment rows for one subject/term (data repair, not a migration)';

    public function handle(): int
    {
        $subjectId = (int) $this->argument('subject');
        $gradingPeriod = (int) $this->argument('grading_period');

        $pairs = [];
        foreach ($this->argument('pairs') as $pair) {
            if (!str_contains($pair, '=')) {
                $this->error("Malformed pair \"{$pair}\" — expected \"Assessment Name=NewMax\".");
                return self::FAILURE;
            }

            [$name, $max] = explode('=', $pair, 2);
            $name = trim($name);
            $max = trim($max);

            if (!is_numeric($max) || (float) $max <= 0) {
                $this->error("Invalid max score \"{$max}\" for \"{$name}\" — must be a positive number.");
                return self::FAILURE;
            }

            $pairs[$name] = (float) $max;
        }

        $assessments = Assessment::where('subject_id', $subjectId)
            ->where('grading_period', $gradingPeriod)
            ->whereIn('name', array_keys($pairs))
            ->get()
            ->keyBy('name');

        $missing = array_diff(array_keys($pairs), $assessments->keys()->all());
        if (!empty($missing)) {
            $this->error('No assessment found (for this subject/term) named: ' . implode(', ', $missing));
            return self::FAILURE;
        }

        $rows = [];
        foreach ($pairs as $name => $newMax) {
            $assessment = $assessments[$name];
            $rows[] = [$name, number_format((float) $assessment->max_score, 2), number_format($newMax, 2), $assessment->scores()->count()];
        }

        $this->info("Subject #{$subjectId}, Term {$gradingPeriod} — proposed max_score changes:");
        $this->table(['Assessment', 'Current Max', 'New Max', 'Scores on file'], $rows);

        if (!$this->confirm('Apply these changes?', false)) {
            $this->warn('No changes made.');
            return self::SUCCESS;
        }

        foreach ($pairs as $name => $newMax) {
            $assessments[$name]->update(['max_score' => $newMax]);
        }

        $this->info('Updated ' . count($pairs) . ' assessment(s).');
        $this->line('Note: raising a max does not restore scores that were rejected at import under the old max — re-import the original file to fill those in.');

        return self::SUCCESS;
    }
}
