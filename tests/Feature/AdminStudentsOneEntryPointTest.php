<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "One entry point on Admin > Students" pass — three equal-weight buttons
 * (Extract Roster, Import Students, Add Student) replaced with a single
 * "Add Students" action opening a chooser. No capability removed: all
 * three original modals still exist, still work, and are reached through
 * the chooser instead of directly.
 *
 * This is the strongest verification available in this environment --
 * there is no browser-automation tool registered in this session, so this
 * asserts against the real rendered HTML from the real Laravel routing/
 * controller/Blade stack (Illuminate's HTTP test kernel), not a literal
 * browser. It cannot catch a JS-only bug in modal.js; a manual click-
 * through is still worth doing once this is deployed.
 */
class AdminStudentsOneEntryPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_has_exactly_one_top_level_entry_point_button(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/students');
        $response->assertOk();
        $html = $response->getContent();

        // Exactly one call to open the chooser, in the page header -- not
        // three separate top-level action buttons any more.
        $this->assertSame(1, substr_count($html, 'onclick="openAddStudentsChooserModal()"'));
        $this->assertStringContainsString('>Add Students<', $html);
    }

    public function test_the_chooser_names_all_three_paths_and_shows_the_ecr_path_as_a_sequence(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/students');
        $html = $response->getContent();

        $this->assertStringContainsString('id="addStudentsChooserModal"', $html);
        $this->assertStringContainsString('From an E-Class Record', $html);
        $this->assertStringContainsString('I already have a CSV', $html);
        $this->assertStringContainsString('One at a time', $html);

        // The ECR option shows a 1-2-3 sequence, not just a label -- the
        // whole point of the restructure per the "one entry point" pass.
        $this->assertStringContainsString('1. Extract', $html);
        $this->assertStringContainsString('2. Correct', $html);
        $this->assertStringContainsString('3. Import', $html);

        // Each choice opens its own existing modal -- capability preserved,
        // only how you get there changed.
        $this->assertStringContainsString('openExtractRosterModal()', $html);
        $this->assertStringContainsString('openImportStudentsModal()', $html);
        $this->assertStringContainsString('openAddStudentModal()', $html);
    }

    public function test_all_three_destination_modals_and_their_real_forms_still_exist(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/students');
        $html = $response->getContent();

        // Extract Roster -- POSTs to the real extraction route, step 1 of 3.
        $this->assertStringContainsString('id="extractRosterModal"', $html);
        $this->assertStringContainsString('Step 1 of 3', $html);
        $this->assertStringContainsString(route('admin.students.extract-roster'), $html);

        // Import Students -- POSTs to the real import route, unchanged.
        $this->assertStringContainsString('id="importStudentsModal"', $html);
        $this->assertStringContainsString(route('admin.students.import'), $html);

        // Add Student (manual) -- POSTs to the real store route, unchanged.
        $this->assertStringContainsString('id="addStudentModal"', $html);
        $this->assertStringContainsString(route('admin.students.store'), $html);
    }

    /**
     * The extraction result panel is session/flash-driven, not tied to any
     * modal's open/close state -- confirming the restructure of the ENTRY
     * buttons didn't touch it. Filename, row count, skipped count, and the
     * name-split convention must all still render exactly as before.
     */
    public function test_the_extraction_result_panel_still_renders_with_every_original_detail(): void
    {
        $admin = User::factory()->admin()->create();

        session([
            'roster_extraction' => [
                'rows'              => [['lrn' => '110000000001', 'last_name' => 'Cruz', 'first_name' => 'Juan', 'middle_name' => 'Reyes', 'gender' => 'male']],
                'source_filename'   => 'shakespeare_ecr.xlsx',
                'skipped_empty'     => 49,
                'missing_lrn_count' => 0,
            ],
        ]);

        $response = $this->actingAs($admin)->get('/admin/students');
        $html = $response->getContent();

        $this->assertStringContainsString('Draft roster from "shakespeare_ecr.xlsx"', $html);
        $this->assertStringContainsString('1 row(s) extracted', $html);
        $this->assertStringContainsString('49 empty row(s) skipped', $html);
        $this->assertStringContainsString('Every row has an LRN', $html);
        $this->assertStringContainsString('Name split: everything before the comma is the last name', $html);
        $this->assertStringContainsString(route('admin.students.extract-roster.download'), $html);
        $this->assertStringContainsString('Step 2 of 3', $html);
    }

    public function test_nothing_else_on_the_students_page_moved(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/students');
        $html = $response->getContent();

        $this->assertStringContainsString('All Students', $html);
        $this->assertStringContainsString('id="studentSearch"', $html);
        $this->assertStringContainsString('id="studentTable"', $html);
        $this->assertStringContainsString('name="section_id"', $html);
    }
}
