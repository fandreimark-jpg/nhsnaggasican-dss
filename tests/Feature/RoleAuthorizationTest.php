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

    public function test_admin_dashboard_ajax_at_risk_partial_renders(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/dashboard', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();
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
}
