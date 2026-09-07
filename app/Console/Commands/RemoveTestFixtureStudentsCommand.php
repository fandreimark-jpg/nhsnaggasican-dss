<?php

namespace App\Console\Commands;

use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Student;
use Illuminate\Console\Command;

/**
 * One-off data repair — NOT a migration, since this is wrong data on one
 * dev database, not a schema change (per CLAUDE.md's database safety
 * rules, and matching the earlier dss:fix-assessment-max command's
 * precedent).
 *
 * Removes students created ad-hoc via tinker during manual HTTP
 * verification for earlier task prompts in this project's history —
 * never through Admin > Students import, and never by a seeder
 * (DatabaseSeeder seeds zero students by design; see its own doc
 * comment). Their names ("Alpha, Low" / "Beta, Moderate" / "Gamma,
 * High") encode the risk level they were built to demonstrate, which is
 * exactly what gave them away as fixtures mixed into the real demo data.
 *
 * Targets the exact, explicitly-known LRNs rather than a generic
 * name-pattern heuristic — a heuristic like "looks like a test name"
 * risks misfiring against a real future student, where a hardcoded list
 * built from this session's own history cannot.
 */
class RemoveTestFixtureStudentsCommand extends Command
{
    protected $signature = 'dss:remove-test-fixture-students';

    protected $description = 'Removes ad-hoc test-fixture students (and their grades/scores/risk results/interventions) that were never created by an import or seeder';

    private const FIXTURE_LRNS = ['110000000201', '110000000202', '110000000203'];

    public function handle(): int
    {
        $students = Student::whereIn('lrn', self::FIXTURE_LRNS)->get();

        if ($students->isEmpty()) {
            $this->info('No matching fixture students found — nothing to do.');
            return self::SUCCESS;
        }

        $rows = $students->map(fn(Student $s) => [
            $s->id,
            $s->lrn,
            $s->last_name . ', ' . $s->first_name,
            Grade::where('student_id', $s->id)->count(),
            RiskResult::where('student_id', $s->id)->count(),
            AssessmentScore::where('student_id', $s->id)->count(),
            Intervention::where('student_id', $s->id)->count(),
        ])->all();

        $this->warn('The following students will be PERMANENTLY deleted, along with their grades, assessment scores, risk results, and interventions (all cascade on student_id):');
        $this->table(['ID', 'LRN', 'Name', 'Grades', 'Risk Results', 'Assessment Scores', 'Interventions'], $rows);

        if (!$this->confirm('Delete these ' . $students->count() . ' student(s) and all related records?', false)) {
            $this->warn('Cancelled — nothing deleted.');
            return self::SUCCESS;
        }

        foreach ($students as $student) {
            $student->delete();
        }

        $this->info('Deleted ' . $students->count() . ' student(s) and their related records.');

        return self::SUCCESS;
    }
}
