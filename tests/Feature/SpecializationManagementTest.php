<?php

namespace Tests\Feature;

use App\Models\Specialization;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the composite (track_id, code) uniqueness rule added alongside
 * the 2026_09_02_..._add_unique_track_code_to_specializations_table
 * migration. Before this, SpecializationController::store() had no
 * uniqueness check at all, so clicking "Add" twice created two identical
 * rows and SubjectsImport's ->value('id') lookup then picked whichever one
 * MySQL happened to return first.
 */
class SpecializationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_code_under_the_same_track_is_rejected_on_store(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        Specialization::factory()->create(['track_id' => $track->id, 'name' => 'STEM', 'code' => 'STEM']);

        $response = $this->actingAs($admin)->post('/admin/specializations', [
            'track_id' => $track->id,
            'name'     => 'Science Tech Eng Math',
            'code'     => 'STEM',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, Specialization::where('track_id', $track->id)->where('code', 'STEM')->count());
    }

    public function test_same_code_under_a_different_track_is_allowed_on_store(): void
    {
        $admin  = User::factory()->admin()->create();
        $trackA = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        $trackB = Track::factory()->create(['name' => 'Technical-Professional Track', 'code' => 'TVL']);
        Specialization::factory()->create(['track_id' => $trackA->id, 'name' => 'General', 'code' => 'GEN']);

        $response = $this->actingAs($admin)->post('/admin/specializations', [
            'track_id' => $trackB->id,
            'name'     => 'General',
            'code'     => 'GEN',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(2, Specialization::where('code', 'GEN')->count());
    }

    public function test_duplicate_code_under_the_same_track_is_rejected_on_update(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        Specialization::factory()->create(['track_id' => $track->id, 'name' => 'STEM', 'code' => 'STEM']);
        $humss = Specialization::factory()->create(['track_id' => $track->id, 'name' => 'HUMSS', 'code' => 'HUMSS']);

        $response = $this->actingAs($admin)->put("/admin/specializations/{$humss->id}", [
            'track_id' => $track->id,
            'name'     => 'HUMSS',
            'code'     => 'STEM', // collides with the other specialization under the same track
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertSame('HUMSS', $humss->fresh()->code);
    }

    public function test_updating_a_specialization_without_changing_its_own_code_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        $spec  = Specialization::factory()->create(['track_id' => $track->id, 'name' => 'STEM', 'code' => 'STEM']);

        $response = $this->actingAs($admin)->put("/admin/specializations/{$spec->id}", [
            'track_id' => $track->id,
            'name'     => 'Science, Technology, Engineering and Mathematics',
            'code'     => 'STEM',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('Science, Technology, Engineering and Mathematics', $spec->fresh()->name);
    }
}
