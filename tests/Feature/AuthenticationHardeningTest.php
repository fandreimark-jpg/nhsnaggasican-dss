<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Pre-demo audit (2026-09-17), section 6 — the authentication behaviours
 * the audit checklist asks for that no earlier test pinned explicitly:
 * a session that outlives its account being disabled, the login
 * throttle, validation of blank/malformed input, and an already
 * signed-in user hitting /login. All through the real HTTP layer.
 */
class AuthenticationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_session_is_evicted_the_moment_the_account_is_disabled(): void
    {
        $adviser = User::factory()->create();

        // Real login, so the session is a genuine one, not actingAs().
        $this->post('/login', ['email' => $adviser->email, 'password' => 'password'])
            ->assertRedirect('/adviser/dashboard');
        $this->get('/adviser/dashboard')->assertOk();

        // An admin disables the account while that session is still alive.
        $adviser->is_active = false;
        $adviser->save();

        $response = $this->get('/adviser/dashboard');
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // And the evicted session cannot simply be reused afterwards.
        $this->get('/adviser/dashboard')->assertRedirect('/login');
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $adviser = User::factory()->create();
        RateLimiter::clear(strtolower($adviser->email) . '|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $adviser->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        // Sixth attempt — even with the CORRECT password — is refused with
        // the throttle message, never authenticated.
        $response = $this->post('/login', ['email' => $adviser->email, 'password' => 'password']);
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();

        RateLimiter::clear(strtolower($adviser->email) . '|127.0.0.1');
    }

    public function test_blank_and_malformed_credentials_are_rejected_server_side(): void
    {
        $this->post('/login', ['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password']);

        $this->post('/login', ['email' => 'not-an-email', 'password' => 'password'])
            ->assertSessionHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_an_unknown_email_gets_the_same_generic_message_as_a_wrong_password(): void
    {
        $adviser = User::factory()->create();

        $unknown = $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'password']);
        $unknownMessage = session('errors')->first('email');

        $wrong = $this->post('/login', ['email' => $adviser->email, 'password' => 'wrong']);
        $wrongMessage = session('errors')->first('email');

        $unknown->assertSessionHasErrors('email');
        $wrong->assertSessionHasErrors('email');
        $this->assertSame($unknownMessage, $wrongMessage, 'The message must not reveal whether an account exists.');
    }

    public function test_a_signed_in_user_visiting_login_is_sent_to_their_own_dashboard(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/login')->assertRedirect('/dashboard');
        $this->actingAs($admin)->get('/dashboard')->assertRedirect('/admin/dashboard');

        $principal = User::factory()->principal()->create();
        $this->actingAs($principal)->get('/dashboard')->assertRedirect('/principal/dashboard');
    }

    public function test_logout_invalidates_the_session_so_protected_pages_redirect_afterwards(): void
    {
        $adviser = User::factory()->create();
        $this->post('/login', ['email' => $adviser->email, 'password' => 'password']);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/adviser/dashboard')->assertRedirect('/login');
    }

    public function test_every_authenticated_response_forbids_back_button_caching(): void
    {
        $adviser = User::factory()->create();
        $response = $this->actingAs($adviser)->get('/adviser/dashboard');
        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
