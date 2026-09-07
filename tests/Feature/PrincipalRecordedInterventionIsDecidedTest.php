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
 * "Decision flow, report scoping, and dashboard pass" TASK 1a — every
 * intervention in this system is created BY the Principal, reviewing
 * this exact student/batch right now. Recording it is the decision;
 * a second "Update" click adds no judgment the first one didn't already
 * contain. Both the single-record and bulk-record routes must default
 * to an approved, decided intervention — see
 * Principal\InterventionController::store()/storeBulk().
 */
class PrincipalRecordedInterventionIsDecidedTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_record_creates_an_approved_and_decided_intervention(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'teacher_monitoring',
        ])->assertSessionHas('success');

        $intervention = Intervention::first();
        $this->assertSame('approved', $intervention->status);
        $this->assertSame($principal->id, $intervention->decided_by);
        $this->assertNotNull($intervention->decided_at);
        $this->assertFalse($intervention->awaitingDecision());
    }

    public function test_bulk_record_creates_approved_and_decided_interventions(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [$student->id],
            'types'          => [$student->id => 'teacher_monitoring'],
            'statuses'       => [$student->id => 'At Risk'],
        ])->assertRedirect();

        $intervention = Intervention::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('approved', $intervention->status);
        $this->assertSame($principal->id, $intervention->decided_by);
        $this->assertNotNull($intervention->decided_at);
    }

    /**
     * The immediate practical consequence: no extra "Update" click is
     * needed before the adviser can act.
     */
    public function test_adviser_can_immediately_acknowledge_a_normally_recorded_intervention(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'teacher_monitoring',
        ])->assertSessionHas('success');

        $intervention = Intervention::first();

        $this->actingAs($adviser)
            ->post("/adviser/interventions/{$intervention->id}/acknowledge")
            ->assertSessionHas('success');

        $this->assertNotNull($intervention->fresh()->acknowledged_at);
    }
}
