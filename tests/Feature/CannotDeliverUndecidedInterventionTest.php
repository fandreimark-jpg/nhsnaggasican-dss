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
 * "Correctness and interface pass" TASK 2 — delivery must never happen
 * ahead of a Principal decision either, individually or in a group. Even
 * if acknowledged_at were somehow already set on an old/tampered row, an
 * undecided intervention (status 'recommended', no decided_by) is never
 * eligible for delivery — see Intervention::awaitingDecision() and
 * Adviser\InterventionController::markDelivered()/markDeliveredGroup().
 */
class CannotDeliverUndecidedInterventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deliver_is_rejected_for_an_undecided_intervention_even_if_acknowledged(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'acknowledged_at' => now(), 'acknowledged_by' => $adviser->id,
        ]);

        $response = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Should not be allowed yet.',
        ]);

        $response->assertSessionHas('error');
        $intervention->refresh();
        $this->assertNull($intervention->delivered_at);
        $this->assertNull($intervention->delivered_by);
    }

    public function test_group_deliver_silently_excludes_an_undecided_intervention_from_the_batch(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $decidedStudent = Student::factory()->create(['section_id' => $section->id]);
        $decided = Intervention::factory()->decided()->create(['student_id' => $decidedStudent->id, 'subject_id' => $subject->id]);
        $decided->update(['acknowledged_at' => now(), 'acknowledged_by' => $adviser->id]);

        $undecidedStudent = Student::factory()->create(['section_id' => $section->id]);
        $undecided = Intervention::factory()->create([
            'student_id' => $undecidedStudent->id, 'subject_id' => $subject->id,
            'acknowledged_at' => now(), 'acknowledged_by' => $adviser->id,
        ]);

        $response = $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => [$decided->id, $undecided->id],
            'delivery_notes'   => 'One shared activity covering the eligible learners.',
        ]);

        $response->assertSessionHas('success');
        $this->assertNotNull($decided->fresh()->delivered_at, 'The decided, eligible intervention should still be delivered.');
        $this->assertNull($undecided->fresh()->delivered_at, 'An undecided intervention must never be delivered, even inside a group batch.');
    }
}
