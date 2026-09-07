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
 * "Master pass" PART 1.4c — Approve All Pending must never approve
 * anything outside the filters the Principal was actually viewing.
 * InterventionController::buildFilteredQuery() re-derives the eligible
 * set from the SAME filter params rather than trusting the submitted
 * ids alone, so a stale or tampered id from a different term/subject/
 * section is silently excluded, not approved.
 */
class ApproveAllPendingIsScopedToFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_id_outside_the_submitted_grading_period_filter_is_not_approved(): void
    {
        $principal = User::factory()->principal()->create();
        $subject = Subject::factory()->create();

        $termOneUndecided = Intervention::factory()->create([
            'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'recommended', 'decided_by' => null,
        ]);
        $termTwoUndecided = Intervention::factory()->create([
            'subject_id' => $subject->id, 'grading_period' => 2,
            'status' => 'recommended', 'decided_by' => null,
        ]);

        // Simulates a tampered/stale request: both ids submitted, but the
        // filter fields say "Term 2 only" — as if the Principal were
        // looking at the Term 2 filtered view.
        $this->actingAs($principal)->post('/principal/interventions/approve-pending', [
            'ids'            => [$termOneUndecided->id, $termTwoUndecided->id],
            'grading_period' => 2,
        ])->assertSessionHas('success');

        $termOneUndecided->refresh();
        $termTwoUndecided->refresh();

        $this->assertSame('recommended', $termOneUndecided->status, 'Term 1 row outside the filter must not be approved.');
        $this->assertNull($termOneUndecided->decided_by);

        $this->assertSame('approved', $termTwoUndecided->status, 'Term 2 row matching the filter must be approved.');
        $this->assertSame($principal->id, $termTwoUndecided->decided_by);
    }

    public function test_an_id_outside_the_submitted_subject_filter_is_not_approved(): void
    {
        $principal = User::factory()->principal()->create();
        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();

        $subjectAUndecided = Intervention::factory()->create([
            'subject_id' => $subjectA->id, 'status' => 'recommended', 'decided_by' => null,
        ]);
        $subjectBUndecided = Intervention::factory()->create([
            'subject_id' => $subjectB->id, 'status' => 'recommended', 'decided_by' => null,
        ]);

        $this->actingAs($principal)->post('/principal/interventions/approve-pending', [
            'ids'        => [$subjectAUndecided->id, $subjectBUndecided->id],
            'subject_id' => $subjectA->id,
        ]);

        $this->assertSame('approved', $subjectAUndecided->fresh()->status);
        $this->assertSame('recommended', $subjectBUndecided->fresh()->status);
    }

    public function test_with_no_filters_submitted_every_valid_undecided_id_is_approved(): void
    {
        $principal = User::factory()->principal()->create();
        $a = Intervention::factory()->create(['status' => 'recommended', 'decided_by' => null]);
        $b = Intervention::factory()->create(['status' => 'recommended', 'decided_by' => null]);

        $this->actingAs($principal)->post('/principal/interventions/approve-pending', [
            'ids' => [$a->id, $b->id],
        ])->assertSessionHas('success', '2 intervention(s) approved.');

        $this->assertSame('approved', $a->fresh()->status);
        $this->assertSame('approved', $b->fresh()->status);
    }

    public function test_an_already_decided_id_is_never_re_approved_or_re_logged(): void
    {
        $principal = User::factory()->principal()->create();
        $alreadyDecided = Intervention::factory()->decided('completed')->create();

        $this->actingAs($principal)->post('/principal/interventions/approve-pending', [
            'ids' => [$alreadyDecided->id],
        ]);

        // Untouched — approve-pending only ever moves 'recommended'/
        // undecided rows, never one already decided.
        $this->assertSame('completed', $alreadyDecided->fresh()->status);
    }
}
