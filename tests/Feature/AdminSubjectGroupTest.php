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

        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Practical Research 1', 'type' => 'core', 'grade_level' => 11,
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
            'subject_group' => 'made_up_group',
        ])->assertSessionHasErrors('subject_group');

        $this->assertDatabaseMissing('subjects', ['name' => 'Bogus Subject']);
    }

    public function test_updating_a_subject_can_change_its_subject_group(): void
    {
        $admin = User::factory()->admin()->create();
        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);

        $this->actingAs($admin)->put(route('admin.subjects.update', $subject->id), [
            'name' => $subject->name, 'type' => $subject->type, 'grade_level' => $subject->grade_level,
            'subject_group' => 'techpro',
        ])->assertRedirect(route('admin.subjects'));

        $this->assertSame('techpro', $subject->fresh()->subject_group);
    }
}
