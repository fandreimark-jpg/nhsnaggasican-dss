<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Rule-based academic monitoring" Phase 2E — the Adviser delivery step for
 * an intervention recorded from an open academic concern.
 *
 * Delivery is a record of what was actually provided to the learner. It
 * never resolves the concern, never touches assessment evidence and never
 * makes the intervention "ready to close" while its concern is open. It is
 * recorded exactly once.
 */
class AdviserDeliveryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'Gave an additional written activity covering the Quiz 1 topics.';

    private User $principal;
    private User $adviser;
    private Section $agila;
    private Subject $cesc;
    private Assessment $quiz1;
    private Student $galang;
    private AcademicConcern $concern;
    private Intervention $iv;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->principal = User::factory()->principal()->create();
        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $track = Track::factory()->create();
        $humss = Specialization::factory()->create(['track_id' => $track->id, 'code' => 'HUMSS', 'curriculum' => 'k12_2013']);
        $this->agila = Section::factory()->create([
            'name' => 'Agila', 'grade_level' => 12, 'school_year' => '2026-2027',
            'track_id' => $track->id, 'specialization_id' => $humss->id, 'adviser_id' => $this->adviser->id,
        ]);
        $this->cesc = Subject::factory()->create([
            'name' => 'Community Engagement Solidarity and Citizenship', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic',
        ]);
        $this->galang = Student::factory()->create([
            'section_id' => $this->agila->id, 'last_name' => 'GALANG', 'first_name' => 'VINCENT', 'lrn' => '120000000001',
        ]);
        $this->quiz1 = Assessment::factory()->create([
            'subject_id' => $this->cesc->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1',
            'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);

        (new AssessmentStatusService())->markMissing($this->quiz1, $this->galang->id, $this->adviser->id);
        app(AcademicMonitoringService::class)->synchronizeAssessments([$this->quiz1->id]);
        $this->concern = AcademicConcern::where('student_id', $this->galang->id)->where('status', 'open')->sole();

        // The Principal reviews it and records an intervention from it (the live #5 / #165 shape).
        $this->actingAs($this->principal)->post(route('principal.academic-monitoring.review', $this->concern), ['review_note' => 'quiz']);
        $this->actingAs($this->principal)->post(route('principal.interventions.store'), [
            'academic_concern_id'   => $this->concern->id,
            'student_id'            => $this->galang->id,
            'subject_id'            => $this->cesc->id,
            'grading_period'        => 1,
            'recommended_type'      => 'additional_learning_activity',
            'recommendation_reason' => 'Missing Requirement: Quiz 1 (Written Work) was explicitly marked Missing by the adviser.',
            'principal_notes'       => 'Provide an additional learning activity to address the missing Quiz 1 requirement.',
        ])->assertSessionHas('success');
        $this->iv = Intervention::sole();
        $this->assertSame('approved', $this->iv->status);
        $this->assertSame($this->concern->id, $this->iv->academic_concern_id);
    }

    private function acknowledge(): void
    {
        $this->actingAs($this->adviser)->post(route('adviser.interventions.acknowledge', $this->iv))->assertSessionHas('success');
    }

    /** Exactly what intervention-delivery.js sends: form data + Accept: application/json. */
    private function deliverJson(?string $note = self::NOTE, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->adviser)
            ->postJson(route('adviser.interventions.deliver', $this->iv), ['delivery_notes' => $note]);
    }

    private function deliveryForm(string $html): string
    {
        $start = strpos($html, '<form method="POST" action="' . route('adviser.interventions.deliver', $this->iv) . '"');
        $this->assertNotFalse($start, 'the delivery form for this intervention is on the page');

        return substr($html, $start, strpos($html, '</form>', $start) - $start);
    }

    private function deliveryLogs()
    {
        return ActivityLog::where('action', 'deliver_intervention')->where('record_id', $this->iv->id)->get();
    }

    // 1, 12, 13 ---------------------------------------------------------------------

    public function test_the_adviser_sees_a_clear_delivery_panel_for_their_own_acknowledged_intervention(): void
    {
        $this->acknowledge();
        $html = $this->actingAs($this->adviser)->get(route('adviser.interventions', ['grading_period' => 1]))->assertOk()->getContent();
        $form = $this->deliveryForm($html);

        $this->assertStringContainsString('Opens a note field. Nothing is saved until you click Confirm Delivered.', $html);
        $this->assertStringContainsString('data-deliver-steps', $form);
        $this->assertStringContainsString('Click <strong>Confirm Delivered</strong> to save it.', $form);
        $this->assertStringContainsString(
            'Record what was actually provided to the learner. This does not resolve the academic concern. The concern remains open until its underlying assessment evidence changes.',
            $form
        );
        $this->assertStringContainsString('name="delivery_notes"', $form);
        $this->assertStringContainsString('data-deliver-error', $form);

        // Every chip and Cancel is a plain button — none of them can submit.
        preg_match_all('/<button\b[^>]*>/', $form, $buttons);
        $chipsAndCancel = array_filter($buttons[0], fn ($b) => str_contains($b, 'data-deliver-chip') || str_contains($b, 'data-deliver-cancel'));
        $this->assertGreaterThanOrEqual(2, count($chipsAndCancel));
        foreach ($chipsAndCancel as $b) {
            $this->assertStringContainsString('type="button"', $b);
        }

        // Confirm Delivered is the one and only submit control.
        $submits = array_values(array_filter($buttons[0], fn ($b) => str_contains($b, 'type="submit"')));
        $this->assertCount(1, $submits);
        $this->assertStringContainsString('data-deliver-submit', $submits[0]);
        $this->assertStringContainsString('data-loading-label="Saving delivery…"', $submits[0]);
        $this->assertMatchesRegularExpression('/data-deliver-submit[^>]*>\s*Confirm Delivered\s*<\/button>/', $form);
    }

    public function test_the_delivery_script_blocks_a_blank_note_disables_the_button_and_keeps_the_note_on_error(): void
    {
        $js = file_get_contents(resource_path('js/intervention-delivery.js'));

        $this->assertStringContainsString('textarea.value.trim() === ""', $js);
        $this->assertStringContainsString('Write what was actually provided to the learner before confirming delivery.', $js);
        $this->assertStringContainsString('submitBtn.disabled = true;', $js);
        $this->assertStringContainsString('"Saving delivery…"', $js);
        $this->assertStringContainsString('Marked as delivered.', file_get_contents(app_path('Http/Controllers/Adviser/InterventionController.php')));

        // The error path re-enables the button and never clears the typed note.
        $catch = substr($js, strpos($js, '.catch(function (err) {'));
        $catch = substr($catch, 0, strpos($catch, '});'));
        $this->assertStringContainsString('submitBtn.disabled = false;', $catch);
        $this->assertStringContainsString('showDeliverError(form', $catch);
        $this->assertStringNotContainsString('.value = ""', $catch);
    }

    // 2 -------------------------------------------------------------------------------

    public function test_delivery_is_refused_before_acknowledgement(): void
    {
        $this->deliverJson()->assertStatus(422)->assertJson(['message' => 'Acknowledge this intervention before marking it delivered.']);

        $iv = $this->iv->fresh();
        $this->assertNull($iv->delivered_at);
        $this->assertNull($iv->delivered_by);
        $this->assertNull($iv->delivery_notes);
        $this->assertCount(0, $this->deliveryLogs());
    }

    // 3 -------------------------------------------------------------------------------

    public function test_an_empty_or_blank_note_is_rejected_and_nothing_is_recorded(): void
    {
        $this->acknowledge();

        foreach (['', '   ', null] as $note) {
            $this->deliverJson($note)->assertStatus(422)->assertJsonValidationErrors('delivery_notes');
        }
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $this->iv), ['delivery_notes' => ' '])
            ->assertSessionHasErrors('delivery_notes');

        $this->assertNull($this->iv->fresh()->delivered_at);
        $this->assertCount(0, $this->deliveryLogs());
    }

    // 4-9, 14-16 ------------------------------------------------------------------------

    public function test_a_valid_delivery_records_who_when_and_what_and_changes_nothing_else(): void
    {
        $this->acknowledge();
        $concernBefore = $this->concern->fresh()->getAttributes();
        $scoresBefore = AssessmentScore::count();
        $markersBefore = AssessmentLearnerStatus::orderBy('id')->get()->map->getAttributes()->all();
        $assessmentBefore = $this->quiz1->fresh()->getAttributes();

        $this->deliverJson()->assertOk()->assertJson([
            'id' => $this->iv->id,
            'delivery_notes' => self::NOTE,
            'message' => 'Marked as delivered.',
        ]);

        $iv = $this->iv->fresh();
        $this->assertSame($this->adviser->id, $iv->delivered_by);
        $this->assertNotNull($iv->delivered_at);
        $this->assertSame(self::NOTE, $iv->delivery_notes);
        $this->assertSame('approved', $iv->status, 'status stays approved');
        $this->assertSame($this->concern->id, $iv->academic_concern_id, 'the concern link is preserved');

        // The linked concern is untouched: still open, still reviewed, nothing resolved.
        $concern = $this->concern->fresh();
        $this->assertSame($concernBefore, $concern->getAttributes());
        $this->assertTrue($concern->isOpen());
        $this->assertNull($concern->resolved_at);

        $this->assertCount(1, $this->deliveryLogs());

        // No evidence changes: no score created, the Missing marker kept, the item unchanged.
        $this->assertSame($scoresBefore, AssessmentScore::count());
        $this->assertSame($markersBefore, AssessmentLearnerStatus::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($assessmentBefore, $this->quiz1->fresh()->getAttributes());

        // Not "ready to close" while its concern is open, on every Principal surface.
        $readiness = new InTermStatusService();
        $this->assertFalse($readiness->isReadyForReview($iv));
        $this->assertSame(0, $readiness->readyForReviewCount('2026-2027'));
        $this->actingAs($this->principal)->get(route('principal.interventions', ['ready_for_review' => 1]))
            ->assertOk()->assertDontSee('GALANG, VINCENT');
    }

    public function test_the_page_shows_the_delivered_state_after_a_delivery(): void
    {
        $this->acknowledge();
        $this->deliverJson()->assertOk();

        $html = $this->actingAs($this->adviser)->get(route('adviser.interventions', ['grading_period' => 1]))->assertOk()->getContent();
        $this->assertStringNotContainsString('action="' . route('adviser.interventions.deliver', $this->iv) . '"', $html, 'no delivery form remains');
        $this->assertStringContainsString('"' . self::NOTE . '"', $html);
    }

    // 10 --------------------------------------------------------------------------------

    public function test_a_second_delivery_is_refused_and_the_first_record_is_kept(): void
    {
        $this->acknowledge();
        $this->deliverJson()->assertOk();
        $first = $this->iv->fresh()->only(['delivered_at', 'delivered_by', 'delivery_notes', 'updated_at']);

        $this->travel(5)->minutes();

        $this->deliverJson('A different note from a second tab.')->assertStatus(422)
            ->assertJson(['message' => 'This intervention was already marked as delivered. The existing delivery record is kept.']);
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $this->iv), ['delivery_notes' => 'Third try.'])
            ->assertSessionHas('error', 'This intervention was already marked as delivered. The existing delivery record is kept.');

        $this->assertEquals($first, $this->iv->fresh()->only(['delivered_at', 'delivered_by', 'delivery_notes', 'updated_at']));
        $this->assertCount(1, $this->deliveryLogs(), 'no duplicate delivery history');
    }

    // 8 — the normal web route: POST only, CSRF-protected, adviser-only; a plain form post works too.

    public function test_the_delivery_route_is_a_csrf_protected_adviser_post_and_a_plain_form_post_works(): void
    {
        $route = app('router')->getRoutes()->getByName('adviser.interventions.deliver');
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('adviser/interventions/{intervention}/deliver', $route->uri());

        $middleware = app('router')->gatherRouteMiddleware($route);
        $this->assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $middleware, 'the web group checks the CSRF token');
        $this->assertContains('auth', $route->gatherMiddleware());

        // The <form>'s own action is the route helper, never a hard-coded path.
        $this->assertStringContainsString(
            "action=\"{{ route('adviser.interventions.deliver', \$iv->id) }}\"",
            file_get_contents(resource_path('views/adviser/interventions.blade.php'))
        );

        $this->acknowledge();
        $this->actingAs($this->adviser)->get(route('adviser.interventions', ['grading_period' => 1]));
        $this->actingAs($this->adviser)
            ->post(route('adviser.interventions.deliver', $this->iv), ['delivery_notes' => self::NOTE])
            ->assertRedirect(route('adviser.interventions'))
            ->assertSessionHas('success', 'Marked as delivered.');

        $this->assertSame(self::NOTE, $this->iv->fresh()->delivery_notes);
        $this->assertCount(1, $this->deliveryLogs());
    }

    // 11 --------------------------------------------------------------------------------

    public function test_another_adviser_is_refused_with_403(): void
    {
        $this->acknowledge();
        $other = User::factory()->create(['role' => 'adviser']);
        Section::factory()->create(['name' => 'Maya', 'grade_level' => 12, 'school_year' => '2026-2027', 'adviser_id' => $other->id]);

        $this->deliverJson(self::NOTE, $other)->assertForbidden();
        $this->actingAs($other)->post(route('adviser.interventions.deliver', $this->iv), ['delivery_notes' => self::NOTE])->assertForbidden();

        $this->assertNull($this->iv->fresh()->delivered_at);
        $this->assertCount(0, $this->deliveryLogs());
    }
}
