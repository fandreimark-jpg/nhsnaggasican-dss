<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "UI legibility pass" — the Recent Activity panel's empty state used to be
 * a bare "No activity recorded yet." with no hint line at all, unlike every
 * other empty state audited in this pass, which pairs the message with a
 * line saying why or what happens next. Matches the wording already used
 * on the Activity Logs page for the same concept.
 */
class AdminDashboardEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_activity_empty_state_explains_that_it_fills_in_automatically(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('No activity recorded yet.');
        $response->assertSee('Every create, update, and delete across the system is recorded here as it happens.');
    }
}
