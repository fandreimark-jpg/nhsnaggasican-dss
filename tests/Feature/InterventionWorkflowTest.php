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
 * (InterventionRecommender); the Principal decides — a new intervention
 * always starts 'recommended' regardless of what's posted, and only an
 * explicit update() call can move it forward. Direct URL access is
 * tested here, not just nav visibility (see RoleMiddleware's rationale).
 */
class InterventionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_principal_can_view_the_interventions_page(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->get('/principal/interventions')->assertOk();
    }

    public function test_principal_can_record_an_intervention_for_an_at_risk_student(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create();
        $student   = Student::factory()->create(['section_id' => $section->id]);

        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'school_year' => $section->school_year,
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'recommended_type' => 'parent_conference',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', [
            'student_id'       => $student->id,
            'recommended_type' => 'parent_conference',
            'status'           => 'recommended', // never auto-approved, regardless of what's posted
            'created_by'       => $principal->id,
        ]);
    }

    public function test_posting_a_status_in_the_create_request_is_ignored_it_always_starts_recommended(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'recommended_type' => 'remediation',
            'status'           => 'approved', // attempted self-approval — must be ignored
        ]);

        $this->assertDatabaseHas('interventions', ['student_id' => $student->id, 'status' => 'recommended']);
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

    public function test_recording_and_updating_an_intervention_is_logged(): void
    {
        $principal = User::factory()->principal()->create();
        $student   = Student::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id' => $student->id, 'recommended_type' => 'remediation',
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

        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
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

        foreach ([$studentA, $studentB] as $student) {
            RiskResult::create([
                'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
                'risk_level' => 'high', 'school_year' => $student->section->school_year, 'generated_at' => now(),
            ]);
        }

        $response = $this->actingAs($principal)->get('/principal/interventions?ar_grade_level=11');

        $response->assertOk();
        $response->assertSee('InSectionA');
        $response->assertDontSee('InSectionB');
    }

    public function test_interventions_page_auto_displays_track_and_specialization_readonly_for_the_selected_section(): void
    {
        $principal = User::factory()->principal()->create();
        $track     = Track::factory()->create(['name' => 'Academic Track']);
        $spec      = Specialization::factory()->create(['name' => 'Humanities and Social Sciences']);
        $section   = Section::factory()->create(['name' => 'Steve', 'track_id' => $track->id, 'specialization_id' => $spec->id]);
        $student   = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'school_year' => $section->school_year, 'generated_at' => now(),
        ]);

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
