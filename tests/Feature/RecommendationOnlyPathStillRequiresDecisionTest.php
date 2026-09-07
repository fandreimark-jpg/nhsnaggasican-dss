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
 * "Decision flow, report scoping, and dashboard pass" TASK 1b — the
 * deferred path ("record as a recommendation only — I will decide
 * later"), unticked by default, must still exist and still behave
 * exactly like the old default did: undecided, not actionable by the
 * adviser, until the Principal comes back and decides.
 */
class RecommendationOnlyPathStillRequiresDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_record_with_the_checkbox_creates_an_undecided_recommendation(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'           => $student->id,
            'subject_id'           => $subject->id,
            'grading_period'       => 1,
            'recommended_type'     => 'teacher_monitoring',
            'recommendation_only'  => '1',
        ])->assertSessionHas('success');

        $intervention = Intervention::first();
        $this->assertSame('recommended', $intervention->status);
        $this->assertNull($intervention->decided_by);
        $this->assertNull($intervention->decided_at);
        $this->assertTrue($intervention->awaitingDecision());
    }

    public function test_bulk_record_with_the_checkbox_creates_undecided_recommendations(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'           => $subject->id,
            'grading_period'       => 1,
            'included'             => [$student->id],
            'types'                => [$student->id => 'teacher_monitoring'],
            'statuses'             => [$student->id => 'At Risk'],
            'recommendation_only'  => '1',
        ])->assertRedirect();

        $intervention = Intervention::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('recommended', $intervention->status);
        $this->assertNull($intervention->decided_by);
    }

    public function test_adviser_cannot_acknowledge_a_recommendation_only_intervention(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'           => $student->id,
            'subject_id'           => $subject->id,
            'grading_period'       => 1,
            'recommended_type'     => 'teacher_monitoring',
            'recommendation_only'  => '1',
        ])->assertSessionHas('success');

        $intervention = Intervention::first();

        $this->actingAs($adviser)
            ->post("/adviser/interventions/{$intervention->id}/acknowledge")
            ->assertSessionHas('error');

        $this->assertNull($intervention->fresh()->acknowledged_at);
    }

    public function test_it_appears_under_awaiting_the_principals_decision_on_the_adviser_page(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'DeferredCase']);
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'           => $student->id,
            'subject_id'           => $subject->id,
            'grading_period'       => 1,
            'recommended_type'     => 'teacher_monitoring',
            'recommendation_only'  => '1',
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        // "Master pass" PART 1.3a — recorded through this route by the
        // Principal (origin='principal'), so it groups under this
        // heading, not the DSS-recommendation one.
        $response->assertSee("Recorded by the Principal, not yet approved", false);
        $response->assertSee('DeferredCase');
    }

    /**
     * Once the Principal later decides via approve-pending or update(),
     * the deferred intervention behaves exactly like a normally-recorded
     * one — the deferred path is a delay, never a different workflow.
     */
    public function test_approve_pending_unlocks_a_deferred_intervention(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'           => $student->id,
            'subject_id'           => $subject->id,
            'grading_period'       => 1,
            'recommended_type'     => 'teacher_monitoring',
            'recommendation_only'  => '1',
        ]);

        $intervention = Intervention::first();

        $this->actingAs($principal)->post('/principal/interventions/approve-pending', [
            'ids' => [$intervention->id],
        ])->assertSessionHas('success');

        $intervention->refresh();
        $this->assertSame('approved', $intervention->status);
        $this->assertSame($principal->id, $intervention->decided_by);
        $this->assertNotNull($intervention->decided_at);

        $this->actingAs($adviser)
            ->post("/adviser/interventions/{$intervention->id}/acknowledge")
            ->assertSessionHas('success');
    }
}
