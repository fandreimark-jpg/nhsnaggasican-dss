<?php

namespace Tests\Feature;

use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Problem 1b of "live in-term risk + stale data guard": dss:truncate
 * (the sanctioned replacement for hand-written SQL) and
 * dss:check-integrity (reports orphaned state without changing
 * anything, non-zero exit when something is found).
 */
class DataIntegrityCommandsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SET FOREIGN_KEY_CHECKS is MySQL-only syntax. SQLite's equivalent,
     * `PRAGMA foreign_keys`, cannot be toggled while a transaction is
     * open — and RefreshDatabase wraps every test in one — so it silently
     * has no effect here. `PRAGMA defer_foreign_keys` is the SQLite
     * pragma designed to work mid-transaction: it delays FK enforcement
     * until commit, which (under RefreshDatabase, which only ever rolls
     * back) never happens.
     */
    private function withForeignKeyChecksDisabled(\Closure $callback): void
    {
        $driver = DB::connection()->getDriverName();
        match ($driver) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=0'),
            'sqlite' => DB::statement('PRAGMA defer_foreign_keys = ON'),
            default => null,
        };
        $callback();
        match ($driver) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=1'),
            'sqlite' => DB::statement('PRAGMA defer_foreign_keys = OFF'),
            default => null,
        };
    }

    public function test_check_integrity_passes_clean_on_an_empty_database(): void
    {
        $this->artisan('dss:check-integrity')
            ->expectsOutputToContain('No orphaned or inconsistent data found.')
            ->assertExitCode(0);
    }

    public function test_check_integrity_detects_a_risk_result_with_no_surviving_grades(): void
    {
        $student = Student::factory()->create();
        RiskResult::create(['student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 70, 'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now()]);

        $this->artisan('dss:check-integrity')->assertExitCode(1);
    }

    public function test_check_integrity_detects_a_report_submission_with_no_surviving_grades(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        \App\Models\ReportSubmission::create([
            'section_id' => $section->id, 'submitted_by' => $section->adviser_id,
            'grading_period' => 1, 'status' => 'submitted', 'school_year' => '2026-2027', 'submitted_at' => now(),
        ]);

        $this->artisan('dss:check-integrity')->assertExitCode(1);
    }

    public function test_check_integrity_detects_an_intervention_with_a_missing_risk_result(): void
    {
        $intervention = Intervention::factory()->create();
        // Force an orphaned FK the way raw SQL (bypassing the app's
        // normal onDelete('set null')) would — not reachable through
        // normal Eloquent deletes.
        $this->withForeignKeyChecksDisabled(function () use ($intervention) {
            DB::table('interventions')->where('id', $intervention->id)->update(['risk_result_id' => 999999]);
        });

        $this->artisan('dss:check-integrity')->assertExitCode(1);
    }

    public function test_check_integrity_detects_an_assessment_score_with_a_missing_assessment(): void
    {
        $score = AssessmentScore::factory()->create();
        $this->withForeignKeyChecksDisabled(function () use ($score) {
            DB::table('assessment_scores')->where('id', $score->id)->update(['assessment_id' => 999999]);
        });

        $this->artisan('dss:check-integrity')->assertExitCode(1);
    }

    public function test_check_integrity_detects_a_student_with_a_missing_section(): void
    {
        $student = Student::factory()->create();
        $this->withForeignKeyChecksDisabled(function () use ($student) {
            DB::table('students')->where('id', $student->id)->update(['section_id' => 999999]);
        });

        $this->artisan('dss:check-integrity')->assertExitCode(1);
    }

    private function createGrade(): Grade
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        return Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'grade' => 85, 'school_year' => '2026-2027',
        ]);
    }

    public function test_truncate_assessments_level_only_removes_assessment_tables(): void
    {
        $score = AssessmentScore::factory()->create();
        $grade = $this->createGrade();

        $this->artisan('dss:truncate', ['--level' => 'assessments'])
            ->expectsConfirmation('Truncate these tables?', 'yes')
            ->assertExitCode(0);

        $this->assertDatabaseCount('assessment_scores', 0);
        $this->assertDatabaseCount('assessments', 0);
        // grades is NOT part of the assessments level.
        $this->assertDatabaseHas('grades', ['id' => $grade->id]);
    }

    public function test_truncate_academic_level_removes_grades_and_risk_results_together(): void
    {
        $grade = $this->createGrade();
        $student = Student::factory()->create();
        $risk = RiskResult::create(['student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 70, 'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now()]);

        $this->artisan('dss:truncate', ['--level' => 'academic'])
            ->expectsConfirmation('Truncate these tables?', 'yes')
            ->assertExitCode(0);

        $this->assertDatabaseCount('grades', 0);
        $this->assertDatabaseCount('risk_results', 0);

        // The exact incident this task exists to prevent — academic
        // never leaves risk_results behind while removing grades.
        $this->artisan('dss:check-integrity')->assertExitCode(0);
    }

    public function test_truncate_cancels_when_confirmation_is_declined(): void
    {
        $grade = $this->createGrade();

        $this->artisan('dss:truncate', ['--level' => 'academic'])
            ->expectsConfirmation('Truncate these tables?', 'no')
            ->assertExitCode(0);

        $this->assertDatabaseHas('grades', ['id' => $grade->id]);
    }

    public function test_truncate_rejects_an_unknown_level(): void
    {
        $this->artisan('dss:truncate', ['--level' => 'everything'])
            ->assertExitCode(1);
    }

    public function test_truncate_never_touches_students_or_sections(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->artisan('dss:truncate', ['--level' => 'academic'])
            ->expectsConfirmation('Truncate these tables?', 'yes')
            ->assertExitCode(0);

        $this->assertDatabaseHas('students', ['id' => $student->id]);
        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }
}
