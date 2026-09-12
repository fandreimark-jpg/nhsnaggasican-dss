<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYSTEM_FIXES_AND_ML_AUDIT.md, "Add Shared Footer" -- one partial
 * (resources/views/partials/footer.blade.php) included once from
 * layouts/app.blade.php, so it renders for every role without being
 * copied into each one's views. Year is date('Y') at render time, never
 * a literal, so this test would fail the moment someone hardcodes a year
 * back in.
 */
class SharedFooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_with_the_current_year_renders_on_the_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee(date('Y') . ' Naggasican National High School');
    }

    public function test_footer_renders_on_the_adviser_dashboard(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee(date('Y') . ' Naggasican National High School');
    }

    public function test_footer_renders_on_the_principal_dashboard(): void
    {
        $principal = User::factory()->create(['role' => 'principal']);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee(date('Y') . ' Naggasican National High School');
    }
}
