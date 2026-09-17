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
}
