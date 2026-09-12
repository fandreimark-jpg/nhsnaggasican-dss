<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYSTEM_FIXES_AND_ML_AUDIT.md, "security/role-authorization
 * re-verification" -- RoleAuthorizationTest already covers the
 * pre-existing routes exhaustively; this re-verifies the NEW routes
 * added across this pass (rejected-students download, the Subject
 * Analysis section/term filters) are not reachable by the wrong role via
 * a direct URL, the same standard every other route in this app is held
 * to.
 */
class NewRoutesRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adviser_cannot_download_rejected_students(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);

        $this->actingAs($adviser)->get('/admin/students/rejected/download')->assertForbidden();
    }

    public function test_principal_cannot_download_rejected_students(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->get('/admin/students/rejected/download')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_rejected_students_download(): void
    {
        $this->get('/admin/students/rejected/download')->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_to_login_from_subject_analysis(): void
    {
        $this->get('/principal/subject-analysis')->assertRedirect(route('login'));
    }

    public function test_adviser_cannot_import_subjects_with_a_grade_level_selection(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);

        $this->actingAs($adviser)->post('/admin/subjects/import', ['grade_level' => '11'])
            ->assertForbidden();
    }
}
