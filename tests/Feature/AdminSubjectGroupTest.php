<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 1 of "DO 015 grading weights" — the Admin subject form's Subject
 * Group field, the thing that decides which subject_group_weights row
 * GradingEngine uses for this subject. Validated against whatever is
 * actually seeded in subject_group_weights, never a hardcoded list.
 */
class AdminSubjectGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_subject_persists_its_chosen_subject_group(): void
    {
        $admin = User::factory()->admin()->create();

        // "Subject classification and grading weights cleanup" pass —
        // research_innovation is elective-only (SubjectGroupWeight::
        // classificationError() rejects it for type=core); Practical
        // Research 1 is a genuine elective in the real DepEd catalog.
        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Practical Research 1', 'type' => 'elective', 'grade_level' => 11,
            'terms' => [1, 2, 3],
            'subject_group' => 'research_innovation',
        ])->assertRedirect(route('admin.subjects'));

        $this->assertDatabaseHas('subjects', [
            'name' => 'Practical Research 1', 'subject_group' => 'research_innovation',
        ]);
    }

    public function test_an_unknown_subject_group_is_rejected_not_silently_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Bogus Subject', 'type' => 'core', 'grade_level' => 11,
            'terms' => [1, 2, 3],
            'subject_group' => 'made_up_group',
        ])->assertSessionHasErrors('subject_group');

        $this->assertDatabaseMissing('subjects', ['name' => 'Bogus Subject']);
    }

    public function test_updating_a_subject_can_change_its_subject_group(): void
    {
        $admin = User::factory()->admin()->create();
        // grade_level pinned to 11 (not left to the factory's random 11/12)
        // — techpro is a Grade 11/do015_2026 group and would be rejected
        // outright for a Grade 12 row (subject_group must be null there).
        $subject = Subject::factory()->create(['type' => 'elective', 'grade_level' => 11, 'subject_group' => 'field_exposure']);

        $this->actingAs($admin)->put(route('admin.subjects.update', $subject->id), [
            'name' => $subject->name, 'type' => 'elective', 'grade_level' => 11,
            'terms' => [1, 2, 3],
            'subject_group' => 'techpro',
        ])->assertRedirect(route('admin.subjects'));

        $this->assertSame('techpro', $subject->fresh()->subject_group);
    }

    /**
     * "ECR alignment" work order, bug found in testing — the five do8_*
     * subject_group_weights rows (Part 2) are computed by
     * GradingEngine::resolveDo8GroupKey() from a section's track and a
     * subject's type; a subject's own subject_group column isn't even
     * read for the do8_2015 scheme. A human must never be able to pick
     * one directly -- doing so would put DO 8 weights on a DO 015
     * subject, silently.
     */
    public function test_the_subject_form_never_offers_a_do8_subject_group(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.subjects'));

        $response->assertOk();
        $subjectGroups = $response->viewData('subjectGroups');

        $this->assertNotEmpty($subjectGroups, 'Sanity check: the do015_2026 groups must still be offered.');
        foreach ($subjectGroups as $group) {
            $this->assertStringStartsNotWith('do8_', $group, "'{$group}' is a do8_* slug and must not be selectable.");
        }
        $this->assertStringNotContainsString('do8_', $response->getContent());
    }

    public function test_a_do8_subject_group_is_rejected_on_create_not_silently_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Sneaky Do8 Subject', 'type' => 'core', 'grade_level' => 12,
            'terms' => [1, 2, 3],
            'subject_group' => 'do8_core',
        ])->assertSessionHasErrors('subject_group');

        $this->assertDatabaseMissing('subjects', ['name' => 'Sneaky Do8 Subject']);
    }
}
