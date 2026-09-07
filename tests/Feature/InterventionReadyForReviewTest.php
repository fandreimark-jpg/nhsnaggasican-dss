<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 2 of "bulk dialog and intervention closure" — a delivered,
 * still-open intervention whose student has recovered to On Track is a
 * SIGNAL the Principal may want to close it, never an automatic close.
 * See InTermStatusService::isReadyForReview().
 */
class InterventionReadyForReviewTest extends TestCase
{
    use RefreshDatabase;

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned, float $max = 20): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => $component . '-' . uniqid(),
            'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_prompt_appears_when_delivered_intervention_students_status_recovers_to_on_track(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subject, $student, $component, 18); // 90% each -> On Track
        }

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved', 'delivered_at' => now(), 'delivered_by' => $principal->id,
        ]);

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertSee('Student is now On Track. Close this intervention?');
        $response->assertSee('Completed');
        $response->assertSee('Monitoring');
    }

    public function test_prompt_does_not_appear_for_a_student_still_at_risk(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->score($section, $subject, $student, 'written_work', 8); // 40% -> below target
        $this->score($section, $subject, $student, 'performance_task', 8); // 40% -> below target -> At Risk

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved', 'delivered_at' => now(), 'delivered_by' => $principal->id,
        ]);

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertDontSee('Student is now On Track. Close this intervention?');
    }

    public function test_prompt_does_not_appear_when_not_yet_delivered(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subject, $student, $component, 18);
        }

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved', 'delivered_at' => null,
        ]);

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertDontSee('Student is now On Track. Close this intervention?');
    }

    public function test_marking_completed_from_the_prompt_records_the_decision_and_removes_the_prompt(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subject, $student, $component, 18);
        }

        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved', 'delivered_at' => now(), 'delivered_by' => $principal->id,
        ]);

        $this->actingAs($principal)->put("/principal/interventions/{$intervention->id}", ['status' => 'completed']);

        $intervention->refresh();
        $this->assertSame('completed', $intervention->status);
        $this->assertSame($principal->id, $intervention->decided_by);
        $this->assertNotNull($intervention->decided_at);

        $response = $this->actingAs($principal)->get('/principal/interventions');
        $response->assertDontSee('Student is now On Track. Close this intervention?');
    }

    public function test_dashboard_and_filter_counts_agree_and_filter_shows_only_ready_rows(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $ready = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ReadyStudent']);
        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subject, $ready, $component, 18);
        }
        Intervention::factory()->create([
            'student_id' => $ready->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'in_progress', 'delivered_at' => now(),
        ]);

        $notReady = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'NotReadyStudent']);
        $this->score($section, $subject, $notReady, 'written_work', 8);
        $this->score($section, $subject, $notReady, 'performance_task', 8);
        Intervention::factory()->create([
            'student_id' => $notReady->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'in_progress', 'delivered_at' => now(),
        ]);

        $dashboard = $this->actingAs($principal)->get('/principal/dashboard');
        $dashboard->assertOk();
        $dashboard->assertSee('Ready to Close');

        $filtered = $this->actingAs($principal)->get('/principal/interventions?ready_for_review=1');
        $filtered->assertOk();
        $filtered->assertSee('ReadyStudent');
        $filtered->assertDontSee('NotReadyStudent');
    }
}
