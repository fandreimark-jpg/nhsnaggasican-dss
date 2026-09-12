<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixed/sticky sidebar requirement. No browser automation tool is
 * available in this session, so this verifies the actual rendered
 * markup (real HTTP requests through the real layout, not a static
 * read of the Blade source) carries the classes that produce the
 * required behavior, on real pages, across all three roles that share
 * ONE layout file (layouts/app.blade.php) -- there is nothing to
 * duplicate per role, so one shared assertion set covers all three.
 *
 * What the classes mean, for whoever reads this test later:
 * - The outer wrapper is h-screen overflow-hidden (not min-h-screen) --
 *   this is what stops the BODY itself from ever scrolling, which is
 *   the actual mechanism that keeps the sidebar visually fixed: nothing
 *   above it moves.
 * - #sidebar keeps its existing mobile drawer classes (fixed,
 *   -translate-x-full, md:static) completely unchanged, and gains
 *   md:h-screen md:shrink-0 (pins it to exactly one viewport height on
 *   desktop, never compressible) plus unconditional overflow-y-auto
 *   (independent scrolling if the menu is taller than the viewport, on
 *   both mobile and desktop).
 * - <main> keeps its pre-existing flex-1 overflow-y-auto -- now that the
 *   parent is height-capped, this is what actually scrolls.
 */
class FixedSidebarLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function assertFixedSidebarMarkup($response): void
    {
        $response->assertOk();
        // The outer flex wrapper: was min-h-screen (unbounded, whole
        // page scrolled together); must now be h-screen overflow-hidden
        // for the sidebar to visually stay put.
        $response->assertSee('flex h-screen overflow-hidden', false);
        $response->assertDontSee('flex min-h-screen', false);
        // The sidebar: existing mobile drawer classes preserved
        // (fixed/-translate-x-full/md:static/md:translate-x-0), plus the
        // new desktop-pinning and independent-scroll classes.
        $response->assertSee('fixed md:static inset-y-0 left-0 z-40 -translate-x-full md:translate-x-0', false);
        $response->assertSee('overflow-y-auto md:h-screen md:shrink-0', false);
        // <main> already had these -- must still be there, unchanged.
        $response->assertSee('flex-1 p-6 overflow-y-auto', false);
    }

    public function test_admin_students_page_has_the_fixed_sidebar_markup(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertFixedSidebarMarkup($this->actingAs($admin)->get('/admin/students'));
    }

    public function test_admin_subjects_page_has_the_fixed_sidebar_markup(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertFixedSidebarMarkup($this->actingAs($admin)->get('/admin/subjects'));
    }

    public function test_admin_sections_page_has_the_fixed_sidebar_markup(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertFixedSidebarMarkup($this->actingAs($admin)->get('/admin/sections'));
    }

    public function test_admin_reports_page_has_the_fixed_sidebar_markup(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertFixedSidebarMarkup($this->actingAs($admin)->get('/admin/reports'));
    }

    public function test_adviser_dashboard_has_the_fixed_sidebar_markup(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);
        $this->assertFixedSidebarMarkup($this->actingAs($adviser)->get('/adviser/dashboard'));
    }

    public function test_principal_interventions_page_has_the_fixed_sidebar_markup(): void
    {
        $principal = User::factory()->principal()->create();
        $this->assertFixedSidebarMarkup($this->actingAs($principal)->get('/principal/interventions'));
    }

    public function test_principal_students_at_risk_page_has_the_fixed_sidebar_markup(): void
    {
        $principal = User::factory()->principal()->create();
        $this->assertFixedSidebarMarkup($this->actingAs($principal)->get('/principal/students'));
    }

    /** The footer partial is still inside <main>'s scrollable content -- never a separately fixed element that could overlap the sidebar. */
    public function test_footer_renders_inside_main_not_as_a_separately_fixed_element(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $content = $response->getContent();
        $mainStart = strpos($content, '<main');
        $footerPos = strpos($content, 'Naggasican National High School. All rights reserved.');

        $this->assertNotFalse($mainStart);
        $this->assertNotFalse($footerPos);
        $this->assertGreaterThan($mainStart, $footerPos, 'The footer must render after <main> opens -- i.e. inside it, part of the scrolling content.');
    }

    /** Sidebar toggle/collapse JS hooks (unchanged function names) still exist for the mobile drawer. */
    public function test_sidebar_toggle_javascript_hooks_are_still_present(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('toggleSidebar()', false);
        $response->assertSee('closeSidebar()', false);
        $response->assertSee('id="sidebarOverlay"', false);
    }
}
