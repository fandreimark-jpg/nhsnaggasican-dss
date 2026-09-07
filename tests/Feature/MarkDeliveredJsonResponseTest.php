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
 * Task 4 of "UI cleanup and correctness pass" — Adviser\InterventionController::
 * markDelivered() must content-negotiate the same way verifyComputedGrade()
 * does (see VerifyGradeJsonResponseTest): a JSON Accept header (the row's
 * fetch() submit) gets a JSON body to update the row in place, while a
 * normal form submit keeps the original redirect.
 */
class MarkDeliveredJsonResponseTest extends TestCase
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

    public function test_a_json_accept_header_gets_a_json_body_on_success(): void
    {
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();
        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response = $this->actingAs($adviser)->postJson("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Gave an additional written activity.',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['id', 'delivered_at', 'delivery_notes', 'add_assessment_item_url', 'message']);
        $response->assertJson([
            'id'             => $intervention->id,
            'delivery_notes' => 'Gave an additional written activity.',
        ]);

        $this->assertDatabaseHas('interventions', [
            'id' => $intervention->id, 'delivery_notes' => 'Gave an additional written activity.',
        ]);
    }

    public function test_without_a_json_accept_header_it_still_redirects(): void
    {
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();
        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Gave an additional written activity.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_a_json_request_before_acknowledgement_gets_a_non_2xx_json_error_not_a_redirect(): void
    {
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();

        $response = $this->actingAs($adviser)->postJson("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Should not work yet.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
        $this->assertNull($intervention->fresh()->delivered_at);
    }

    public function test_a_json_request_with_an_empty_note_is_rejected(): void
    {
        ['adviser' => $adviser, 'intervention' => $intervention] = $this->setUpIntervention();
        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response = $this->actingAs($adviser)->postJson("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => '',
        ]);

        $response->assertStatus(422);
        $this->assertNull($intervention->fresh()->delivered_at);
    }
}
