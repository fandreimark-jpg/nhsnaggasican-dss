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
 * "Decision flow, report scoping, and dashboard pass" TASK 1 — the end
 * to end payoff of making recording the decision: a Principal recording
 * interventions in bulk, with no further action, must have them appear
 * in the adviser's ACTIONABLE list (not the "awaiting decision" one) and
 * be acknowledgeable/deliverable right away.
 */
class AdviserSeesDecidedInterventionsImmediatelyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_recorded_interventions_are_immediately_actionable_by_the_adviser(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'BulkDecided']);
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [$student->id],
            'types'          => [$student->id => 'teacher_monitoring'],
            'statuses'       => [$student->id => 'At Risk'],
        ])->assertRedirect();

        $intervention = Intervention::where('student_id', $student->id)->firstOrFail();

        // Not stuck under "awaiting decision" — visible and actionable now.
        $page = $this->actingAs($adviser)->get('/adviser/interventions');
        $page->assertOk();
        $page->assertSee('BulkDecided');
        $page->assertDontSee("Awaiting the Principal's decision", false);

        $this->actingAs($adviser)
            ->post("/adviser/interventions/{$intervention->id}/acknowledge")
            ->assertSessionHas('success');

        $this->actingAs($adviser)
            ->post("/adviser/interventions/{$intervention->id}/deliver", ['delivery_notes' => 'Met with student.'])
            ->assertSessionHas('success');

        $intervention->refresh();
        $this->assertNotNull($intervention->acknowledged_at);
        $this->assertNotNull($intervention->delivered_at);
    }
}
