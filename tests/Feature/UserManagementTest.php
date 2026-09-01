<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Account uniqueness (at most one ACTIVE Admin, at most one ACTIVE
 * Principal — Advisers unlimited) and active/inactive account status.
 * See App\Models\User::boot()/hasActiveAccountForRole() and
 * Admin\UserController for the implementation; the
 * add_active_status_to_users_table migration adds the DB-level
 * role_singleton_key UNIQUE index that backs this even under concurrent
 * requests, not just the application-level pre-checks these tests mostly
 * exercise (test_o below exercises the DB constraint directly).
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'last_name'  => 'Reyes',
            'first_name' => 'Ana',
            'username'   => 'areyes',
            'password'   => 'password123',
            'role'       => 'adviser',
        ], $overrides);
    }

    // A. Admin uniqueness
    public function test_creating_a_second_admin_is_rejected_while_one_is_active(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/users', $this->createPayload(['role' => 'admin', 'username' => 'newadmin']));

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'newadmin@naggasican.edu.ph']);
    }

    // B. Principal uniqueness
    public function test_creating_a_second_principal_is_rejected_while_one_is_active(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->principal()->create();

        $response = $this->actingAs($admin)->post('/admin/users', $this->createPayload(['role' => 'principal', 'username' => 'newprincipal']));

        $response->assertSessionHasErrors('role');
        $response->assertSessionHasErrors(['role' => 'An active Principal account already exists. Please deactivate the existing Principal account before creating another Principal account.']);
        $this->assertDatabaseMissing('users', ['email' => 'newprincipal@naggasican.edu.ph']);
    }

    // C. Principal after deactivation
    public function test_a_new_principal_can_be_created_after_the_existing_one_is_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $existingPrincipal = User::factory()->principal()->create();

        $this->actingAs($admin)->post('/admin/users/' . $existingPrincipal->id . '/disable');

        $response = $this->actingAs($admin)->post('/admin/users', $this->createPayload(['role' => 'principal', 'username' => 'newprincipal']));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'newprincipal@naggasican.edu.ph', 'role' => 'principal', 'is_active' => 1]);
    }

    // D. Principal reactivation conflict
    public function test_reactivating_a_principal_is_rejected_while_another_is_active(): void
    {
        $admin = User::factory()->admin()->create();
        $principalA = User::factory()->principal()->create();
        $principalB = User::factory()->principal()->inactive()->create();

        $response = $this->actingAs($admin)->post('/admin/users/' . $principalB->id . '/activate');

        $response->assertSessionHas('error');
        $this->assertFalse($principalB->fresh()->is_active);
        $this->assertTrue($principalA->fresh()->is_active);
    }

    public function test_reactivating_a_principal_succeeds_once_the_other_is_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $principalA = User::factory()->principal()->create();
        $principalB = User::factory()->principal()->inactive()->create();

        $this->actingAs($admin)->post('/admin/users/' . $principalA->id . '/disable');
        $response = $this->actingAs($admin)->post('/admin/users/' . $principalB->id . '/activate');

        $response->assertSessionHas('success');
        $this->assertTrue($principalB->fresh()->is_active);
        $this->assertFalse($principalA->fresh()->is_active);
    }

    // E. Admin reactivation conflict
    public function test_reactivating_an_admin_is_rejected_while_another_is_active(): void
    {
        $activeAdmin = User::factory()->admin()->create();
        $inactiveAdmin = User::factory()->admin()->inactive()->create();

        $response = $this->actingAs($activeAdmin)->post('/admin/users/' . $inactiveAdmin->id . '/activate');

        $response->assertSessionHas('error');
        $this->assertFalse($inactiveAdmin->fresh()->is_active);
    }

    // F. Multiple Advisers
    public function test_multiple_active_adviser_accounts_can_be_created(): void
    {
        $admin = User::factory()->admin()->create();

        $r1 = $this->actingAs($admin)->post('/admin/users', $this->createPayload(['username' => 'adviser1']));
        $r2 = $this->actingAs($admin)->post('/admin/users', $this->createPayload(['username' => 'adviser2']));
        $r3 = $this->actingAs($admin)->post('/admin/users', $this->createPayload(['username' => 'adviser3']));

        $r1->assertSessionHasNoErrors();
        $r2->assertSessionHasNoErrors();
        $r3->assertSessionHasNoErrors();
        $this->assertSame(3, User::where('role', 'adviser')->where('is_active', true)->count());
    }

    // G. Duplicate email/username
    public function test_duplicate_username_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'jdoe@naggasican.edu.ph']);

        $response = $this->actingAs($admin)->post('/admin/users', $this->createPayload(['username' => 'jdoe']));

        $response->assertSessionHasErrors('username');
        $this->assertSame(1, User::where('email', 'jdoe@naggasican.edu.ph')->count());
    }

    public function test_email_has_a_database_level_unique_constraint(): void
    {
        User::factory()->create(['email' => 'unique-test@naggasican.edu.ph']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create(['email' => 'unique-test@naggasican.edu.ph']);
    }

    // I. Disabled login
    public function test_a_disabled_user_cannot_log_in(): void
    {
        $adviser = User::factory()->create(['email' => 'disabled-adviser@naggasican.edu.ph']);
        $adviser->is_active = false;
        $adviser->save();

        $response = $this->post('/login', ['email' => 'disabled-adviser@naggasican.edu.ph', 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_disabled_login_shows_the_disabled_specific_message_not_the_generic_one(): void
    {
        $adviser = User::factory()->create(['email' => 'disabled-adviser2@naggasican.edu.ph']);
        $adviser->is_active = false;
        $adviser->save();

        $response = $this->from('/login')->post('/login', ['email' => 'disabled-adviser2@naggasican.edu.ph', 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'Your account has been disabled. Please contact the system administrator.']);
    }

    public function test_an_active_users_login_still_works_normally(): void
    {
        $adviser = User::factory()->create(['email' => 'active-adviser@naggasican.edu.ph']);

        $response = $this->post('/login', ['email' => 'active-adviser@naggasican.edu.ph', 'password' => 'password']);

        $this->assertAuthenticatedAs($adviser);
    }

    // J. Historical data preserved on disable
    public function test_disabling_a_user_with_academic_records_preserves_those_records(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $grade   = Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'grade' => 88, 'school_year' => $section->school_year,
        ]);

        $response = $this->actingAs($admin)->post('/admin/users/' . $adviser->id . '/disable');

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['id' => $adviser->id]); // not deleted
        $this->assertFalse($adviser->fresh()->is_active);
        $this->assertDatabaseHas('grades', ['id' => $grade->id, 'encoded_by' => $adviser->id]);
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'adviser_id' => $adviser->id]);
    }

    public function test_disabled_user_is_not_deleted_from_the_database(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create();

        $this->actingAs($admin)->post('/admin/users/' . $adviser->id . '/disable');

        $this->assertDatabaseHas('users', ['id' => $adviser->id]);
    }

    // K. Authorization
    public function test_admin_can_manage_users(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_principal_cannot_manage_users(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser   = User::factory()->create();

        $this->actingAs($principal)->get('/admin/users')->assertForbidden();
        $this->actingAs($principal)->post('/admin/users', $this->createPayload())->assertForbidden();
        $this->actingAs($principal)->post('/admin/users/' . $adviser->id . '/disable')->assertForbidden();
        $this->actingAs($principal)->post('/admin/users/' . $adviser->id . '/activate')->assertForbidden();
    }

    public function test_adviser_cannot_manage_users(): void
    {
        $adviser = User::factory()->create();
        $other   = User::factory()->create();

        $this->actingAs($adviser)->get('/admin/users')->assertForbidden();
        $this->actingAs($adviser)->post('/admin/users', $this->createPayload())->assertForbidden();
        $this->actingAs($adviser)->post('/admin/users/' . $other->id . '/disable')->assertForbidden();
    }

    public function test_guest_cannot_access_user_management(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));
        $this->post('/admin/users', [])->assertRedirect(route('login'));
    }

    // Extra: self-protection + role-change uniqueness (CLAUDE.md section 9)
    public function test_admin_cannot_disable_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/users/' . $admin->id . '/disable');

        $response->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_changing_an_active_advisers_role_to_admin_is_rejected_while_an_admin_is_already_active(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create(['email' => 'wouldbe@naggasican.edu.ph']);

        $response = $this->actingAs($admin)->put('/admin/users/' . $adviser->id, $this->createPayload([
            'username' => 'wouldbe',
            'role'     => 'admin',
        ]));

        $response->assertSessionHasErrors('role');
        $this->assertSame('adviser', $adviser->fresh()->role);
    }

    public function test_changing_an_inactive_users_role_does_not_trip_the_uniqueness_check(): void
    {
        $admin = User::factory()->admin()->create();
        $inactiveAdviser = User::factory()->inactive()->create(['email' => 'inactive-adv@naggasican.edu.ph']);

        // No active Principal exists yet, so promoting this INACTIVE user
        // to 'principal' must succeed — they won't hold the singleton key
        // until reactivated (see User::boot()).
        $response = $this->actingAs($admin)->put('/admin/users/' . $inactiveAdviser->id, $this->createPayload([
            'username' => 'inactive-adv',
            'role'     => 'principal',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('principal', $inactiveAdviser->fresh()->role);
        $this->assertFalse($inactiveAdviser->fresh()->is_active);
    }

    // Concurrency guarantee itself — the DB constraint, not just the pre-check.
    public function test_the_database_rejects_a_second_simultaneously_active_principal_even_bypassing_the_controller(): void
    {
        User::factory()->principal()->create();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        User::factory()->principal()->create();
    }
}
