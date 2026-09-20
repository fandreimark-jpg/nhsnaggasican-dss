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

        $response->assertSessionHasErrorsIn('profile', ['current_password']);
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

        $response->assertSessionHasErrorsIn('profilePassword', ['current_password']);
    }

    /**
     * Final pre-demo audit (2026-09-20). profile/_modal.blade.php is
     * included on EVERY page. It used to open on any default-bag error
     * and call $errors->only() — which MessageBag does not have — so a
     * rejected Admin > Users or Admin > Students form (keys email /
     * first_name / last_name) rendered a 500 instead of its own message.
     * 64 such errors sat in laravel.log before this was caught.
     */
    public function test_another_pages_validation_error_on_a_shared_key_neither_opens_the_profile_modal_nor_crashes_the_page(): void
    {
        $admin = User::factory()->admin()->create();

        $post = $this->actingAs($admin)->from('/admin/users')->post('/admin/users', [
            'last_name' => '', 'first_name' => 'X', 'username' => 'x', 'role' => 'adviser', 'password' => 'short',
        ]);
        $post->assertRedirect('/admin/users');
        $post->assertSessionHasErrors(['last_name']);

        $page = $this->actingAs($admin)->get('/admin/users');
        $page->assertOk();
        $page->assertSee('The last name field is required.');
        // The profile modal stays hidden — the error belongs to the Users form.
        $page->assertSee('id="profileModal"', false);
        $page->assertDontSee('data-profile-errors', false);
        $page->assertSee('id="profileModal"' . "
     class=\"hidden opacity-0", false);
    }

    public function test_the_profile_modal_opens_with_its_own_errors_and_shows_them(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/dashboard')->put('/profile', [
            'last_name' => '', 'first_name' => $user->first_name, 'email' => $user->email,
        ])->assertSessionHasErrorsIn('profile', ['last_name']);

        $page = $this->actingAs($user)->get('/adviser/dashboard');
        $page->assertOk();
        $page->assertSee('data-profile-errors', false);
        $page->assertSee('The last name field is required.');
    }
}

