<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Clarity, progress, and visual design pass" TASK 3f — the ONE exception
 * to "delivery is never bulk" (group delivery, for a genuine shared
 * activity) still requires a REAL note. An empty or whitespace-only note
 * must never mark anything delivered, for any number of selected
 * interventions.
 */
class GroupDeliveryRequiresNoteTest extends TestCase
{
    use RefreshDatabase;

    private function makeEligibleInterventions(User $adviser, Section $section, Subject $subject, int $count): \Illuminate\Support\Collection
    {
        return collect(range(1, $count))->map(function () use ($section, $subject) {
            $student = Student::factory()->create(['section_id' => $section->id]);
            $intervention = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);
            $intervention->update(['acknowledged_at' => now(), 'acknowledged_by' => $intervention->created_by]);
            return $intervention;
        });
    }

    public function test_an_empty_note_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $interventions = $this->makeEligibleInterventions($adviser, $section, $subject, 3);

        $response = $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $interventions->pluck('id')->all(),
            'delivery_notes'   => '',
        ]);

        $response->assertSessionHasErrors('delivery_notes');
        foreach ($interventions as $iv) {
            $this->assertNull($iv->fresh()->delivered_at);
        }
    }

    public function test_a_whitespace_only_note_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $interventions = $this->makeEligibleInterventions($adviser, $section, $subject, 2);

        // 25 raw characters, but nothing but spaces — must fail the
        // TRIMMED length check, not just Laravel's byte-length min:20.
        $response = $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $interventions->pluck('id')->all(),
            'delivery_notes'   => str_repeat(' ', 25),
        ]);

        $response->assertSessionHasErrors('delivery_notes');
        foreach ($interventions as $iv) {
            $this->assertNull($iv->fresh()->delivered_at);
        }
    }

    public function test_a_note_under_twenty_meaningful_characters_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $interventions = $this->makeEligibleInterventions($adviser, $section, $subject, 2);

        $response = $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $interventions->pluck('id')->all(),
            'delivery_notes'   => 'Too short.',
        ]);

        $response->assertSessionHasErrors('delivery_notes');
        foreach ($interventions as $iv) {
            $this->assertNull($iv->fresh()->delivered_at);
        }
    }

    public function test_a_real_note_of_twenty_or_more_characters_succeeds_for_every_selected_intervention(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $interventions = $this->makeEligibleInterventions($adviser, $section, $subject, 3);

        $response = $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $interventions->pluck('id')->all(),
            'delivery_notes'   => 'Ran one group re-teaching session for all three students together.',
        ]);

        $response->assertRedirect();
        foreach ($interventions as $iv) {
            $fresh = $iv->fresh();
            $this->assertNotNull($fresh->delivered_at);
            $this->assertSame($adviser->id, $fresh->delivered_by);
            $this->assertSame('Ran one group re-teaching session for all three students together.', $fresh->delivery_notes);
        }
    }

    public function test_group_delivery_requires_at_least_one_selected_intervention(): void
    {
        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id]);

        $response = $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => [],
            'delivery_notes'   => 'This has plenty of real characters in it.',
        ]);

        $response->assertSessionHasErrors('intervention_ids');
    }
}
