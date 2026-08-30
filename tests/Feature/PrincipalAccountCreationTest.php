<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Widening users.role to include 'principal' (see the
 * widen_role_to_include_principal migration) is only useful if an admin
 * can actually create one through the normal Users management UI.
 */
class PrincipalAccountCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_principal_account(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'last_name'  => 'Santos',
            'first_name' => 'Maria',
            'username'   => 'msantos',
            'password'   => 'password123',
            'role'       => 'principal',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', [
            'email' => 'msantos@naggasican.edu.ph',
            'role'  => 'principal',
        ]);
    }

    public function test_the_new_principal_account_can_log_in_and_reach_its_dashboard(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/dashboard');

        $response->assertRedirect(route('principal.dashboard'));
    }
}
