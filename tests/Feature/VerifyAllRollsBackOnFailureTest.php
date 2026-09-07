<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Workflow completion pass" TASK 1e — a half-verified section is worse
 * than an unverified one. The whole batch runs inside one
 * DB::transaction(); if verifyOneGrade() reports failure for ANY row
 * mid-loop, every write from that request — including grades already
 * written earlier in the same loop — must roll back.
 *
 * Forcing a genuine mid-batch failure requires a controlled double for
 * GradingEngine: computeGrade() is called once per student during
 * classification, then again per ELIGIBLE student during the actual
 * verify loop. This double lets every classification call succeed
 * (so both students are classified eligible and the request reaches the
 * transaction) but makes the SECOND student's call inside the verify
 * loop report incomplete evidence — simulating a race between
 * classification and write without needing real concurrency.
 */
class VerifyAllRollsBackOnFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    private function fullyScore(Section $section, Subject $subject, Student $student): void
    {
        foreach (['written_work' => 84, 'performance_task' => 60, 'examination' => 70] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }
    }

    public function test_when_one_student_fails_mid_batch_zero_grades_are_written(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $studentA = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'AAA']);
        $studentB = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'BBB']);
        $this->fullyScore($section, $subject, $studentA);
        $this->fullyScore($section, $subject, $studentB);

        // Calls in order: classify(AAA), classify(BBB), verify(AAA),
        // verify(BBB) — the 4th call is made to fail.
        $this->app->instance(GradingEngine::class, new class extends GradingEngine {
            private int $calls = 0;

            public function computeGrade(Student $student, Subject $subject, Section $section, int $gradingPeriod, string $schoolYear): array
            {
                $this->calls++;
                if ($this->calls === 4) {
                    return [
                        'complete' => false, 'components' => [], 'contributions' => [],
                        'computed_grade' => null, 'transmuted_grade' => null,
                        'transmutation_scheme' => 'do8_2015', 'transmutation_available' => false,
                        'transmutation_provisional' => false, 'transmutation_fallback_scheme' => null,
                    ];
                }
                return parent::computeGrade($student, $subject, $section, $gradingPeriod, $schoolYear);
            }
        });

        $response = $this->actingAs($adviser)->postJson('/adviser/grades/verify-all', [
            'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertStatus(422);
        // studentA's grade was written FIRST in the loop, then studentB's
        // write failed — the transaction must have rolled BOTH back.
        $this->assertDatabaseMissing('grades', ['student_id' => $studentA->id]);
        $this->assertDatabaseMissing('grades', ['student_id' => $studentB->id]);
        $this->assertSame(0, \App\Models\Grade::count());
    }
}
