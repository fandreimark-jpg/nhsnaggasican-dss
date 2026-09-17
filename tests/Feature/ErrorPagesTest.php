<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-demo audit (2026-09-17), section 23 — before this pass the
 * application had NO resources/views/errors/ directory at all, so a
 * wrong URL or a cross-role request showed Laravel's stock unbranded
 * error screen. These pin that every HTTP error a demo audience could
 * plausibly trigger renders the branded shell, carries the same favicon
 * as the rest of the app, offers a safe way out, and leaks nothing.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_404_renders_the_branded_page_for_a_guest(): void
    {
        $response = $this->get('/this-page-does-not-exist');
        $response->assertNotFound();
        $response->assertSee('Page not found');
        $response->assertSee('The requested page could not be found.');
        $response->assertSee('Go to sign in');
        $response->assertSee('images/favicon-32.png');
        $response->assertDontSee('NotFoundHttpException');
    }

    public function test_404_offers_the_dashboard_to_a_signed_in_user(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get('/admin/nothing-here');
        $response->assertNotFound();
        $response->assertSee('Return to dashboard');
        $response->assertDontSee('Go to sign in');
    }

    public function test_403_renders_the_branded_page_on_a_cross_role_request(): void
    {
        $principal = User::factory()->principal()->create();
        $response = $this->actingAs($principal)->get('/admin/users');
        $response->assertForbidden();
        $response->assertSee('Access denied');
        $response->assertSee('You do not have permission to access this page.');
        $response->assertSee('Return to dashboard');
        $response->assertDontSee('RoleMiddleware');
    }

    public function test_419_and_500_pages_render_without_an_authenticated_user_and_leak_nothing(): void
    {
        $expired = view('errors.419')->render();
        $this->assertStringContainsString('Session expired', $expired);
        $this->assertStringContainsString('Please sign in again', $expired);
        $this->assertStringContainsString(route('login'), $expired);
        $this->assertStringNotContainsString('Return to dashboard', $expired);

        $failed = view('errors.500')->render();
        $this->assertStringContainsString('Something went wrong', $failed);
        $this->assertStringContainsString('Please try again', $failed);
        foreach (['stack trace', 'Exception', base_path(), 'SQLSTATE', 'vendor/laravel'] as $leak) {
            $this->assertStringNotContainsString($leak, $failed);
        }

        $maintenance = view('errors.503')->render();
        $this->assertStringContainsString('Temporarily unavailable', $maintenance);
    }

    public function test_error_pages_are_marked_noindex_and_share_the_app_favicon(): void
    {
        foreach (['403', '404', '419', '500', '503'] as $code) {
            $html = view("errors.$code")->render();
            $this->assertStringContainsString('<meta name="robots" content="noindex">', $html, "errors.$code");
            $this->assertStringContainsString('images/favicon-32.png', $html, "errors.$code");
            $this->assertStringContainsString('images/nagga-logo.png', $html, "errors.$code");
        }
    }
}
