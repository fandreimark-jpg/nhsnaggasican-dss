<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYSTEM_FIXES_AND_ML_AUDIT.md, "Remove Hardcoded Academic Year" --
 * Section::activeSchoolYear() (the one resolver every consumer --
 * assessments, grades, reports, risk, interventions, imports,
 * dashboards -- already calls) now reads AcademicYear::active() first,
 * so an Admin can activate a school year explicitly, without needing a
 * section to already exist in it. Falls back to the pre-existing
 * insertion-order inference when nothing is explicitly activated, so
 * every database that predates this table keeps working unchanged.
 */
class AcademicYearConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_no_academic_year_rows_the_existing_section_based_inference_still_works(): void
    {
        Section::factory()->create(['school_year' => '2026-2027']);

        $this->assertSame('2026-2027', Section::activeSchoolYear());
    }

    public function test_an_explicitly_activated_academic_year_overrides_the_section_based_inference(): void
    {
        // A section exists for an OLDER year -- without an explicit
        // active flag, activeSchoolYear() would still return this one.
        Section::factory()->create(['school_year' => '2026-2027']);

        AcademicYear::create(['school_year' => '2027-2028', 'is_active' => true]);

        $this->assertSame('2027-2028', Section::activeSchoolYear());
    }

    public function test_admin_can_create_an_academic_year_without_activating_it(): void
    {
        $admin = User::factory()->admin()->create();
        Section::factory()->create(['school_year' => '2026-2027']);

        $response = $this->actingAs($admin)->post('/admin/academic-years', ['school_year' => '2027-2028']);

        $response->assertRedirect(route('admin.academic-terms'));
        $this->assertDatabaseHas('academic_years', ['school_year' => '2027-2028', 'is_active' => false]);
        // Creating it must not silently activate it.
        $this->assertSame('2026-2027', Section::activeSchoolYear());
    }

    public function test_activating_an_academic_year_deactivates_every_other_one(): void
    {
        $admin = User::factory()->admin()->create();
        $old = AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        $new = AcademicYear::create(['school_year' => '2027-2028', 'is_active' => false]);

        $response = $this->actingAs($admin)->post("/admin/academic-years/{$new->id}/activate");

        $response->assertRedirect(route('admin.academic-terms'));
        $this->assertTrue($new->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame('2027-2028', Section::activeSchoolYear());
    }

    public function test_activating_an_academic_year_ensures_its_three_terms_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $year = AcademicYear::create(['school_year' => '2027-2028', 'is_active' => false]);

        $this->actingAs($admin)->post("/admin/academic-years/{$year->id}/activate");

        $this->assertSame(3, AcademicTerm::where('school_year', '2027-2028')->count());
        $this->assertTrue(AcademicTerm::isOpen('2027-2028', 1));
    }

    public function test_a_duplicate_school_year_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        AcademicYear::create(['school_year' => '2026-2027']);

        $response = $this->actingAs($admin)->post('/admin/academic-years', ['school_year' => '2026-2027']);

        $response->assertSessionHasErrors(['school_year']);
    }

    public function test_academic_years_panel_renders_on_the_academic_terms_page(): void
    {
        $admin = User::factory()->admin()->create();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);

        $response = $this->actingAs($admin)->get('/admin/academic-terms');

        $response->assertOk();
        $response->assertSee('Academic Years');
        $response->assertSee('2026-2027');
    }

    public function test_adviser_cannot_activate_an_academic_year(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);
        $year = AcademicYear::create(['school_year' => '2027-2028']);

        $this->actingAs($adviser)->post("/admin/academic-years/{$year->id}/activate")->assertForbidden();
    }

    public function test_principal_cannot_create_an_academic_year(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->post('/admin/academic-years', ['school_year' => '2027-2028'])->assertForbidden();
    }
}
