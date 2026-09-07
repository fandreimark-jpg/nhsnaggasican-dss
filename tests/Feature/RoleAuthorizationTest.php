<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RoleMiddleware ('role:admin' / 'role:adviser') is what actually keeps
 * an Adviser out of Admin functions and vice versa — the menu just hides
 * links, it enforces nothing on its own. These tests hit the routes
 * directly (bypassing the UI) to make sure that enforcement is real.
 */
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_admin_dashboard(): void
    {
        $this->get('/admin/dashboard')->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_to_login_from_adviser_dashboard(): void
    {
        $this->get('/adviser/dashboard')->assertRedirect(route('login'));
    }

    public function test_adviser_cannot_access_admin_dashboard(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)
            ->get('/admin/dashboard')
            ->assertForbidden();
    }

    public function test_adviser_cannot_access_admin_users_management(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_cannot_access_adviser_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/adviser/dashboard')
            ->assertForbidden();
    }

    public function test_admin_cannot_access_adviser_grades(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/adviser/grades')
            ->assertForbidden();
    }

    public function test_adviser_can_access_own_dashboard(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)
            ->get('/adviser/dashboard')
            ->assertOk();
    }

    public function test_admin_can_access_own_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk();
    }

    /**
     * Blade rendering errors (undefined variables, wrong compact() keys
     * after a controller edit) only surface when the view actually
     * renders — a route existing isn't enough. These hit every page
     * touched by the in-progress work to catch that class of bug.
     */
    public function test_admin_reports_page_renders(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/reports')->assertOk();
    }

    public function test_admin_academic_terms_page_renders(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/academic-terms')->assertOk();
    }

    public function test_admin_dashboard_has_no_risk_or_at_risk_analytics(): void
    {
        $admin = User::factory()->admin()->create();

        // Admin dashboard is master-data-only — no ajax at-risk partial
        // branch exists anymore (that's exclusively a Principal-dashboard
        // concept now), and even a plain load must never surface DSS
        // language like "At Risk" / "Risk Level" / "Recommendations".
        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertViewHas('totalUsers');
        $response->assertDontSee('Risk Distribution');
        $response->assertDontSee('At-Risk');
        $response->assertDontSee('Students Needing Attention');
    }

    public function test_adviser_students_page_renders(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->get('/adviser/students')->assertOk();
    }

    public function test_principal_can_access_own_dashboard(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)
            ->get('/principal/dashboard')
            ->assertOk();
    }

    public function test_admin_cannot_access_principal_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/principal/dashboard')
            ->assertForbidden();
    }

    public function test_adviser_cannot_access_principal_dashboard(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)
            ->get('/principal/dashboard')
            ->assertForbidden();
    }

    public function test_principal_cannot_access_admin_dashboard(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)
            ->get('/admin/dashboard')
            ->assertForbidden();
    }

    public function test_principal_cannot_access_adviser_dashboard(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)
            ->get('/adviser/dashboard')
            ->assertForbidden();
    }

    public function test_principal_cannot_modify_grades(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)
            ->post('/adviser/grades', ['grading_period' => 1, 'grades' => []])
            ->assertForbidden();
    }

    public function test_principal_cannot_verify_computed_grades(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)
            ->post('/adviser/grades/verify', ['student_id' => 1, 'subject_id' => 1, 'grading_period' => 1])
            ->assertForbidden();
    }

    public function test_principal_reports_page_renders(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->get('/principal/reports')->assertOk();
    }

    public function test_admin_cannot_access_principal_reports(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/principal/reports')->assertForbidden();
    }

    public function test_principal_subject_analysis_page_renders(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->get('/principal/subject-analysis')->assertOk();
    }

    public function test_adviser_cannot_access_principal_subject_analysis(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->get('/principal/subject-analysis')->assertForbidden();
    }

    public function test_principal_cannot_manage_admin_master_data(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)
            ->post('/admin/sections', ['name' => 'Narra'])
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_principal_dashboard(): void
    {
        $this->get('/principal/dashboard')->assertRedirect(route('login'));
    }

    public function test_adviser_grades_page_renders(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->get('/adviser/grades')->assertOk();
    }

    public function test_adviser_submit_report_page_renders(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->get('/adviser/submit-report')->assertOk();
    }

    public function test_adviser_assessments_page_renders(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->get('/adviser/assessments')->assertOk();
    }

    public function test_principal_students_index_renders(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->get('/principal/students')->assertOk();
    }

    public function test_adviser_cannot_access_principal_students_index(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->get('/principal/students')->assertForbidden();
    }

    public function test_admin_cannot_access_principal_students_index(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/principal/students')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_principal_students_index(): void
    {
        $this->get('/principal/students')->assertRedirect(route('login'));
    }

    public function test_principal_cannot_access_assessment_upload_detect_route(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->post('/adviser/assessments/detect', [])->assertForbidden();
    }

    /**
     * Task 5 of "close the intervention loop" — the adviser gained
     * read + acknowledge access to interventions in that task; these
     * confirm the boundary still holds everywhere else. Only the
     * Principal role has 'interventions.store' / '.update' / '.bulk-store'
     * routes at all (see routes/web.php's principal group comment: "the
     * one WRITE surface the Principal role has") — an adviser hitting them
     * is blocked by 'role:principal' middleware before the controller
     * ever runs, same as every other principal.* route.
     */
    public function test_adviser_cannot_create_an_intervention(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->post('/principal/interventions', [
            'student_id' => 1, 'subject_id' => 1, 'grading_period' => 1, 'recommended_type' => 'remediation',
        ])->assertForbidden();
    }

    public function test_adviser_cannot_update_an_interventions_status(): void
    {
        $adviser = User::factory()->create();
        $intervention = \App\Models\Intervention::factory()->create();

        $this->actingAs($adviser)->put('/principal/interventions/' . $intervention->id, [
            'status' => 'approved',
        ])->assertForbidden();
    }

    public function test_adviser_cannot_access_the_bulk_intervention_route(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->post('/principal/interventions/bulk', [])->assertForbidden();
    }

    /** There is no delete route for interventions anywhere — nobody, adviser or Principal, can delete one; this locks that in. */
    public function test_no_intervention_delete_route_exists_for_any_role(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('principal.interventions.destroy'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('adviser.interventions.destroy'));
    }

    public function test_adviser_cannot_see_interventions_from_another_section(): void
    {
        $adviser = \App\Models\User::factory()->create();
        \App\Models\Section::factory()->create(['adviser_id' => $adviser->id]);

        $otherSection = \App\Models\Section::factory()->create();
        $otherStudent = \App\Models\Student::factory()->create(['section_id' => $otherSection->id, 'last_name' => 'OtherSectionOnly']);
        $subject = \App\Models\Subject::factory()->create();
        \App\Models\Intervention::factory()->create(['student_id' => $otherStudent->id, 'subject_id' => $subject->id]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertDontSee('OtherSectionOnly');
    }
}
