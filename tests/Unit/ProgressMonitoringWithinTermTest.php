<?php

namespace Tests\Unit;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\ProgressMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Task 3 of "close the delivery loop" — the point of the whole feature:
 * showing a component moving WITHIN the term an intervention was
 * delivered in, not just term-over-term. See
 * ProgressMonitoringService::compareWithinTerm().
 */
class ProgressMonitoringWithinTermTest extends TestCase
{
    use RefreshDatabase;

    private ProgressMonitoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProgressMonitoringService();
    }

    /** Creates a scored item with an explicit created_at, bypassing the model's auto-timestamp. */
    private function scoreAt(Assessment $assessment, Student $student, float $score, Carbon $createdAt): AssessmentScore
    {
        $row = AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $score]);
        $row->timestamps = false;
        $row->created_at = $createdAt;
        $row->save();
        return $row;
    }

    private function makeAssessment(Section $section, Subject $subject, string $component, int $term = 1): Assessment
    {
        return Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => $term, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 20,
        ]);
    }

    /**
     * TASK 2 of "status clarity and progress consistency" — compareWithinTerm()
     * now anchors to $focusComponent (the intervention's recorded reason),
     * never a recomputed "weakest now." Defaults to 'performance_task' so
     * every pre-existing test in this file (all built around performance_task
     * evidence) keeps working unchanged; tests for the null/mismatch cases
     * override it explicitly.
     */
    private function makeIntervention(Student $student, Subject $subject, ?Carbon $deliveredAt, int $gradingPeriod = 1, ?string $focusComponent = 'performance_task'): Intervention
    {
        return Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => $gradingPeriod,
            'delivered_at' => $deliveredAt, 'focus_component' => $focusComponent,
        ]);
    }

    public function test_returns_null_when_not_delivered(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = $this->makeIntervention($student, $subject, null);

        $this->assertNull($this->service->compareWithinTerm($intervention));
    }

    /**
     * Superseded by TASK 2 of "status clarity and progress consistency":
     * "no evidence at all" is no longer a null-producing case on its
     * own — a known focus_component with zero evidence yet is a
     * legitimate, displayable state (before = "—", 0 items). See
     * test_a_known_focus_component_with_zero_evidence_is_not_null below
     * for that case, and test_returns_a_not_recorded_result_when_focus_component_is_unknown
     * for the one case that's still non-comparable: no focus_component
     * to anchor to at all.
     */
    public function test_a_known_focus_component_with_zero_evidence_is_not_null(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = $this->makeIntervention($student, $subject, now());

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertNotNull($result);
        $this->assertFalse($result['not_recorded']);
        $this->assertSame('performance_task', $result['component']);
        $this->assertNull($result['before_percentage']);
        $this->assertSame(0, $result['before_item_count']);
    }

    /**
     * TASK 2 of "status clarity and progress consistency" — the bug this
     * task fixes: the comparison must anchor to the intervention's
     * RECORDED reason, never to whichever component looks weakest right
     * now. focus_component is empty here because there's no reason text
     * to recover one from — the intervention factory's default
     * recommendation_reason names no component.
     */
    public function test_returns_a_not_recorded_result_when_focus_component_is_unknown(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = $this->makeIntervention($student, $subject, now(), 1, null);

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertNotNull($result);
        $this->assertTrue($result['not_recorded']);
        $this->assertNull($result['component']);
        $this->assertNull($result['change']);
    }

    public function test_anchors_to_the_recorded_focus_component_and_reports_before_and_after(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        // Written Work: strong, before delivery.
        $ww = $this->makeAssessment($section, $subject, 'written_work');
        $this->scoreAt($ww, $student, 18, $deliveredAt->copy()->subDay());

        // Performance Task: weak before delivery — this intervention's
        // recorded reason names THIS component (see makeIntervention()'s
        // default focus_component).
        $pt1 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt1, $student, 8, $deliveredAt->copy()->subDay()); // 40%

        $intervention = $this->makeIntervention($student, $subject, $deliveredAt);

        // Two NEW performance_task scores after delivery, pulling the average up.
        $pt2 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt2, $student, 18, $deliveredAt->copy()->addDay());
        $pt3 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt3, $student, 19, $deliveredAt->copy()->addDays(2));

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertNotNull($result);
        $this->assertSame('performance_task', $result['component']);
        $this->assertSame(40.0, $result['before_percentage']); // 8/20
        $this->assertSame(1, $result['before_item_count']);
        // after = (8+18+19)/(20+20+20) = 45/60 = 75%
        $this->assertSame(75.0, $result['after_percentage']);
        $this->assertSame(3, $result['after_item_count']);
        $this->assertSame(2, $result['new_item_count']);
        $this->assertTrue($result['has_enough_evidence']);
        $this->assertSame(35.0, $result['change']);

        // TASK 3 of "bulk dialog and intervention closure" — the raw
        // points a remedial score is ADDED to, not swapped into.
        $this->assertSame(8.0, $result['before_earned']);
        $this->assertSame(20.0, $result['before_max']);
        $this->assertSame(45.0, $result['after_earned']);
        $this->assertSame(60.0, $result['after_max']);
    }

    /**
     * TASK 2's regression test proper: focus_component names the
     * STRONGER component even though the OTHER component is weaker at
     * delivery. Before this task's fix, compareWithinTerm() would have
     * picked the weaker one regardless of what the reason said.
     */
    public function test_reports_on_the_recorded_component_even_when_a_different_one_is_weaker(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        // Written Work: strong (90%) — but this is what the intervention is about.
        $ww = $this->makeAssessment($section, $subject, 'written_work');
        $this->scoreAt($ww, $student, 18, $deliveredAt->copy()->subDay());

        // Performance Task: weak (40%) — NOT what the intervention is about.
        $pt = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt, $student, 8, $deliveredAt->copy()->subDay());

        $intervention = $this->makeIntervention($student, $subject, $deliveredAt, 1, 'written_work');

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertNotNull($result);
        $this->assertSame('written_work', $result['component']);
        $this->assertSame(90.0, $result['before_percentage']);
    }

    /**
     * TASK 2's mismatch-note data: new evidence recorded in a component
     * OTHER than the focus is flagged, never blocked.
     */
    public function test_flags_other_components_that_received_new_evidence_since_delivery(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        $pt = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt, $student, 8, $deliveredAt->copy()->subDay());

        $intervention = $this->makeIntervention($student, $subject, $deliveredAt); // focus: performance_task

        // New evidence added to Written Work AFTER delivery — a different
        // component than the one this intervention is about.
        $ww = $this->makeAssessment($section, $subject, 'written_work');
        $this->scoreAt($ww, $student, 18, $deliveredAt->copy()->addDay());

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertSame(['written_work'], $result['other_components_with_new_evidence']);
    }

    public function test_no_mismatch_flag_when_only_the_focus_component_gets_new_evidence(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        $pt1 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt1, $student, 8, $deliveredAt->copy()->subDay());

        $intervention = $this->makeIntervention($student, $subject, $deliveredAt);

        $pt2 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt2, $student, 18, $deliveredAt->copy()->addDay());

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertSame([], $result['other_components_with_new_evidence']);
    }

    /**
     * The item-count boundary this task explicitly asks for a unit test
     * on: exactly 1 new item since delivery must NOT produce a delta.
     */
    public function test_exactly_one_new_item_since_delivery_is_not_enough_evidence(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        $pt1 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt1, $student, 8, $deliveredAt->copy()->subDay());

        $intervention = $this->makeIntervention($student, $subject, $deliveredAt);

        $pt2 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt2, $student, 18, $deliveredAt->copy()->addDay());

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['new_item_count']);
        $this->assertFalse($result['has_enough_evidence']);
        $this->assertNull($result['change'], 'A single new score must never produce a delta.');
        // The raw percentages are still reported even without a delta.
        $this->assertSame(40.0, $result['before_percentage']);
        $this->assertNotNull($result['after_percentage']);
    }

    /** The other side of the same boundary: exactly 2 new items IS enough. */
    public function test_exactly_two_new_items_since_delivery_is_enough_evidence(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        $pt1 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt1, $student, 8, $deliveredAt->copy()->subDay());

        $intervention = $this->makeIntervention($student, $subject, $deliveredAt);

        $pt2 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt2, $student, 18, $deliveredAt->copy()->addDay());
        $pt3 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt3, $student, 18, $deliveredAt->copy()->addDays(2));

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertSame(2, $result['new_item_count']);
        $this->assertTrue($result['has_enough_evidence']);
        $this->assertNotNull($result['change']);
    }

    public function test_a_score_recorded_exactly_at_delivered_at_counts_as_before(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        $pt1 = $this->makeAssessment($section, $subject, 'performance_task');
        $this->scoreAt($pt1, $student, 8, $deliveredAt->copy()); // exactly at delivered_at

        $intervention = $this->makeIntervention($student, $subject, $deliveredAt);

        $result = $this->service->compareWithinTerm($intervention);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['before_item_count'], '"on or before delivered_at" must include the exact instant.');
    }

    public function test_returns_null_when_grading_period_is_missing(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'grading_period' => null, 'delivered_at' => now(),
        ]);

        $this->assertNull($this->service->compareWithinTerm($intervention));
    }
}
