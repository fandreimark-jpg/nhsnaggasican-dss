<?php

namespace App\Console\Commands;

use App\Models\AcademicTerm;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\ReportSubmission;
use App\Models\RiskResult;
use App\Models\Student;
use Illuminate\Console\Command;

/**
 * Reports orphaned/inconsistent state WITHOUT changing anything — see
 * the "live in-term risk + stale data guard" prompt (Problem 1b). Every
 * check here describes a shape that could only be produced by
 * hand-written SQL bypassing this app's foreign-key constraints (a
 * normal delete through Eloquent, or php artisan dss:truncate, keeps
 * these consistent by construction) — the exact mistake that caused the
 * original incident. Meant to be run before a demo, or any time
 * something on screen looks wrong.
 */
class CheckIntegrityCommand extends Command
{
    protected $signature = 'dss:check-integrity';

    protected $description = 'Reports orphaned/stale data (risk results, submissions, interventions, assessment scores, students) without changing anything. Exits non-zero if anything is found.';

    public function handle(): int
    {
        $findings = [];

        // 1. Risk results whose term has no grades. Iterated from
        // risk_results itself, not academic_terms — a stale row can
        // outlive its academic_terms row too if that was wiped alongside
        // grades, and this must still catch it.
        foreach (RiskResult::query()->select('school_year')->distinct()->pluck('school_year') as $schoolYear) {
            foreach (AcademicTerm::staleRiskTerms($schoolYear) as $term) {
                $count = RiskResult::where('school_year', $schoolYear)->where('grading_period', $term)->count();
                $findings[] = [
                    'Risk results with no surviving grades',
                    "{$count} row(s) — school year {$schoolYear}, Term {$term}",
                    'php artisan dss:truncate --level=academic',
                ];
            }
        }

        // 2. Report submissions whose term has no grades.
        foreach (ReportSubmission::query()->select('school_year', 'grading_period')->distinct()->get() as $row) {
            $hasGrades = Grade::where('school_year', $row->school_year)->where('grading_period', $row->grading_period)->exists();
            if (!$hasGrades) {
                $count = ReportSubmission::where('school_year', $row->school_year)->where('grading_period', $row->grading_period)->count();
                $findings[] = [
                    'Report submissions with no surviving grades',
                    "{$count} row(s) — school year {$row->school_year}, Term {$row->grading_period}",
                    'php artisan dss:truncate --level=academic',
                ];
            }
        }

        // 3. Interventions whose risk_result_id points at a missing row.
        $orphanedInterventions = Intervention::whereNotNull('risk_result_id')->whereDoesntHave('riskResult')->count();
        if ($orphanedInterventions > 0) {
            $findings[] = [
                'Interventions with a missing risk_result_id',
                "{$orphanedInterventions} row(s)",
                'php artisan dss:truncate --level=academic',
            ];
        }

        // 4. Assessment scores whose assessment no longer exists.
        $orphanedScores = AssessmentScore::whereDoesntHave('assessment')->count();
        if ($orphanedScores > 0) {
            $findings[] = [
                'Assessment scores with a missing assessment',
                "{$orphanedScores} row(s)",
                'php artisan dss:truncate --level=assessments',
            ];
        }

        // 5. Students whose section no longer exists.
        $orphanedStudents = Student::whereDoesntHave('section')->count();
        if ($orphanedStudents > 0) {
            $findings[] = [
                'Students with a missing section',
                "{$orphanedStudents} row(s)",
                'Reassign via Admin > Students, or remove them manually — no automatic command (this touches master data, never auto-resolved).',
            ];
        }

        if (empty($findings)) {
            $this->info('No orphaned or inconsistent data found.');
            return self::SUCCESS;
        }

        $this->error(count($findings) . ' issue(s) found:');
        $this->table(['Issue', 'Detail', 'To resolve'], $findings);

        return self::FAILURE;
    }
}
