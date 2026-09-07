<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Workflow completion pass" TASK 4 — a persistent "lifted from X" marker
 * directly under the Transmuted number whenever transmutation raised a
 * sub-75 computed grade to a reported grade of 75 or above, on both the
 * Adviser Assessments and Principal Students tables. No value changes —
 * this is purely a legibility fix.
 */
class ComputedTransmutedClarityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_adviser_assessments_shows_lifted_from_marker_when_transmutation_raises_a_sub_75_grade(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'LiftedCase']);

        // WW=84, PT=60, Exam=70 -> computed 68.5 (below 75), transmuted 80
        // (do8_2015 68.00-69.59 band) — a real "lifted" case.
        $this->score($section, $subject, $student, 'written_work', 84);
        $this->score($section, $subject, $student, 'performance_task', 60);
        $this->score($section, $subject, $student, 'examination', 70);

        $response = $this->actingAs($adviser)->get('/adviser/assessments?period=1&subject_id=' . $subject->id);
        $response->assertOk();
        $response->assertSee('lifted from 68.50', false);
        $response->assertSee('Raw weighted evidence. This is what In-Term Status uses.', false);
        $response->assertSee('The reported grade after DepEd', false);
    }

    public function test_no_lifted_marker_when_computed_grade_is_already_at_or_above_75(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'AlreadyPassing']);

        $this->score($section, $subject, $student, 'written_work', 90);
        $this->score($section, $subject, $student, 'performance_task', 90);
        $this->score($section, $subject, $student, 'examination', 90);

        $response = $this->actingAs($adviser)->get('/adviser/assessments?period=1&subject_id=' . $subject->id);
        $response->assertOk();
        $response->assertDontSee('lifted from', false);
    }
}
