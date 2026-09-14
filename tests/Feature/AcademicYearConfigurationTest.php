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

    public function test_year_edit_validation_and_safe_date_corrections(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $year = AcademicYear::create(['school_year' => '2030-2031']);
        AcademicYear::create(['school_year' => '2031-2032']);
        foreach (['2031-2032', '2030', '2030/2031', '2030-2032', 'abcd-efgh'] as $invalid) {
            $this->put(route('admin.academic-years.update', $year), ['school_year' => $invalid])->assertSessionHasErrors('school_year');
        }
        foreach (['2030-06-01', '2030-05-01'] as $end) {
            $this->put(route('admin.academic-years.update', $year), ['school_year' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => $end])->assertSessionHasErrors('end_date');
        }
        $this->put(route('admin.academic-years.update', $year), ['school_year' => '2032-2033', 'end_date' => '2033-04-01'])->assertSessionHas('success');
        $this->assertSame('2032-2033', $year->fresh()->school_year);
        $section = Section::factory()->create(['school_year' => '2032-2033']);
        $this->put(route('admin.academic-years.update', $year), ['school_year' => '2034-2035'])->assertSessionHasErrors('school_year');
        $this->put(route('admin.academic-years.update', $year), ['school_year' => '2032-2033', 'start_date' => '2032-06-01', 'end_date' => '2033-04-01'])->assertSessionHas('success');
        $this->assertSame('2032-06-01', $year->fresh()->start_date->format('Y-m-d'));
        $this->assertModelExists($section);
        $this->assertModelExists($year);
    }

    public function test_non_admins_cannot_manage_years_or_terms(): void
    {
        $year = AcademicYear::create(['school_year' => '2030-2031']);
        AcademicTerm::ensureExistFor($year->school_year);
        $term = AcademicTerm::first();
        foreach (['adviser', 'principal'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('admin.academic-terms'))->assertForbidden();
            $this->post(route('admin.academic-years.store'), ['school_year' => '2032-2033'])->assertForbidden();
            $this->post(route('admin.academic-years.activate', $year))->assertForbidden();
            foreach (['academic-years' => $year, 'academic-terms' => $term] as $resource => $record) {
                $this->put(route("admin.$resource.update", $record), [])->assertForbidden();
            }
            $this->post(route('admin.academic-terms.open', 1))->assertForbidden();
            $this->post(route('admin.academic-terms.close', 1))->assertForbidden();
        }
    }

    public function test_edit_requires_csrf_outside_laravels_test_bypass(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $year = AcademicYear::create(['school_year' => '2030-2031']);
        $this->app['env'] = 'local';
        try {
            $this->post(route('admin.academic-years.update', $year), ['_method' => 'PUT', 'school_year' => $year->school_year])->assertStatus(419);
            $this->assertModelExists($year);
            $this->withSession(['_token' => 'academic-config-csrf-test'])
                ->post(route('admin.academic-years.update', $year), ['_method' => 'PUT', '_token' => 'academic-config-csrf-test', 'school_year' => $year->school_year, 'start_date' => '2030-06-01'])
                ->assertSessionHas('success');
            $this->assertSame('2030-06-01', $year->fresh()->start_date->format('Y-m-d'));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_term_configuration_prevents_year_rename_but_allows_date_correction(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $year = AcademicYear::create(['school_year' => '2030-2031']);
        AcademicTerm::ensureExistFor($year->school_year);
        AcademicTerm::where('school_year', $year->school_year)->update(['is_open' => false]);
        $this->put(route('admin.academic-years.update', $year), ['school_year' => '2031-2032'])->assertSessionHasErrors('school_year');
        $this->put(route('admin.academic-years.update', $year), ['school_year' => $year->school_year, 'start_date' => '2030-06-01'])->assertSessionHas('success');
        $this->assertSame('2030-06-01', $year->fresh()->start_date->format('Y-m-d'));
        $this->assertSame(3, AcademicTerm::where('school_year', $year->school_year)->count());
    }
}
