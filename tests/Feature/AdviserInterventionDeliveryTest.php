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
 * Task 2 of "close the delivery loop": a record that the decision was
 * actually CARRIED OUT, not just seen. Acknowledged -> Delivered is the
 * only order this can happen in, and delivery is a record of action
 * taken — the adviser still never touches status/recommended_type/
 * principal_notes.
 */
class AdviserInterventionDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function setUpIntervention(): array
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        return compact('adviser', 'section', 'student', 'subject', 'intervention');
    }

    public function test_mark_as_delivered_is_rejected_before_acknowledgement(): void
    {
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();

        $response = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Gave the extra performance task.',
        ]);

        $response->assertSessionHas('error');
        $intervention->refresh();
        $this->assertNull($intervention->delivered_at);
        $this->assertNull($intervention->delivered_by);
    }

    public function test_mark_as_delivered_succeeds_after_acknowledgement_and_records_the_note(): void
    {
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();

        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Gave the extra performance task on 09/10.',
        ]);

        $response->assertRedirect();
        $intervention->refresh();
        $this->assertNotNull($intervention->delivered_at);
        $this->assertSame($adviser->id, $intervention->delivered_by);
        $this->assertSame('Gave the extra performance task on 09/10.', $intervention->delivery_notes);
    }

    public function test_delivery_notes_are_required(): void
    {
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();
        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => '',
        ]);

        $response->assertSessionHasErrors('delivery_notes');
        $this->assertNull($intervention->fresh()->delivered_at);
    }

    public function test_delivery_never_changes_status_type_or_principal_notes(): void
    {
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();
        // 'approved' (not 'recommended') — this test is about the DELIVER
        // request body being unable to tamper with status/type/notes, which
        // needs a decided (acknowledgeable/deliverable) record to begin with.
        $intervention->update(['status' => 'approved', 'recommended_type' => 'remediation', 'principal_notes' => 'Original']);
        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Done.',
            'status' => 'completed', 'recommended_type' => 'other', 'principal_notes' => 'Tampered',
        ]);

        $intervention->refresh();
        $this->assertSame('approved', $intervention->status);
        $this->assertSame('remediation', $intervention->recommended_type);
        $this->assertSame('Original', $intervention->principal_notes);
    }

    public function test_marking_delivery_on_another_sections_intervention_is_forbidden(): void
    {
        $ownAdviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $ownAdviser->id]);

        ['intervention' => $otherIntervention] = $this->setUpIntervention();
        $otherIntervention->update(['acknowledged_at' => now(), 'acknowledged_by' => $otherIntervention->created_by]);

        $response = $this->actingAs($ownAdviser)->post("/adviser/interventions/{$otherIntervention->id}/deliver", [
            'delivery_notes' => 'Should not work.',
        ]);

        $response->assertForbidden();
        $this->assertNull($otherIntervention->fresh()->delivered_at);
    }

    public function test_adviser_list_shows_the_three_delivery_states(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $notAcked = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'NotAcked']);
        Intervention::factory()->decided()->create(['student_id' => $notAcked->id, 'subject_id' => $subject->id]);

        $acked = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Acked']);
        $ackedIv = Intervention::factory()->decided()->create(['student_id' => $acked->id, 'subject_id' => $subject->id]);
        $ackedIv->update(['acknowledged_at' => now(), 'acknowledged_by' => $adviser->id]);

        $delivered = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Delivered']);
        $deliveredIv = Intervention::factory()->decided()->create(['student_id' => $delivered->id, 'subject_id' => $subject->id]);
        $deliveredIv->update([
            'acknowledged_at' => now(), 'acknowledged_by' => $adviser->id,
            'delivered_at' => now(), 'delivered_by' => $adviser->id, 'delivery_notes' => 'All done.',
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertSee('Not yet acknowledged');
        $response->assertSee('Mark as Delivered');
        $response->assertSee('Delivered');
        $response->assertSee('All done.');
    }

    public function test_principal_interventions_page_shows_delivery_note_and_both_timestamps(): void
    {
        $principal = User::factory()->principal()->create();
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();

        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");
        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Extra performance task given and reviewed.',
        ]);

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertSee('Extra performance task given and reviewed.');
        $response->assertSee('Delivered by');
        $response->assertSee('Ack. by');
    }
}
