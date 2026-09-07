<?php

namespace App\Console\Commands;

use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\GradingEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * TASK 4 of "DO 015 grading weights" — every `grades` row already in the
 * database was computed under the OLD flat 25/50/25 weights. After the
 * subject_group_weights/exam_role_shares rework, a verified grade's
 * `computed_grade`/`grade` (transmuted) may now disagree with what the
 * screens show for that same student/subject/term. This recomputes and
 * updates VERIFIED grades only (is_verified — see GradeController::
 * verifyComputedGrade()) for a given school year and term, prints a
 * before-and-after table, and requires confirmation before writing
 * anything. Never run automatically as part of a migration — rewriting
 * official grades is a decision a person makes, not a side effect of
 * deploying code.
 */
class RecomputeGradesCommand extends Command
{
    protected $signature = 'dss:recompute-grades {school_year : e.g. 2026-2027} {grading_period : 1, 2, or 3}';

    protected $description = 'Recompute verified grades for a school year/term under the current grading weights and, on confirmation, update them. Reports every change before applying anything.';

    public function handle(GradingEngine $gradingEngine): int
    {
        $schoolYear = $this->argument('school_year');
        $gradingPeriod = (int) $this->argument('grading_period');

        if (!in_array($gradingPeriod, [1, 2, 3], true)) {
            $this->error('grading_period must be 1, 2, or 3.');
            return self::FAILURE;
        }

        $grades = Grade::where('school_year', $schoolYear)
            ->where('grading_period', $gradingPeriod)
            ->where('is_verified', true)
            ->with(['student', 'subject'])
            ->get();

        if ($grades->isEmpty()) {
            $this->info("No verified grades found for school year {$schoolYear}, Term {$gradingPeriod}.");
            return self::SUCCESS;
        }

        // Cache Section lookups — many grades share the same section.
        $sectionsById = [];
        $sectionFor = function (int $sectionId) use (&$sectionsById) {
            return $sectionsById[$sectionId] ??= Section::find($sectionId);
        };

        $rows = [];
        $toApply = [];
        $largestMovement = 0.0;
        $passingChanges = 0;

        foreach ($grades as $grade) {
            $student = $grade->student;
            $subject = $grade->subject;
            $section = $sectionFor($grade->section_id);

            if (!$student || !$subject || !$section) {
                $rows[] = [$grade->id, '(missing student/subject/section)', '—', '—', '—', '—', 'SKIPPED — orphaned row'];
                continue;
            }

            $result = $gradingEngine->computeGrade($student, $subject, $section, $gradingPeriod, $schoolYear);
            $studentName = $student->last_name . ', ' . $student->first_name;

            if (!$result['complete'] || !$result['transmutation_available']) {
                $reason = !$result['complete']
                    ? 'assessment evidence no longer computes as complete'
                    : 'no transmuted grade available for the ' . $result['transmutation_scheme'] . ' scheme';

                $rows[] = [
                    $studentName, $subject->name,
                    number_format((float) $grade->computed_grade, 2), '—',
                    number_format((float) $grade->grade, 2), '—',
                    'SKIPPED — ' . $reason,
                ];
                continue;
            }

            $oldOfficial = (float) $grade->grade;
            $newOfficial = (float) $result['transmuted_grade'];
            $oldComputed = (float) $grade->computed_grade;
            $newComputed = (float) $result['computed_grade'];
            // TASK 3 of "unblock verification" — a recompute that lands on
            // a REAL scheme match (no longer provisional) or newly needs
            // the fallback (was real, now provisional) is a change worth
            // applying even if the numbers happen to match, so the
            // is_provisional flag itself stays accurate and findable.
            $oldProvisional = (bool) $grade->is_provisional;
            $newProvisional = (bool) ($result['transmutation_provisional'] ?? false);
            $newProvisionalScheme = $newProvisional ? $result['transmutation_fallback_scheme'] : null;
            $unchanged = abs($oldOfficial - $newOfficial) < 0.005
                && abs($oldComputed - $newComputed) < 0.005
                && $oldProvisional === $newProvisional;

            $passingFlipped = ($oldOfficial >= 75.0) !== ($newOfficial >= 75.0);
            if ($passingFlipped) {
                $passingChanges++;
            }
            $largestMovement = max($largestMovement, abs($newOfficial - $oldOfficial));

            $status = $unchanged ? 'unchanged' : ($passingFlipped ? 'CHANGED — passing status flips' : 'changed');
            if ($newProvisional && !$oldProvisional) {
                $status .= ' (now PROVISIONAL)';
            } elseif (!$newProvisional && $oldProvisional) {
                $status .= ' (provisional cleared)';
            }

            $rows[] = [
                $studentName, $subject->name,
                number_format($oldComputed, 2), number_format($newComputed, 2),
                number_format($oldOfficial, 2), number_format($newOfficial, 2),
                $status,
            ];

            if (!$unchanged) {
                $toApply[] = [
                    'grade' => $grade,
                    'computed_grade' => $newComputed,
                    'official_grade' => $newOfficial,
                    'is_provisional' => $newProvisional,
                    'provisional_scheme' => $newProvisionalScheme,
                ];
            }
        }

        $this->table(
            ['Student', 'Subject', 'Old Computed', 'New Computed', 'Old Official', 'New Official', 'Status'],
            $rows
        );

        $this->newLine();
        $this->info("Grades affected: {$this->pluralCount(count($toApply))} of {$grades->count()} verified grade(s) checked.");
        $this->info('Largest movement: ' . number_format($largestMovement, 2) . ' point(s).');
        $this->info("Passing-status changes: {$this->pluralCount($passingChanges)}.");

        if (empty($toApply)) {
            $this->info('Nothing to update.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Apply these ' . count($toApply) . ' change(s) to verified grades? This rewrites the official grade.')) {
            $this->warn('Cancelled — no changes made.');
            return self::SUCCESS;
        }

        foreach ($toApply as $change) {
            $change['grade']->update([
                'computed_grade'     => round($change['computed_grade'], 2),
                'grade'              => round($change['official_grade'], 2),
                'is_provisional'     => $change['is_provisional'],
                'provisional_scheme' => $change['provisional_scheme'],
            ]);
        }

        // ActivityLog (see App\Helpers\LogActivity) attributes every entry
        // to an authenticated web-session user, which this CLI-only
        // command never has — the standard Laravel log is the audit
        // trail here instead, alongside this command's own console output.
        Log::info('dss:recompute-grades applied ' . count($toApply) . ' change(s) for school year ' . $schoolYear . ', Term ' . $gradingPeriod . '.');

        $this->info('Updated ' . count($toApply) . ' grade(s).');
        return self::SUCCESS;
    }

    private function pluralCount(int $count): string
    {
        return $count . ' grade' . ($count === 1 ? '' : 's');
    }
}
