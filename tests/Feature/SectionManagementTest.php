<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Specialization;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function sectionPayload(array $overrides = []): array
    {
        $track          = Track::factory()->create();
        $specialization = Specialization::factory()->create(['track_id' => $track->id]);

        return array_merge([
            'name'              => 'Narra',
            'grade_level'       => 11,
            'track_id'          => $track->id,
            'specialization_id' => $specialization->id,
            'school_year'       => '2026-2027',
            'adviser_id'        => '',
        ], $overrides);
    }

    public function test_a_section_can_be_created_with_no_adviser_assigned(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/sections', $this->sectionPayload());

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.sections'));
        $this->assertDatabaseHas('sections', ['name' => 'Narra', 'adviser_id' => null]);
    }

    public function test_only_a_user_with_the_adviser_role_can_be_assigned_to_a_section(): void
    {
        $admin        = User::factory()->admin()->create();
        // Inactive — only one ACTIVE admin account is allowed at a time
        // (see UserManagementTest); this admin only needs to exist with
        // the 'admin' role for this assertion, not be a second active one.
        $anotherAdmin = User::factory()->admin()->inactive()->create();

        $response = $this->actingAs($admin)->post('/admin/sections', $this->sectionPayload([
            'adviser_id' => $anotherAdmin->id,
        ]));

        $response->assertSessionHasErrors('adviser_id');
        $this->assertDatabaseMissing('sections', ['name' => 'Narra']);
    }

    public function test_a_real_adviser_can_be_assigned_to_a_section(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create(); // default role is 'adviser'

        $response = $this->actingAs($admin)->post('/admin/sections', $this->sectionPayload([
            'adviser_id' => $adviser->id,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sections', ['name' => 'Narra', 'adviser_id' => $adviser->id]);
    }

    /**
     * SYSTEM_FIXES_AND_ML_AUDIT.md, "sections/curriculum reconciliation"
     * -- the manual Add/Edit Section form can now set curriculum
     * explicitly, same optional treatment as the bulk importer (see
     * SectionsImportTest).
     */
    public function test_curriculum_is_optional_on_manual_create(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/sections', $this->sectionPayload());

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sections', ['name' => 'Narra', 'curriculum' => null]);
    }

    public function test_curriculum_can_be_set_explicitly_on_manual_create(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/sections', $this->sectionPayload(['curriculum' => 'sshs']));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sections', ['name' => 'Narra', 'curriculum' => 'sshs']);
    }

    public function test_an_unrecognized_curriculum_value_is_rejected_on_manual_create(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/sections', $this->sectionPayload(['curriculum' => 'not_a_real_curriculum']));

        $response->assertSessionHasErrors('curriculum');
        $this->assertDatabaseMissing('sections', ['name' => 'Narra']);
    }

    public function test_curriculum_can_be_updated_on_an_existing_section(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create();
        $specialization = Specialization::factory()->create(['track_id' => $track->id]);
        $section = Section::factory()->create([
            'track_id' => $track->id, 'specialization_id' => $specialization->id, 'curriculum' => null,
        ]);

        $response = $this->actingAs($admin)->put("/admin/sections/{$section->id}", $this->sectionPayload([
            'name' => $section->name, 'curriculum' => 'k12_2013',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('k12_2013', $section->fresh()->curriculum);
    }
}
