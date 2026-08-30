<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * There is no dedicated profile page in this app — profile editing lives
 * in a modal available on every screen, submitted via PUT to /profile and
 * /profile/password, and there is no self-service account deletion (see
 * ProfileController's docblock). These tests cover the real endpoints
 * instead of the stock Breeze profile page/PATCH/DELETE flow.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->put('/profile', [
                'last_name'   => 'Cruz',
                'first_name'  => 'Juan',
                'middle_name' => null,
                'email'       => $user->email, // unchanged — no current_password required
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $user->refresh();

        $this->assertSame('Cruz', $user->last_name);
        $this->assertSame('Juan', $user->first_name);
        $this->assertSame('Cruz, Juan', $user->name);
    }

    public function test_changing_email_requires_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->put('/profile', [
                'last_name'  => $user->last_name,
                'first_name' => $user->first_name,
                'email'      => 'new-email@example.com',
                // no current_password supplied
            ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_email_can_be_changed_with_correct_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->put('/profile', [
                'last_name'        => $user->last_name,
                'first_name'       => $user->first_name,
                'email'            => 'new-email@example.com',
                'current_password' => 'password',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('new-email@example.com', $user->fresh()->email);
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->put('/profile/password', [
                'current_password'     => 'password',
                'password'              => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password', $user->fresh()->password));
    }

    public function test_correct_current_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->put('/profile/password', [
                'current_password'     => 'wrong-password',
                'password'              => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertSessionHasErrors('current_password');
    }
}
