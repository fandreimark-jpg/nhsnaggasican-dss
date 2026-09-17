<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-demo audit (2026-09-17), section 17 — the tab icon used to be
 * declared on the login page only, with a ZERO-byte public/favicon.ico
 * behind every other page, so the tab icon changed (to nothing) the
 * moment a user signed in. <x-app-favicon /> is now the single source;
 * this test pins that every layout in the app renders the exact same
 * declaration and that the icon files it points at really exist.
 */
class FaviconConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const ICON_LINK = 'rel="icon" type="image/png" sizes="32x32" href="http://localhost/images/favicon-32.png"';

    public function test_every_layout_renders_the_same_favicon_declaration(): void
    {
        $pages = [
            'login (standalone page)' => $this->get('/login'),
            'guest layout'            => $this->get('/forgot-password'),
            'admin app layout'        => $this->actingAs(User::factory()->admin()->create())->get('/admin/dashboard'),
            'adviser app layout'      => $this->actingAs(User::factory()->create())->get('/adviser/dashboard'),
            'principal app layout'    => $this->actingAs(User::factory()->principal()->create())->get('/principal/dashboard'),
            'error page'              => $this->get('/no-such-page'),
        ];

        foreach ($pages as $label => $response) {
            $html = $response->getContent();
            $this->assertStringContainsString(self::ICON_LINK, $html, "$label is missing the shared favicon");
            $this->assertStringContainsString('rel="apple-touch-icon"', $html, $label);
            $this->assertSame(
                1,
                substr_count($html, 'sizes="32x32"'),
                "$label declares the 32px icon more than once — conflicting favicon declarations"
            );
            // The old per-page declaration pointed at the raw 500px logo.
            $this->assertStringNotContainsString('rel="icon" type="image/png" href="http://localhost/images/nagga-logo.png"', $html, $label);
        }
    }

    public function test_the_icon_files_exist_and_favicon_ico_is_no_longer_an_empty_placeholder(): void
    {
        foreach (['images/favicon-32.png', 'images/favicon-192.png', 'images/apple-touch-icon.png', 'favicon.ico'] as $file) {
            $this->assertFileExists(public_path($file));
            $this->assertGreaterThan(0, filesize(public_path($file)), "$file is empty");
        }

        // The .ico must start with a genuine ICO directory header
        // (reserved 0, type 1 = icon).
        $this->assertSame("\x00\x00\x01\x00", substr(file_get_contents(public_path('favicon.ico')), 0, 4));
    }

    public function test_the_guest_layout_shows_the_school_logo_not_the_stock_laravel_mark(): void
    {
        $response = $this->get('/forgot-password');
        $response->assertOk();
        $response->assertSee('images/nagga-logo.png');
        $response->assertDontSee('viewBox="0 0 316 316"', false);
    }
}
