<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-9: the Principal's one WRITE surface. The DSS recommends
 * (InterventionRecommender); the Principal decides — recording an
 * intervention here IS that decision by default ("decision flow, report
 * scoping, and dashboard pass" TASK 1: a new intervention starts
 * 'approved', decided by whoever posted it, regardless of what status
 * value is in the request), unless recommendation_only=1 was explicitly
 * posted, in which case it starts 'recommended' with no decider — see
 * Intervention::awaitingDecision(). Direct URL access is tested here,
 * not just nav visibility (see RoleMiddleware's rationale).
 *
 * POST /principal/interventions is reachable from the Students page's
 * Record Intervention modal (see Principal\StudentController), not from
 * this controller's own index() anymore — see the "separate intervention
 * discovery from tracking" prompt. index() lists RECORDED interventions,
 * so most tests here create an Intervention directly rather than relying
 * on at-risk filtering the way this file used to.
 */
class InterventionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_principal_can_view_the_interventions_page(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->get('/principal/interventions')->assertOk();
    }

    public function test_principal_can_record_an_intervention_with_a_risk_result_present(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create();
        $student   = Student::factory()->create(['section_id' => $section->id]);
        $subject   = Subject::factory()->create();

        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'school_year' => $section->school_year,
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'parent_conference',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'recommended_type' => 'parent_conference',
            'status'           => 'approved', // recording IS the decision by default
            'created_by'       => $principal->id,
            'decided_by'       => $principal->id,
        ]);
        $this->assertNotNull(Intervention::first()->risk_result_id);
        $this->assertNotNull(Intervention::first()->decided_at);
    }

    /**
     * The whole point of this task: recording must work identically when
     * no adviser has submitted a term report yet — assessment evidence
     * alone is enough. This is the first thing Task 1 said to test.
     */
    public function test_recording_an_intervention_with_zero_submitted_term_reports_writes_a_null_risk_result_id(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create();
        $student   = Student::factory()->create(['section_id' => $section->id]);
        $subject   = Subject::factory()->create();

        $this->assertSame(0, RiskResult::count());

        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'additional_learning_activity',
            'recommendation_reason' => 'Weakest component in this subject: Performance Task at 60.0% (-15.0 points below target).',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', [
            'student_id'             => $student->id,
            'subject_id'             => $subject->id,
            'risk_result_id'         => null,
            'status'                 => 'approved',
            'created_by'             => $principal->id,
            'recommendation_reason'  => 'Weakest component in this subject: Performance Task at 60.0% (-15.0 points below target).',
            // TASK 2 of "status clarity and progress consistency" — parsed
            // from the reason text at creation, not left for
            // ProgressMonitoringService to re-derive later.
            'focus_component'        => 'performance_task',
        ]);
    }

    /**
     * TASK 2 of "status clarity and progress consistency" — a reason
     * that names no component (e.g. a generic monitoring recommendation)
     * must leave focus_component null, not guess one from recommended_type.
     */
    public function test_recording_an_intervention_with_a_component_less_reason_leaves_focus_component_null(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create();
        $student   = Student::factory()->create(['section_id' => $section->id]);
        $subject   = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'remediation',
            'recommendation_reason' => 'Currently failing: Some Subject. Remediation is recommended.',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('interventions', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'focus_component' => null,
        ]);
    }

    public function test_posting_a_status_in_the_create_request_is_ignored_it_always_starts_approved(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create();
        $subject   = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'remediation',
            'status'           => 'monitoring', // attempted status tampering — must be ignored
        ]);

        $this->assertDatabaseHas('interventions', ['student_id' => $student->id, 'status' => 'approved']);
    }

    /**
     * "Decision flow, report scoping, and dashboard pass" TASK 1b — the
     * one way to actually get a 'recommended', undecided row out of this
     * route: explicitly tick the deferred-decision checkbox.
     */
    public function test_recommendation_only_checkbox_defers_the_decision(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create();
        $subject   = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'           => $student->id,
            'subject_id'           => $subject->id,
            'grading_period'       => 1,
            'recommended_type'     => 'remediation',
            'recommendation_only'  => '1',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('interventions', [
            'student_id' => $student->id, 'status' => 'recommended', 'decided_by' => null, 'decided_at' => null,
        ]);
    }

    public function test_a_duplicate_open_intervention_for_the_same_student_and_subject_is_rejected(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create();
        $subject   = Subject::factory()->create();

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'status' => 'recommended', 'grading_period' => 1,
        ]);

        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'remediation',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(1, Intervention::where('student_id', $student->id)->where('subject_id', $subject->id)->count());
    }

    public function test_a_completed_intervention_does_not_block_recording_a_new_one_for_the_same_subject(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create();
        $subject   = Subject::factory()->create();

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'status' => 'completed',
        ]);

        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'remediation',
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(2, Intervention::where('student_id', $student->id)->where('subject_id', $subject->id)->count());
    }

    public function test_principal_can_move_an_intervention_through_its_status_lifecycle(): void
    {
        $principal    = User::factory()->principal()->create();
        $intervention = Intervention::factory()->create(['status' => 'recommended']);

        $response = $this->actingAs($principal)->put('/principal/interventions/' . $intervention->id, [
            'status' => 'approved',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', [
            'id' => $intervention->id, 'status' => 'approved', 'decided_by' => $principal->id,
        ]);
        $this->assertNotNull($intervention->fresh()->decided_at);
    }

    public function test_admin_cannot_access_intervention_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/principal/interventions')->assertForbidden();
        $this->actingAs($admin)->post('/principal/interventions', [])->assertForbidden();
    }

    public function test_adviser_cannot_access_intervention_routes(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->get('/principal/interventions')->assertForbidden();
    }

    public function test_adviser_cannot_record_an_intervention(): void
    {
        $adviser = User::factory()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($adviser)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'remediation',
        ])->assertForbidden();

        $this->assertDatabaseCount('interventions', 0);
    }

    public function test_guest_is_redirected_from_intervention_routes(): void
    {
        $this->get('/principal/interventions')->assertRedirect(route('login'));
    }

    public function test_interventions_page_shows_the_before_after_change_for_an_existing_intervention(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create(['school_year' => '2026-2027']);
        $student   = Student::factory()->create(['section_id' => $section->id]);
        $subject   = Subject::factory()->create();

        foreach ([1 => 60, 2 => 78] as $term => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => $term, 'school_year' => '2026-2027',
                'name' => 'PT-' . $term, 'component' => 'performance_task', 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);

            // Fill the other two components so this component is the
            // clearly identifiable weakest one at term 1 (baseline).
            foreach (['written_work', 'examination'] as $other) {
                $a = Assessment::factory()->create([
                    'subject_id' => $subject->id, 'section_id' => $section->id,
                    'grading_period' => $term, 'school_year' => '2026-2027',
                    'name' => $other . '-' . $term, 'component' => $other, 'max_score' => 100,
                ]);
                AssessmentScore::factory()->create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => 90]);
            }
        }

        $riskResult = RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 70,
            'risk_level' => 'moderate', 'school_year' => '2026-2027',
            'weakest_subject' => $subject->name, 'weakest_subject_id' => $subject->id,
            'weakest_subject_grade' => 70, 'generated_at' => now(),
        ]);

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'risk_result_id' => $riskResult->id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertSee('Change: +18.0 points.');
        // Neutral language only — never a causal claim.
        $response->assertDontSee('caused');
        $response->assertDontSee('improved because');
    }

    /**
     * An intervention recorded from assessment evidence alone (no risk
     * result at all) must still appear on the tracking page — the whole
     * reason Task 2 dropped whereHas('riskResults') from index().
     */
    public function test_interventions_page_lists_an_intervention_with_no_risk_result(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create(['last_name' => 'NoRiskResultYet']);
        $subject   = Subject::factory()->create(['name' => 'General Mathematics']);

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'risk_result_id' => null,
        ]);

        $this->assertSame(0, RiskResult::count());

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertSee('NoRiskResultYet');
        $response->assertSee('General Mathematics');
    }

    public function test_recording_and_updating_an_intervention_is_logged(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create();
        $subject   = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'recommended_type' => 'remediation',
        ]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'create_intervention', 'user_id' => $principal->id]);

        $intervention = Intervention::first();
        $this->actingAs($principal)->put('/principal/interventions/' . $intervention->id, ['status' => 'approved']);

        $this->assertDatabaseHas('activity_logs', ['action' => 'update_intervention', 'user_id' => $principal->id]);
    }

    public function test_an_invalid_intervention_type_is_rejected(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create();
        $subject   = Subject::factory()->create();

        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'not-a-real-type',
        ]);

        $response->assertSessionHasErrors('recommended_type');
    }

    public function test_interventions_page_can_be_filtered_by_grade_level_and_section(): void
    {
        $principal = User::factory()->principal()->create();
        $sectionA  = Section::factory()->create(['grade_level' => 11, 'name' => 'Narra']);
        $sectionB  = Section::factory()->create(['grade_level' => 12, 'name' => 'Molave']);
        $studentA  = Student::factory()->create(['section_id' => $sectionA->id, 'last_name' => 'InSectionA']);
        $studentB  = Student::factory()->create(['section_id' => $sectionB->id, 'last_name' => 'InSectionB']);

        Intervention::factory()->create(['student_id' => $studentA->id]);
        Intervention::factory()->create(['student_id' => $studentB->id]);

        $response = $this->actingAs($principal)->get('/principal/interventions?grade_level=11');

        $response->assertOk();
        $response->assertSee('InSectionA');
        $response->assertDontSee('InSectionB');
    }

    public function test_interventions_page_can_be_filtered_by_status(): void
    {
        $principal = User::factory()->principal()->create();
        $recommended = Student::factory()->create(['last_name' => 'StillRecommended']);
        $completed   = Student::factory()->create(['last_name' => 'AlreadyCompleted']);

        Intervention::factory()->create(['student_id' => $recommended->id, 'status' => 'recommended']);
        Intervention::factory()->create(['student_id' => $completed->id, 'status' => 'completed']);

        $response = $this->actingAs($principal)->get('/principal/interventions?status=completed');

        $response->assertOk();
        $response->assertSee('AlreadyCompleted');
        $response->assertDontSee('StillRecommended');
    }

    public function test_interventions_page_auto_displays_track_and_specialization_readonly_for_the_selected_section(): void
    {
        $principal = User::factory()->principal()->create();
        $track     = Track::factory()->create(['name' => 'Academic Track']);
        $spec      = Specialization::factory()->create(['name' => 'Humanities and Social Sciences', 'track_id' => $track->id]);
        $section   = Section::factory()->create(['name' => 'Steve', 'track_id' => $track->id, 'specialization_id' => $spec->id]);
        $student   = Student::factory()->create(['section_id' => $section->id]);

        Intervention::factory()->create(['student_id' => $student->id]);

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertDontSee('name="ar_track"', false);
        $response->assertDontSee('name="ar_specialization"', false);
        $response->assertSee('data-track="' . $track->name . '"', false);
        $response->assertSee('data-specialization="' . $spec->name . '"', false);
        // Also shown directly in the row itself, not just the filter chrome.
        $response->assertSee($track->name);
        $response->assertSee($spec->name);
    }
}
