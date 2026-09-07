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
 * "Correctness and interface pass" TASK 2 — CLAUDE.md: "the DSS
 * recommends, the Principal decides." An intervention created from the
 * Students page starts at status 'recommended' with no decided_by; an
 * adviser must not be able to acknowledge it (individually or in bulk)
 * until the Principal has actually decided — see
 * Intervention::awaitingDecision().
 */
class CannotAcknowledgeUndecidedInterventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_acknowledge_is_rejected_while_status_is_recommended_and_undecided(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        $response = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response->assertSessionHas('error');
        $intervention->refresh();
        $this->assertNull($intervention->acknowledged_at);
        $this->assertNull($intervention->acknowledged_by);
    }

    public function test_bulk_acknowledge_all_never_includes_an_undecided_intervention(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $undecided = Intervention::factory()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        $response = $this->actingAs($adviser)->post('/adviser/interventions/acknowledge-all');

        $response->assertSessionHas('success', 'Acknowledged 0 intervention(s).');
        $this->assertNull($undecided->fresh()->acknowledged_at);
    }

    public function test_adviser_interventions_page_shows_it_as_awaiting_decision_not_acknowledgeable(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'StillRecommended']);
        $subject = Subject::factory()->create();
        Intervention::factory()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        // "Master pass" PART 1.3a — wording follows origin; a factory-
        // default row (origin='principal') groups under this heading,
        // not the DSS-recommendation one.
        $response->assertSee("Recorded by the Principal, not yet approved", false);
        $response->assertSee('StillRecommended');
    }
}
