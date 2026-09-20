<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 4 of "DO 015 grading weights" — `dss:recompute-grades` recomputes
 * VERIFIED grades (is_verified) for a school year/term under the CURRENT
 * grading weights and reports a before/after table, applying nothing
 * until confirmed.
 */
// NOTE ('SSHS ECR grading correction', 2026-09-20): these Grade 12
// sections carry an EXPLICIT k12_2013 curriculum because this file's
// intent is the DO 8, s. 2015 (legacy) path. An unset curriculum in
// SY 2026-2027 now resolves to DO 015 for both grade levels.
class RecomputeGradesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
    }

    private function scoreItem(Subject $subject, Section $section, Student $student, string $component, float $earned, float $max): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_recomputes_and_applies_a_real_movement_on_confirmation(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $adviser = User::factory()->create();

        // WW=100, PT=70, Exam=50 — new do015_2026 core_academic weights
        // (20/50/30) give 100*.2+70*.5+50*.3 = 70.0 exactly, landing on
        // do015_2026's passing anchor band (70.00-71.17 -> 75). Old flat
        // 25/50/25 would have given 72.5, transmuted differently under
        // the OLD code's assumptions — simulated here as the stale
        // "before" row.
        $this->scoreItem($subject, $section, $student, 'written_work', 100, 100);
        $this->scoreItem($subject, $section, $student, 'performance_task', 70, 100);
        $this->scoreItem($subject, $section, $student, 'examination', 50, 100);

        $grade = Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 85.00, 'computed_grade' => 72.50, 'is_verified' => true, 'verified_at' => now(),
        ]);

        $this->artisan('dss:recompute-grades', ['school_year' => '2026-2027', 'grading_period' => 1])
            ->expectsConfirmation('Apply these 1 change(s) to verified grades? This rewrites the official grade.', 'yes')
            ->assertExitCode(0);

        $grade->refresh();
        $this->assertEquals(70.0, (float) $grade->computed_grade);
        $this->assertEquals(75.0, (float) $grade->grade);
    }

    public function test_declining_confirmation_makes_no_changes(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $adviser = User::factory()->create();

        $this->scoreItem($subject, $section, $student, 'written_work', 100, 100);
        $this->scoreItem($subject, $section, $student, 'performance_task', 70, 100);
        $this->scoreItem($subject, $section, $student, 'examination', 50, 100);

        $grade = Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 85.00, 'computed_grade' => 72.50, 'is_verified' => true, 'verified_at' => now(),
        ]);

        $this->artisan('dss:recompute-grades', ['school_year' => '2026-2027', 'grading_period' => 1])
            ->expectsConfirmation('Apply these 1 change(s) to verified grades? This rewrites the official grade.', 'no')
            ->assertExitCode(0);

        $grade->refresh();
        $this->assertEquals(72.50, (float) $grade->computed_grade);
        $this->assertEquals(85.00, (float) $grade->grade);
    }

    public function test_unchanged_grades_report_nothing_to_update_without_asking_for_confirmation(): void
    {
        // Grade 12 stays on do8_2015 25/50/25 — unchanged by this task,
        // so a verified grade recomputes to the exact same values.
        $section = Section::factory()->create(['curriculum' => 'k12_2013', 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $adviser = User::factory()->create();

        $this->scoreItem($subject, $section, $student, 'written_work', 84, 100);
        $this->scoreItem($subject, $section, $student, 'performance_task', 60, 100);
        $this->scoreItem($subject, $section, $student, 'examination', 70, 100);

        Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 80.00, 'computed_grade' => 68.50, 'is_verified' => true, 'verified_at' => now(),
        ]);

        $this->artisan('dss:recompute-grades', ['school_year' => '2026-2027', 'grading_period' => 1])
            ->doesntExpectOutput('Cancelled — no changes made.')
            ->assertExitCode(0);
    }

    public function test_a_grade_that_no_longer_computes_as_complete_is_skipped_not_blanked(): void
    {
        $section = Section::factory()->create(['curriculum' => 'k12_2013', 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $adviser = User::factory()->create();

        // No assessment evidence at all for this term — computeGrade()
        // will report incomplete. A verified grade with no surviving
        // evidence must be left exactly as-is, never blanked.
        $grade = Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 80.00, 'computed_grade' => 68.50, 'is_verified' => true, 'verified_at' => now(),
        ]);

        $this->artisan('dss:recompute-grades', ['school_year' => '2026-2027', 'grading_period' => 1])
            ->assertExitCode(0);

        $grade->refresh();
        $this->assertEquals(68.50, (float) $grade->computed_grade);
        $this->assertEquals(80.00, (float) $grade->grade);
    }
}
