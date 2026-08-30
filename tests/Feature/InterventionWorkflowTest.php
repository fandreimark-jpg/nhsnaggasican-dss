<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
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
}
