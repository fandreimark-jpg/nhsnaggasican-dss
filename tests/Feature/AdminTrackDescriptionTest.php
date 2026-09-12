<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYSTEM_FIXES_AND_ML_AUDIT.md, "Track description" -- tracks.description
 * is nullable (not every track has one, and existing tracks predate the
 * column), visible on the Admin Tracks page, and settable through the
 * existing Add/Edit Track form.
 */
class AdminTrackDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_track_with_a_description(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/tracks', [
            'name'        => 'Academic Track',
            'code'        => 'acad',
            'description' => 'Prepares learners for higher education through academic subjects.',
        ]);

        $response->assertRedirect(route('admin.tracks'));
        $this->assertDatabaseHas('tracks', [
            'name'        => 'Academic Track',
            'code'        => 'ACAD',
            'description' => 'Prepares learners for higher education through academic subjects.',
        ]);
    }

    public function test_a_track_can_be_created_with_no_description_at_all(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/tracks', [
            'name' => 'Technical-Professional Track',
            'code' => 'techpro',
        ]);

        $response->assertRedirect(route('admin.tracks'));
        $this->assertDatabaseHas('tracks', ['name' => 'Technical-Professional Track', 'description' => null]);
    }

    public function test_admin_can_update_a_tracks_description(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create(['description' => null]);

        $response = $this->actingAs($admin)->put("/admin/tracks/{$track->id}", [
            'name'        => $track->name,
            'code'        => $track->code,
            'description' => 'Updated description.',
        ]);

        $response->assertRedirect(route('admin.tracks'));
        $this->assertDatabaseHas('tracks', ['id' => $track->id, 'description' => 'Updated description.']);
    }

    public function test_description_is_visible_on_the_tracks_page_without_opening_the_database(): void
    {
        $admin = User::factory()->admin()->create();
        Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD', 'description' => 'A real, distinctive description text.']);

        $response = $this->actingAs($admin)->get('/admin/tracks');

        $response->assertOk();
        $response->assertSee('A real, distinctive description text.');
    }

    public function test_a_track_with_no_description_does_not_break_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD', 'description' => null]);

        $response = $this->actingAs($admin)->get('/admin/tracks');

        $response->assertOk();
        $response->assertSee('No description yet');
    }
}
