<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A UI pass previously added an action button to every empty-state, but
 * several of those screens already have the same action in the card
 * header — the button then visually "moved" depending on whether the
 * table was empty. Rule: an empty state renders a button for an action
 * only when there is no other route to it on that same screen.
 */
class AssessmentsEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_adviser_assessments_empty_state_does_not_duplicate_the_header_upload_button(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->get('/adviser/assessments?period=1&subject_id=' . $subject->id);

        $response->assertOk();
        $response->assertSee('No assessment items uploaded yet');
        $response->assertSee('Upload Assessment Form', false);
        // Exactly one control opens the upload modal — the header button —
        // not a second one duplicated inside the empty state.
        $this->assertSame(1, substr_count($response->getContent(), 'openAssessmentUploadModal()'));
    }

    public function test_admin_crud_pages_render_exactly_one_control_per_action_when_empty(): void
    {
        $admin = User::factory()->admin()->create();

        $cases = [
            '/admin/tracks'          => 'openAddTrackModal()',
            '/admin/specializations' => 'openAddSpecModal()',
            '/admin/subjects'        => 'openAddSubjectModal()',
            '/admin/sections'        => 'openAddSectionModal()',
            '/admin/students'        => 'openAddStudentModal()',
            '/admin/users'           => 'openAddModal()',
        ];

        foreach ($cases as $route => $needle) {
            $response = $this->actingAs($admin)->get($route);
            $response->assertOk();
            $this->assertSame(
                1,
                substr_count($response->getContent(), $needle),
                "Expected exactly one '{$needle}' control on {$route} when empty."
            );
        }
    }
}
