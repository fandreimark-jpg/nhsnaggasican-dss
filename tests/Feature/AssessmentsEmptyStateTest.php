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
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;

    public function test_the_verification_empty_state_points_to_the_class_record_and_the_old_page_redirects(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->get('/adviser/verify-grades?period=1&subject_id=' . $subject->id);
        $response->assertOk();
        $response->assertSee('No assessment evidence yet');
        $this->assertStringContainsString(e(route('teacher.class-record', ['section_id' => $section->id, 'subject_id' => $subject->id, 'period' => 1])), $response->getContent());
        $this->assertStringNotContainsString('Upload Assessment Form', $response->getContent());

        // The retired page is no longer a destination.
        $this->actingAs($adviser)->get('/adviser/assessments?period=1&subject_id=' . $subject->id)
            ->assertRedirect(route('teacher.class-record', ['school_year' => $section->school_year, 'section_id' => $section->id, 'subject_id' => $subject->id, 'period' => 1]));
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
