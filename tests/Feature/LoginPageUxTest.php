<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-demo audit (2026-09-17), section 18 — the login page's accessibility
 * and slow-network affordances, pinned so a future restyle can't drop them.
 */
class LoginPageUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_carries_the_expected_input_semantics(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('autocomplete="username"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('<label for="email"', $html);
        $this->assertStringContainsString('<label for="password"', $html);

        // Show/hide-password control is a real, labelled button.
        $this->assertStringContainsString('aria-label="Show password"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);

        // Double-submit protection label consumed by resources/js/confirm.js.
        $this->assertStringContainsString('data-loading="Signing in…"', $html);
    }

    public function test_login_placeholder_does_not_imply_an_official_email_domain(): void
    {
        $html = $this->get('/login')->getContent();
        $this->assertStringNotContainsString('placeholder="email@naggasican.edu.ph"', $html);
        $this->assertStringNotContainsString('deped.gov.ph', $html);
        $this->assertStringContainsString('placeholder="Enter your email address"', $html);
    }

    public function test_login_page_has_no_self_registration_link(): void
    {
        $html = $this->get('/login')->getContent();
        $this->assertStringNotContainsString('/register', $html);
        $this->assertStringNotContainsStringIgnoringCase('create an account', $html);
        $this->assertNull(app('router')->getRoutes()->getByName('register'));
    }
}
