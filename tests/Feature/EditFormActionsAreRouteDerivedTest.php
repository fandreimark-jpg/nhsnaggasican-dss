<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-demo audit (2026-09-17), section 30 — every Edit modal used to set
 * its form action from a hardcoded root-relative path in resources/js/
 * modal.js (`/admin/users/${id}` and six siblings). The Add modals were
 * already route-derived via data-store-url; the Edit half was not, so
 * under the XAMPP sub-directory deployment (http://localhost/
 * naggasican-dss/public/) every "Update" would have posted to the wrong
 * host root. Each form now carries data-update-url rendered by route(),
 * and modal.js reads it instead.
 */
class EditFormActionsAreRouteDerivedTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_edit_form_carries_a_route_derived_update_url(): void
    {
        $admin = User::factory()->admin()->create();

        $expected = [
            '/admin/users'           => route('admin.users.update', ['id' => '__ID__']),
            '/admin/sections'        => route('admin.sections.update', ['id' => '__ID__']),
            '/admin/students'        => route('admin.students.update', ['id' => '__ID__']),
            '/admin/subjects'        => route('admin.subjects.update', ['id' => '__ID__']),
            '/admin/tracks'          => route('admin.tracks.update', ['id' => '__ID__']),
            '/admin/specializations' => route('admin.specializations.update', ['id' => '__ID__']),
        ];

        foreach ($expected as $page => $updateUrl) {
            $html = $this->actingAs($admin)->get($page)->assertOk()->getContent();
            $this->assertStringContainsString('data-update-url="' . $updateUrl . '"', $html, $page);
        }
    }

    public function test_the_adviser_edit_student_form_carries_a_route_derived_update_url(): void
    {
        $adviser = User::factory()->create();
        $html = $this->actingAs($adviser)->get('/adviser/students')->assertOk()->getContent();
        $this->assertStringContainsString(
            'data-update-url="' . route('adviser.students.update', ['id' => '__ID__']) . '"',
            $html
        );
    }

    public function test_modal_js_no_longer_hardcodes_any_root_relative_form_action(): void
    {
        $js = file_get_contents(resource_path('js/modal.js'));
        $this->assertDoesNotMatchRegularExpression('/\.action\s*=\s*`\//', $js);
        $this->assertStringContainsString('window.updateUrlFor', $js);
    }

    /**
     * The Adviser Assessments Edit Assessment Item modal was missed by the
     * 2026-09-17 pass: its inline page script set
     * `.action = '/adviser/assessments/item/' + data.id` (single-quoted, so
     * the modal.js backtick check above never saw it). Under the XAMPP
     * sub-directory it posted outside the app and 404'd.
     */
    public function test_the_adviser_edit_assessment_item_form_carries_a_route_derived_update_url(): void
    {
        [$adviser, $subject] = $this->adviserWithAnAssessmentItem();

        $html = $this->actingAs($adviser)
            ->get('/adviser/assessments?subject_id=' . $subject->id . '&period=1')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-update-url="' . route('adviser.assessments.item.update', ['assessment' => '__ID__']) . '"',
            $html
        );
        $this->assertStringContainsString('window.updateUrlFor(editForm, data.id)', $html);
        $this->assertStringNotContainsString("'/adviser/assessments/item/'", $html);
    }

    public function test_the_edit_assessment_item_url_follows_a_sub_directory_base_url(): void
    {
        [$adviser, $subject] = $this->adviserWithAnAssessmentItem();

        // The request exactly as Apache hands a sub-directory install to
        // public/index.php. Laravel derives the base path from the request
        // (SCRIPT_NAME), as it does under XAMPP; the view supplies none of
        // it. Any host works — nothing here depends on localhost.
        $html = $this->actingAs($adviser)
            ->withServerVariables([
                'SCRIPT_NAME'     => '/naggasican-dss/public/index.php',
                'SCRIPT_FILENAME' => public_path('index.php'),
                'PHP_SELF'        => '/naggasican-dss/public/index.php',
            ])
            ->get('http://school.example/naggasican-dss/public/adviser/assessments?subject_id=' . $subject->id . '&period=1')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-update-url="http://school.example/naggasican-dss/public/adviser/assessments/item/__ID__"',
            $html
        );
    }

    /**
     * Guards every Blade view and every JS source, not only modal.js: no
     * form action may be set from a root-relative app path in any quote
     * style ('/…', "/…" or `/…`).
     */
    public function test_no_view_or_script_sets_a_form_action_from_a_root_relative_path(): void
    {
        $files = array_merge(
            glob(resource_path('js/*.js')),
            \Illuminate\Support\Facades\File::allFiles(resource_path('views'))
        );

        $offenders = [];
        foreach ($files as $file) {
            $path = (string) $file;
            if (preg_match('/\.action\s*=\s*[\'"`]\//', file_get_contents($path))) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame([], $offenders, 'Set form actions with window.updateUrlFor() and a route()-rendered data-update-url.');
    }

    /** @return array{0: User, 1: \App\Models\Subject} */
    private function adviserWithAnAssessmentItem(): array
    {
        \App\Models\AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        \App\Models\AcademicTerm::ensureExistFor('2026-2027');
        \App\Models\AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $adviser = User::factory()->create(['role' => 'adviser']);
        $section = \App\Models\Section::factory()->create([
            'grade_level' => 11, 'school_year' => '2026-2027', 'curriculum' => 'sshs', 'adviser_id' => $adviser->id,
        ]);
        $subject = \App\Models\Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        \App\Models\Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'uploaded_by' => $adviser->id,
        ]);

        return [$adviser, $subject];
    }
}
