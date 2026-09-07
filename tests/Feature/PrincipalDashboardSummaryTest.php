<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Principal dashboard's own summary (intervention status +
 * assessment completion) - the thing that actually differentiates it
 * from Admin's, which never shows this. Computed via 2 aggregate join
 * queries, not a loop, so it stays cheap regardless of school size.
 */
class PrincipalDashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_intervention_counts_are_grouped_by_status_bucket(): void
    {
        Intervention::factory()->create(['status' => 'recommended']);
        Intervention::factory()->create(['status' => 'in_review']);
        Intervention::factory()->create(['status' => 'approved']);
        Intervention::factory()->create(['status' => 'in_progress']);
        Intervention::factory()->create(['status' => 'monitoring']);
        Intervention::factory()->create(['status' => 'completed']);

        $summary = (new DashboardAnalyticsService())->getPrincipalSummary();

        $this->assertSame(3, $summary['under_intervention']); // approved + in_progress + monitoring
        $this->assertSame(2, $summary['awaiting_decision']);  // recommended + in_review
        $this->assertSame(1, $summary['completed_interventions']);
    }

    public function test_assessment_completion_reports_no_data_when_nothing_uploaded(): void
    {
        $summary = (new DashboardAnalyticsService())->getPrincipalSummary();

        $this->assertFalse($summary['assessment_completion']['has_data']);
        $this->assertNull($summary['assessment_completion']['percentage']);
    }

    public function test_assessment_completion_percentage_reflects_actual_vs_expected_scores(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $studentA = Student::factory()->create(['section_id' => $section->id]);
        $studentB = Student::factory()->create(['section_id' => $section->id]);

        $assessment = Assessment::factory()->create([
            'section_id' => $section->id, 'school_year' => '2026-2027', 'grading_period' => 1,
        ]);
        // Only 1 of 2 expected students scored.
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $studentA->id]);

        $summary = (new DashboardAnalyticsService())->getPrincipalSummary();

        $this->assertTrue($summary['assessment_completion']['has_data']);
        $this->assertSame(2, $summary['assessment_completion']['expected']);
        $this->assertSame(1, $summary['assessment_completion']['actual']);
        $this->assertSame(50.0, $summary['assessment_completion']['percentage']);
    }

    public function test_principal_dashboard_renders_the_summary_cards(): void
    {
        $principal = User::factory()->principal()->create();
        Intervention::factory()->create(['status' => 'approved']);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('Under Intervention');
        $response->assertSee('Assessment Completion');
        $response->assertViewHas('under_intervention', 1);
    }

    /**
     * TASK 7 of "terminology, transmutation, and interface cleanup" — the
     * "refresh, not real-time" approach only works if a plain reload
     * actually shows fresh numbers. Every dashboard number is recomputed
     * from the database on each GET (no cache layer anywhere in
     * DashboardAnalyticsService), so recording an intervention and then
     * loading the dashboard again — no websocket, no polling — must show
     * the updated count.
     */
    public function test_recording_an_intervention_as_a_recommendation_only_updates_the_dashboard_count_on_the_next_load(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $before = $this->actingAs($principal)->get('/principal/dashboard');
        $before->assertViewHas('awaiting_decision', 0);

        // "Decision flow, report scoping, and dashboard pass" TASK 1a —
        // recording an intervention now IS the decision by default, so
        // the awaiting-decision count only grows via the explicit
        // deferred-decision checkbox.
        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'           => $student->id,
            'subject_id'           => $subject->id,
            'grading_period'       => 1,
            'recommended_type'     => Intervention::TYPES[0],
            'recommendation_only'  => '1',
        ])->assertRedirect();

        $after = $this->actingAs($principal)->get('/principal/dashboard');
        $after->assertViewHas('awaiting_decision', 1);
    }

    /**
     * "Decision flow, report scoping, and dashboard pass" TASK 1a — the
     * normal path (no checkbox ticked) decides on creation, so it must
     * NOT show up as awaiting a decision.
     */
    public function test_recording_an_intervention_normally_does_not_increase_the_awaiting_decision_count(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => Intervention::TYPES[0],
        ])->assertRedirect();

        $after = $this->actingAs($principal)->get('/principal/dashboard');
        $after->assertViewHas('awaiting_decision', 0);
    }

    public function test_admin_dashboard_does_not_show_principal_only_cards(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertDontSee('Under Intervention');
        $response->assertDontSee('Assessment Completion');
    }
}
