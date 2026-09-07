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
 * "Correctness and interface pass" TASK 2 — the other half of
 * CannotAcknowledgeUndecidedInterventionTest: once the Principal actually
 * decides (Principal\InterventionController::update(), which stamps
 * status + decided_by/decided_at), the same intervention becomes
 * acknowledgeable and, after that, deliverable — proving the guard reacts
 * to a real decision rather than being permanently stuck.
 */
class PrincipalDecisionUnlocksAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    public function test_acknowledge_succeeds_once_the_principal_has_decided(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        // Before any decision, acknowledgement is rejected.
        $before = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");
        $before->assertSessionHas('error');
        $this->assertNull($intervention->fresh()->acknowledged_at);

        // The Principal decides.
        $this->actingAs($principal)->put("/principal/interventions/{$intervention->id}", [
            'status' => 'approved',
        ]);
        $intervention->refresh();
        $this->assertSame('approved', $intervention->status);
        $this->assertSame($principal->id, $intervention->decided_by);

        // Now acknowledgement succeeds.
        $after = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");
        $after->assertRedirect();
        $this->assertNotNull($intervention->fresh()->acknowledged_at);
    }

    public function test_deliver_succeeds_once_decided_and_acknowledged(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        $this->actingAs($principal)->put("/principal/interventions/{$intervention->id}", [
            'status' => 'approved',
        ]);
        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Gave the extra support after approval.',
        ]);

        $response->assertRedirect();
        $intervention->refresh();
        $this->assertNotNull($intervention->delivered_at);
        $this->assertSame('Gave the extra support after approval.', $intervention->delivery_notes);
    }
}
