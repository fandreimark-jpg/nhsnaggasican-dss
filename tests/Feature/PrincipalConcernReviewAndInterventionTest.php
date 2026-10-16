<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Rule-based academic monitoring" Phase 2E — Principal review and
 * intervention integration.
 *
 *   FOR REVIEW  open concern the rules found in current evidence
 *   REVIEWED    the Principal inspected the evidence — nothing else changes
 *   INTERVENTION a separate Principal decision, optionally linked to a concern
 *   RESOLVED    the evidence no longer meets the rule (monitoring only)
 */
class PrincipalConcernReviewAndInterventionTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $adviser;
    private Section $agila;
    private Subject $cesc;
    private Assessment $quiz1;
    private array $learners = [];
    private AssessmentStatusService $statuses;
    private AcademicMonitoringService $monitoring;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->principal = User::factory()->principal()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $track = Track::factory()->create();
        $humss = Specialization::factory()->create(['track_id' => $track->id, 'name' => 'Humanities and Social Sciences', 'code' => 'HUMSS', 'curriculum' => 'k12_2013']);
        $this->agila = Section::factory()->create([
            'name' => 'Agila', 'grade_level' => 12, 'school_year' => '2026-2027',
            'track_id' => $track->id, 'specialization_id' => $humss->id, 'adviser_id' => $this->adviser->id,
        ]);
        $this->cesc = Subject::factory()->create([
            'name' => 'Community Engagement Solidarity and Citizenship', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic',
        ]);
        foreach (['GALANG' => 'VINCENT', 'ILAGAN' => 'RENZO', 'FERNANDEZ' => 'KYLA'] as $last => $first) {
            $this->learners[$last] = Student::factory()->create([
                'section_id' => $this->agila->id, 'last_name' => $last, 'first_name' => $first,
                'lrn' => '1200000000' . str_pad((string) (count($this->learners) + 1), 2, '0', STR_PAD_LEFT),
            ]);
        }

        $this->quiz1 = Assessment::factory()->create([
            'subject_id' => $this->cesc->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1',
            'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);
        $this->statuses = new AssessmentStatusService();
        $this->monitoring = app(AcademicMonitoringService::class);

        foreach (['GALANG', 'ILAGAN', 'FERNANDEZ'] as $last) {
            $this->statuses->markMissing($this->quiz1, $this->learners[$last]->id, $this->adviser->id);
        }
        $this->sync();
        $this->statuses->recordScore($this->quiz1, $this->learners['FERNANDEZ']->id, 14); // resolves Fernandez
        $this->sync();
    }

    // --- helpers -----------------------------------------------------------

    private function sync(): void
    {
        $this->monitoring->synchronizeAssessments([$this->quiz1->id]);
    }

    private function concern(string $last, string $status = 'open'): AcademicConcern
    {
        return AcademicConcern::where('student_id', $this->learners[$last]->id)->where('status', $status)->sole();
    }

    private function review(AcademicConcern $concern, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->principal)
            ->from(route('principal.academic-monitoring'))
            ->post(route('principal.academic-monitoring.review', $concern), $data);
    }

    private function recordFromConcern(AcademicConcern $concern, array $overrides = [])
    {
        return $this->actingAs($this->principal)
            ->from(route('principal.academic-monitoring'))
            ->post(route('principal.interventions.store'), array_merge([
                'academic_concern_id'   => $concern->id,
                'student_id'            => $concern->student_id,
                'subject_id'            => $concern->subject_id,
                'grading_period'        => $concern->grading_period,
                'recommended_type'      => 'teacher_monitoring',
                'recommendation_reason' => 'Missing Requirement: Quiz 1 (Written Work) was explicitly marked Missing by the adviser.',
            ], $overrides));
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->principal)->get(route('principal.academic-monitoring', $query));
    }

    private function block(string $html, int $id): string
    {
        $start = strpos($html, 'data-concern-id="' . $id . '"');
        $this->assertNotFalse($start, "concern #{$id} is on the page");
        $next = strpos($html, 'data-concern-id="', $start + 20);

        return substr($html, $start, $next === false ? null : $next - $start);
    }

    // --- A-G. Mark as Reviewed ---------------------------------------------------

    public function test_a_to_g_the_principal_marks_an_open_concern_reviewed_and_nothing_else_changes(): void
    {
        $concern = $this->concern('GALANG');
        $before = $concern->fresh()->toArray();
        $counts = fn() => [
            DB::table('interventions')->count(), DB::table('assessment_scores')->count(),
            DB::table('assessment_learner_statuses')->count(), DB::table('academic_concerns')->count(),
        ];
        $countsBefore = $counts();

        $this->travel(1)->minutes();
        $this->review($concern)->assertRedirect(route('principal.academic-monitoring'))
            ->assertSessionHas('success', 'Marked as reviewed. The concern stays open until its evidence changes; no intervention was created.');

        $after = $concern->fresh();
        $this->assertSame($this->principal->id, $after->reviewed_by);             // B
        $this->assertNotNull($after->reviewed_at);                                  // C
        $this->assertNull($after->review_note);
        $this->assertSame(AcademicConcern::STATUS_OPEN, $after->status);           // E
        $this->assertNull($after->resolved_at);                                     // F
        $this->assertNull($after->resolution_reason);
        foreach (['student_id', 'subject_id', 'section_id', 'school_year', 'grading_period', 'rule_key', 'rule_version',
                  'context_key', 'open_key', 'evidence', 'first_detected_at', 'last_evaluated_at'] as $field) {
            $this->assertSame($before[$field], $after->toArray()[$field], "$field is unchanged");
        }
        $this->assertSame($countsBefore, $counts());                                // G: no intervention, no evidence change

        $log = ActivityLog::where('action', 'review_academic_concern')->sole();
        $this->assertSame($this->principal->id, $log->user_id);
        $this->assertStringContainsString('#' . $concern->id, $log->description);
    }

    public function test_d_an_optional_plain_text_note_is_stored_and_html_or_overlong_notes_are_refused(): void
    {
        $galang = $this->concern('GALANG');
        $this->review($galang, ['review_note' => '<b>checked</b>'])->assertSessionHasErrors('review_note');
        $this->review($galang, ['review_note' => str_repeat('x', 1001)])->assertSessionHasErrors('review_note');
        $this->assertNull($galang->fresh()->reviewed_at, 'a refused note stores nothing');

        $this->review($galang, ['review_note' => '  Adviser confirmed Quiz 1 was not submitted.  '])->assertSessionHas('success');
        $this->assertSame('Adviser confirmed Quiz 1 was not submitted.', $galang->fresh()->review_note);

        $this->assertStringNotContainsString('Adviser confirmed', ActivityLog::where('action', 'review_academic_concern')->sole()->description,
            'the note is not copied into the activity log');
    }

    // --- H-J. Authorization -----------------------------------------------------------

    public function test_h_i_j_adviser_admin_and_guest_cannot_review(): void
    {
        $concern = $this->concern('GALANG');

        $this->review($concern, [], $this->adviser)->assertForbidden();
        $this->review($concern, [], User::factory()->admin()->create())->assertForbidden();
        auth()->logout();
        $this->post(route('principal.academic-monitoring.review', $concern))->assertRedirect(route('login'));

        $this->assertNull($concern->fresh()->reviewed_at);
    }

    // --- K-L. History and determinism ------------------------------------------------------

    public function test_k_a_resolved_concern_is_refused_and_not_rewritten(): void
    {
        $resolved = $this->concern('FERNANDEZ', 'resolved');
        $before = $resolved->fresh()->toArray();

        $this->review($resolved, ['review_note' => 'late'])
            ->assertSessionHas('error', 'This concern is already resolved, so it is kept as history and cannot be marked as reviewed.');

        $this->assertSame($before, $resolved->fresh()->toArray());
    }

    public function test_l_a_second_review_is_refused_and_the_first_is_kept(): void
    {
        $concern = $this->concern('GALANG');
        $this->review($concern, ['review_note' => 'first'])->assertSessionHas('success');
        $first = $concern->fresh()->toArray();

        $this->travel(5)->minutes();
        $this->review($concern, ['review_note' => 'second'])
            ->assertSessionHas('error', 'This concern has already been marked as reviewed. The existing review is kept.');

        $this->assertSame($first, $concern->fresh()->toArray());
        $this->assertSame(1, ActivityLog::where('action', 'review_academic_concern')->count());
    }

    // --- M-O. Re-evaluation keeps the review ----------------------------------------------------

    public function test_m_n_re_evaluating_identical_evidence_keeps_the_review(): void
    {
        $concern = $this->concern('GALANG');
        $this->review($concern, ['review_note' => 'seen']);
        $reviewed = $concern->fresh();

        $this->travel(10)->minutes();
        $this->sync();
        $this->monitoring->evaluate('2026-2027', 1);

        $after = $concern->fresh();
        $this->assertTrue($after->last_evaluated_at->greaterThan($reviewed->last_evaluated_at), 'it was re-evaluated');
        $this->assertSame($reviewed->reviewed_by, $after->reviewed_by);
        $this->assertTrue($reviewed->reviewed_at->equalTo($after->reviewed_at));
        $this->assertSame('seen', $after->review_note);
    }

    public function test_o_a_refreshed_but_non_material_evidence_change_keeps_the_review(): void
    {
        // For EXPLICIT_MISSING_REQUIREMENT the material facts — rule, learner,
        // assessment item, Missing status — are the concern's identity
        // (open_key); they cannot change while it stays open. A change that
        // does happen in place, such as the item's max score, refreshes the
        // evidence but is not material to "this learner is Missing Quiz 1".
        $concern = $this->concern('GALANG');
        $this->review($concern);

        $this->quiz1->update(['max_score' => 25]);
        $report = $this->monitoring->synchronizeAssessments([$this->quiz1->id]);

        $this->assertCount(2, $report[0]['refreshed'], 'the evidence was refreshed');
        $after = $concern->fresh();
        $this->assertEquals(25, $after->evidence['assessment']['max_score']);
        $this->assertNotNull($after->reviewed_at, 'a non-material refresh never clears the review');
        $this->assertSame($this->principal->id, $after->reviewed_by);
    }

    // --- P-T. Record Intervention from a concern ---------------------------------------------------

    public function test_p_y_an_intervention_recorded_from_a_concern_links_to_it_without_any_risk_result(): void
    {
        $this->assertSame(0, RiskResult::count());
        $concern = $this->concern('GALANG');

        $this->recordFromConcern($concern)->assertSessionHas('success');

        $iv = Intervention::sole();
        $this->assertSame($concern->id, $iv->academic_concern_id);
        $this->assertSame($this->learners['GALANG']->id, $iv->student_id);
        $this->assertSame($this->cesc->id, $iv->subject_id);
        $this->assertSame(1, $iv->grading_period);
        $this->assertSame($this->agila->id, $iv->section_id);
        $this->assertSame('2026-2027', $iv->school_year);
        $this->assertSame('teacher_monitoring', $iv->recommended_type);
        $this->assertSame('written_work', $iv->focus_component);
        $this->assertSame('approved', $iv->status);
        $this->assertNull($iv->risk_result_id);
        $this->assertSame('principal', $iv->origin);
        $this->assertTrue($concern->fresh()->isOpen(), 'recording an intervention does not resolve the concern');
        $this->assertNull($concern->fresh()->reviewed_at, 'nor does it mark the concern reviewed');
        $this->assertSame($iv->id, $concern->fresh()->interventions->sole()->id);
    }

    public function test_q_a_legacy_intervention_without_a_concern_stays_valid(): void
    {
        $this->actingAs($this->principal)->post(route('principal.interventions.store'), [
            'student_id' => $this->learners['ILAGAN']->id, 'subject_id' => $this->cesc->id,
            'grading_period' => 1, 'recommended_type' => 'parent_conference',
        ])->assertSessionHas('success');

        $this->assertNull(Intervention::sole()->academic_concern_id);
    }

    public function test_a_concern_link_that_does_not_describe_the_intervention_is_refused(): void
    {
        $galang = $this->concern('GALANG');

        // Another learner's concern.
        $this->recordFromConcern($galang, ['student_id' => $this->learners['ILAGAN']->id])
            ->assertSessionHas('error', 'That academic concern is not an open concern for this learner, subject and term, so the intervention was not recorded.');
        // A resolved concern.
        $this->recordFromConcern($this->concern('FERNANDEZ', 'resolved'))->assertSessionHas('error');
        // A concern that does not exist.
        $this->recordFromConcern($galang, ['academic_concern_id' => 999999])->assertSessionHas('error');

        $this->assertSame(0, Intervention::count());
    }

    public function test_r_an_intervention_survives_its_concern_reference_disappearing(): void
    {
        $concern = $this->concern('GALANG');
        $this->recordFromConcern($concern);
        $iv = Intervention::sole();

        // The application never deletes a concern; a raw delete proves the
        // foreign key keeps the intervention (ON DELETE SET NULL).
        DB::table('academic_concerns')->where('id', $concern->id)->delete();

        $this->assertNotNull($iv->fresh(), 'the intervention is kept');
        $this->assertNull($iv->fresh()->academic_concern_id);
    }

    public function test_s_t_the_record_intervention_form_is_prefilled_and_opening_it_creates_nothing(): void
    {
        $concern = $this->concern('GALANG');
        $html = $this->page()->assertOk()->getContent();
        $block = $this->block($html, $concern->id);

        $this->assertStringContainsString('data-record-intervention', $block);
        // @json escapes quotes as " (JSON_HEX_QUOT) inside the onclick attribute.
        $this->assertStringContainsString('"academic_concern_id":' . $concern->id, $block);
        $this->assertStringContainsString('"student_id":' . $concern->student_id, $block);
        $this->assertStringContainsString('"subject_id":' . $concern->subject_id, $block);
        $this->assertStringContainsString('"grading_period":1', $block);

        $this->assertStringContainsString('action="' . route('principal.interventions.store') . '"', $html);
        $this->assertMatchesRegularExpression('/<option value="" selected disabled>— Choose an intervention —<\/option>/', $html,
            'no intervention type is pre-selected');
        $this->assertStringContainsString('name="academic_concern_id"', $html);

        $this->assertSame(0, Intervention::count(), 'rendering the page (and the form) created nothing');
    }

    // --- U-V. Linked intervention visibility ---------------------------------------------------------

    public function test_u_the_linked_intervention_appears_on_the_concern(): void
    {
        $concern = $this->concern('GALANG');
        $this->recordFromConcern($concern);

        $block = $this->block($this->page()->getContent(), $concern->id);
        $this->assertMatchesRegularExpression('/data-linked-interventions>\s*<span class="block" data-linked-intervention="\d+">\s*Teacher Monitoring · Approved · recorded .+ by Santos, Maria/s', $block);
        $this->assertStringContainsString('Not yet acknowledged by the adviser', $block);
        $this->assertStringNotContainsString('No intervention recorded for this concern.', $block);

        $other = $this->block($this->page()->getContent(), $this->concern('ILAGAN')->id);
        $this->assertStringContainsString('No intervention recorded for this concern.', $other);
    }

    public function test_v_the_adviser_sees_the_linked_intervention_on_the_existing_interventions_page(): void
    {
        $this->recordFromConcern($this->concern('GALANG'));

        $this->actingAs($this->adviser)->get(route('adviser.interventions'))
            ->assertOk()
            ->assertSee('GALANG')
            ->assertDontSee('Mark as Reviewed')
            ->assertDontSee('data-review-action', false);
    }

    // --- W-X. Resolution still works, history stays -----------------------------------------------------

    public function test_w_x_a_reviewed_concern_with_an_intervention_still_resolves_and_keeps_its_history(): void
    {
        $concern = $this->concern('GALANG');
        $this->review($concern, ['review_note' => 'seen']);
        $this->recordFromConcern($concern);

        $this->statuses->recordScore($this->quiz1, $this->learners['GALANG']->id, 12);
        $this->sync();

        $resolved = $concern->fresh();
        $this->assertSame(AcademicConcern::STATUS_RESOLVED, $resolved->status);
        $this->assertSame('A score was recorded for "Quiz 1".', $resolved->resolution_reason);
        $this->assertSame($this->principal->id, $resolved->reviewed_by);
        $this->assertSame('seen', $resolved->review_note);
        $this->assertSame(Intervention::sole()->id, $resolved->interventions->sole()->id);

        $block = $this->block($this->page(['status' => 'resolved'])->getContent(), $resolved->id);
        $this->assertStringContainsString('Reviewed by Santos, Maria', $block);
        $this->assertStringContainsString('Teacher Monitoring · Approved', $block);
        $this->assertStringNotContainsString('data-concern-actions', $block, 'no actions on history');
    }

    // --- Presentation ------------------------------------------------------------------------------------

    public function test_a_reviewed_open_concern_reads_open_reviewed_never_resolved(): void
    {
        $concern = $this->concern('GALANG');
        $this->review($concern, ['review_note' => 'Checked with adviser']);

        $block = $this->block($this->page()->getContent(), $concern->id);
        $this->assertStringContainsString('Open · Reviewed', $block);
        $this->assertStringContainsString('still open until its evidence changes', $block);
        $this->assertStringContainsString('Note: Checked with adviser', $block);
        $this->assertStringNotContainsString('Resolved', strip_tags(substr($block, 0, strpos($block, '<details') ?: 0)));
        $this->assertStringNotContainsString('data-review-action', $block, 'already reviewed: no second review button');
        $this->assertStringContainsString('data-record-intervention', $block, 'an intervention can still be recorded');

        $unreviewed = $this->block($this->page()->getContent(), $this->concern('ILAGAN')->id);
        $this->assertStringContainsString('For Review', $unreviewed);
        $this->assertStringContainsString('data-review-action', $unreviewed);
        $this->assertStringContainsString('action="' . route('principal.academic-monitoring.review', $this->concern('ILAGAN')) . '"', $unreviewed);
    }

    // --- Intervention readiness ("Ready to Close") under rule-based monitoring -----------------------------

    public function test_readiness_is_never_granted_on_no_scored_evidence(): void
    {
        // A delivered, approved intervention for a learner with NO score in
        // the subject this term: absence of evidence is not On Track.
        $noEvidence = Student::factory()->create(['section_id' => $this->agila->id, 'last_name' => 'NOSCORE', 'lrn' => '120000000099']);
        $iv = Intervention::create([
            'student_id' => $noEvidence->id, 'subject_id' => $this->cesc->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'section_id' => $this->agila->id, 'recommended_type' => 'teacher_monitoring',
            'status' => 'approved', 'created_by' => $this->principal->id, 'origin' => 'principal',
            'decided_by' => $this->principal->id, 'decided_at' => now(),
            'acknowledged_by' => $this->adviser->id, 'acknowledged_at' => now(),
            'delivered_by' => $this->adviser->id, 'delivered_at' => now(), 'delivery_notes' => 'Done.',
        ]);

        $status = (new \App\Services\InTermStatusService())->statusFor($noEvidence, $this->cesc, $this->agila, 1, '2026-2027');
        $this->assertSame(0, $status['item_count']);
        $this->assertSame(\App\Services\InTermStatusService::NO_EVIDENCE, $status['status'], 'absence of evidence is never labelled On Track');
        $this->assertFalse((new \App\Services\InTermStatusService())->isReadyForReview($iv));
    }

    public function test_the_galang_flow_approved_acknowledged_delivered_stays_not_ready_while_the_concern_is_open(): void
    {
        $readiness = new \App\Services\InTermStatusService();
        $concern = $this->concern('GALANG');
        $this->recordFromConcern($concern)->assertSessionHas('success');
        $iv = Intervention::sole();
        $this->assertFalse($readiness->isReadyForReview($iv->fresh()), 'approved, not delivered');

        $this->actingAs($this->adviser)->post(route('adviser.interventions.acknowledge', $iv))->assertSessionHas('success');
        $this->assertFalse($readiness->isReadyForReview($iv->fresh()), 'acknowledged, not delivered');

        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $iv), [
            'delivery_notes' => 'Gave an additional written activity covering the Quiz 1 topics.',
        ])->assertSessionHas('success');

        $delivered = $iv->fresh();
        $this->assertNotNull($delivered->delivered_at);
        $this->assertSame('approved', $delivered->status);
        $this->assertFalse($readiness->isReadyForReview($delivered), 'delivered, but the linked concern is still open');
        $this->assertSame(0, $readiness->readyForReviewCount('2026-2027'));

        // Delivery never resolves a concern.
        $this->assertTrue($concern->fresh()->isOpen());
        $this->assertNull($concern->fresh()->resolved_at);

        // The Principal pages agree: no "ready to close" count or flag.
        $this->actingAs($this->principal)->get(route('principal.interventions', ['ready_for_review' => 1]))
            ->assertOk()->assertDontSee('GALANG, VINCENT');
    }

    public function test_an_open_linked_concern_blocks_readiness_even_when_other_evidence_is_on_track(): void
    {
        $readiness = new \App\Services\InTermStatusService();
        $concern = $this->concern('GALANG');
        $this->recordFromConcern($concern);
        $iv = Intervention::sole();
        $this->actingAs($this->adviser)->post(route('adviser.interventions.acknowledge', $iv));
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $iv), ['delivery_notes' => 'Additional activity given.']);

        // GALANG scores well on a DIFFERENT, larger item, so the subject now has
        // real On-Track evidence — but Quiz 1 is still Missing and #5 still
        // open. (Since D1, 2026-10-04, the Missing Quiz 1 keeps its HPS in the
        // denominator: 190 / (20 + 200) = 86.36%, still On Track.)
        $quiz2 = Assessment::factory()->create([
            'subject_id' => $this->cesc->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 2', 'assessment_type' => 'Quiz 2',
            'component' => 'written_work', 'max_score' => 200, 'uploaded_by' => $this->adviser->id,
        ]);
        $this->statuses->recordScore($quiz2, $this->learners['GALANG']->id, 190);
        $status = $readiness->statusFor($this->learners['GALANG'], $this->cesc, $this->agila, 1, '2026-2027');
        $this->assertSame(1, $status['item_count']);
        $this->assertSame('On Track', $status['status']);

        $this->assertTrue($concern->fresh()->isOpen());
        $this->assertFalse($readiness->isReadyForReview($iv->fresh()), 'only the open concern stands in the way here');
    }

    public function test_once_the_linked_concern_resolves_readiness_is_evaluated_normally(): void
    {
        $readiness = new \App\Services\InTermStatusService();
        $concern = $this->concern('GALANG');
        $this->recordFromConcern($concern);
        $iv = Intervention::sole();
        $this->actingAs($this->adviser)->post(route('adviser.interventions.acknowledge', $iv));
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $iv), ['delivery_notes' => 'Additional activity given.']);
        $this->assertFalse($readiness->isReadyForReview($iv->fresh()));

        // The evidence changes: a real score is recorded, monitoring resolves the concern.
        $this->statuses->recordScore($this->quiz1, $this->learners['GALANG']->id, 18);
        $this->sync();
        $this->assertSame(AcademicConcern::STATUS_RESOLVED, $concern->fresh()->status);

        // No longer blocked; now the normal rule applies to real evidence (18/20 Written Work = On Track).
        $this->assertTrue($readiness->isReadyForReview($iv->fresh()));
        $this->assertSame(1, $readiness->readyForReviewCount('2026-2027'));
    }

    public function test_academic_monitoring_shows_the_linked_interventions_delivery_state_without_resolving(): void
    {
        $concern = $this->concern('GALANG');
        $this->review($concern, ['review_note' => 'quiz']);
        $this->recordFromConcern($concern)->assertSessionHas('success');
        $iv = Intervention::sole();

        $linked = fn() => $this->block($this->page(['status' => 'all'])->assertOk()->getContent(), $concern->id);
        $this->assertStringContainsString('Not yet acknowledged by the adviser', $linked());

        $this->actingAs($this->adviser)->post(route('adviser.interventions.acknowledge', $iv))->assertSessionHas('success');
        $this->assertStringContainsString('Acknowledged by the adviser', $linked());
        $this->assertStringContainsString('not yet delivered', $linked());

        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $iv), [
            'delivery_notes' => 'Gave an additional written activity.',
        ])->assertSessionHas('success');
        $html = $linked();
        $this->assertStringContainsString('Delivered ', $html);
        $this->assertStringContainsString('Gave an additional written activity.', $html);
        $this->assertStringContainsString('Delivery does not close this concern', $html);
        $this->assertStringContainsString('Open · Reviewed', $html, 'delivery never makes the concern read Resolved');
        $this->assertTrue($concern->fresh()->isOpen());

        // The evidence changes and monitoring resolves it: the delivery stays as history,
        // and the "does not close" note is no longer relevant.
        $this->statuses->recordScore($this->quiz1, $this->learners['GALANG']->id, 16);
        $this->sync();
        $html = $linked();
        $this->assertStringContainsString('Gave an additional written activity.', $html);
        $this->assertStringNotContainsString('Delivery does not close this concern', $html);
    }

    // --- Before the Phase 2E migrations are applied (the live database until approval) ---------------------

    private function forgetSchemaMemo(): void
    {
        $memo = new \ReflectionProperty(AcademicConcern::class, 'columnMemo');
        $memo->setValue(null, []);
    }

    public function test_without_the_phase_2e_columns_the_page_works_and_nothing_errors(): void
    {
        $noteMigration = require database_path('migrations/2026_09_28_000001_add_review_note_to_academic_concerns_table.php');
        $linkMigration = require database_path('migrations/2026_09_28_000002_add_academic_concern_id_to_interventions_table.php');
        $linkMigration->down();
        $noteMigration->down();
        $this->forgetSchemaMemo();

        try {
            $concern = $this->concern('GALANG');
            $html = $this->page()->assertOk()->getContent();
            $this->assertStringNotContainsString('data-record-intervention', $html, 'no link to offer yet');
            $this->assertStringNotContainsString('name="review_note"', $html, 'no note field yet');
            $this->assertStringContainsString('data-review-action', $html, 'reviewing still works');

            $this->review($concern, ['review_note' => 'x'])->assertSessionHas('error');
            $this->assertNull($concern->fresh()->reviewed_at);
            $this->review($concern)->assertSessionHas('success');
            $this->assertNotNull($concern->fresh()->reviewed_at);

            $this->recordFromConcern($concern)
                ->assertSessionHas('error', 'Linking an intervention to an academic concern is not available yet on this database. Ask the administrator to apply the pending update.');
            $this->assertSame(0, Intervention::count());
        } finally {
            $noteMigration->up();
            $linkMigration->up();
            $this->forgetSchemaMemo();
        }
    }

    // --- Z. GET pages never change review or intervention state ---------------------------------------------

    public function test_z_viewing_writes_no_review_or_intervention(): void
    {
        $snapshot = fn() => [
            DB::table('academic_concerns')->orderBy('id')->get()->map(fn($r) => (array) $r)->all(),
            DB::table('interventions')->count(),
            DB::table('activity_logs')->where('action', 'like', '%review%')->count(),
        ];
        $before = $snapshot();

        foreach ([[], ['status' => 'all'], ['status' => 'resolved']] as $q) {
            $this->page($q)->assertOk();
        }
        $this->actingAs($this->principal)->get(route('principal.dashboard'))->assertOk();
        $this->actingAs($this->principal)->get(route('principal.interventions'))->assertOk();

        $this->assertSame($before, $snapshot());
    }
}
