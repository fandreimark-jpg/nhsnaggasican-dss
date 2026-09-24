<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\ProgressMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLAUDE.md's worked example: Before intervention PT=60%, after PT=78%,
 * change=+18 percentage points. Verified exactly, plus the "not enough
 * evidence yet" and "no later term possible" cases — this service must
 * never fabricate a comparison it doesn't have real data for.
 */
class ProgressMonitoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgressMonitoringService $service;
    private Section $section;
    private Subject $subject;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service  = new ProgressMonitoringService();
        $this->section  = Section::factory()->create(['school_year' => '2026-2027']);
        $this->subject  = Subject::factory()->create();
        $this->student  = Student::factory()->create(['section_id' => $this->section->id]);
    }

    private function score(int $term, string $component, float $earned, float $max = 100): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
            'grading_period' => $term, 'school_year' => '2026-2027',
            'name' => $component . '-t' . $term, 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $this->student->id, 'score' => $earned]);
    }

    private function makeIntervention(int $riskTerm): Intervention
    {
        $riskResult = RiskResult::create([
            'student_id' => $this->student->id, 'grading_period' => $riskTerm, 'average_grade' => 70,
            'risk_level' => 'moderate', 'school_year' => '2026-2027',
            'weakest_subject' => $this->subject->name, 'weakest_subject_id' => $this->subject->id,
            'weakest_subject_grade' => 70, 'generated_at' => now(),
        ]);

        return Intervention::factory()->create([
            'student_id' => $this->student->id, 'subject_id' => $this->subject->id,
            'risk_result_id' => $riskResult->id,
        ]);
    }

    public function test_the_claude_md_worked_example_pt_60_to_78_is_plus_18(): void
    {
        // Term 1: WW/Exam fine, PT is the weakest -> that's the baseline component.
        $this->score(1, 'written_work', 90);
        $this->score(1, 'performance_task', 60);
        $this->score(1, 'examination', 90);

        // Term 2: same component, improved.
        $this->score(2, 'written_work', 90);
        $this->score(2, 'performance_task', 78);
        $this->score(2, 'examination', 90);

        $intervention = $this->makeIntervention(riskTerm: 1);

        $result = $this->service->compare($intervention);

        $this->assertSame('performance_task', $result['component']);
        $this->assertEquals(60.0, $result['before_percentage']);
        $this->assertEquals(78.0, $result['after_percentage']);
        $this->assertEquals(18.0, $result['change']);
    }

    public function test_returns_before_only_when_the_next_terms_evidence_does_not_exist_yet(): void
    {
        $this->score(1, 'written_work', 90);
        $this->score(1, 'performance_task', 60);
        $this->score(1, 'examination', 90);
        // No Term 2 data at all yet.

        $intervention = $this->makeIntervention(riskTerm: 1);

        $result = $this->service->compare($intervention);

        $this->assertSame('awaiting_evidence', $result['status']);
        $this->assertEquals(60.0, $result['before_percentage']);
        $this->assertNull($result['after_percentage']);
        $this->assertNull($result['change']);
    }

    /**
     * "Progress column honesty" work order, PART 1d — before_term + 1
     * would be Term 4, which does not exist in a three-term year. Must
     * read a distinct 'no_later_term' status, never silently reference a
     * "Term 4" that isn't real.
     */
    public function test_returns_before_only_when_baseline_term_was_already_the_final_term(): void
    {
        $this->score(3, 'written_work', 90);
        $this->score(3, 'performance_task', 60);
        $this->score(3, 'examination', 90);

        $intervention = $this->makeIntervention(riskTerm: 3);

        $result = $this->service->compare($intervention);

        $this->assertSame('no_later_term', $result['status']);
        $this->assertNull($result['after_period']);
        $this->assertNull($result['change']);
    }

    public function test_returns_not_applicable_when_the_intervention_has_no_linked_subject(): void
    {
        $intervention = Intervention::factory()->create(['subject_id' => null, 'risk_result_id' => null]);

        $result = $this->service->compare($intervention);

        $this->assertSame('not_applicable', $result['status']);
        $this->assertNull($result['component']);
    }

    /**
     * "Progress column honesty" work order, PART 1d — a Principal-recorded
     * intervention (no risk_result_id) must render the specific
     * not-applicable status, never the generic catch-all. This is the
     * shape of all 29 real interventions on file today — see the STOP
     * POINT 1 count.
     */
    public function test_a_principal_recorded_intervention_with_no_risk_result_id_is_not_applicable(): void
    {
        $intervention = Intervention::factory()->create([
            'student_id' => $this->student->id, 'subject_id' => $this->subject->id,
            'risk_result_id' => null, 'origin' => 'principal',
        ]);

        $result = $this->service->compare($intervention);

        $this->assertSame('not_applicable', $result['status']);
        $this->assertNull($result['before_period']);
        $this->assertNull($result['after_period']);
    }

    public function test_a_full_comparison_is_status_available(): void
    {
        $this->score(1, 'written_work', 90);
        $this->score(1, 'performance_task', 60);
        $this->score(1, 'examination', 90);
        $this->score(2, 'written_work', 90);
        $this->score(2, 'performance_task', 78);
        $this->score(2, 'examination', 90);

        $intervention = $this->makeIntervention(riskTerm: 1);

        $result = $this->service->compare($intervention);

        $this->assertSame('available', $result['status']);
        $this->assertEquals(18.0, $result['change']);
    }

    /**
     * Final pre-deployment audit (2026-09-24). compare() used to re-derive
     * the weakest component from the baseline term's evidence AS IT IS NOW,
     * so additional-support items entered after delivery could move the
     * weakest elsewhere and the Principal's Term-over-Term Progress column
     * would report a component the intervention was never about — while the
     * same row's Type & Reason and Within-Term Progress cells still named
     * the original one. 56 of the 82 live Term 1 interventions disagreed
     * that way. The component is frozen on the row at creation and must be
     * read from there, the same value compareWithinTerm() has always used.
     *
     * Every other test in this file leaves focus_component null (the factory
     * does not set it), so they exercise the FALLBACK path only — which is
     * exactly why none of them caught this.
     */
    public function test_compare_reports_the_frozen_focus_component_not_todays_weakest(): void
    {
        // Baseline term: Written Work is, today, the weakest component.
        $this->score(1, 'written_work', 50);
        $this->score(1, 'performance_task', 60);
        $this->score(1, 'examination', 90);
        $this->score(2, 'written_work', 55);
        $this->score(2, 'performance_task', 78);
        $this->score(2, 'examination', 90);

        // But the Principal acted on Performance Task — that is what the
        // row records and what the column must report.
        $intervention = $this->makeIntervention(riskTerm: 1);
        $intervention->update(['focus_component' => 'performance_task']);

        $result = $this->service->compare($intervention->fresh());

        $this->assertSame('performance_task', $result['component']);
        $this->assertEquals(60.0, $result['before_percentage']);
        $this->assertEquals(78.0, $result['after_percentage']);
        $this->assertEquals(18.0, $result['change']);
    }

    /**
     * The fallback is deliberately kept: a row created before
     * focus_component existed still gets a comparison rather than being
     * dropped to 'unavailable'.
     */
    public function test_compare_falls_back_to_the_weakest_component_when_none_was_frozen(): void
    {
        $this->score(1, 'written_work', 90);
        $this->score(1, 'performance_task', 60);
        $this->score(1, 'examination', 90);
        $this->score(2, 'performance_task', 78);

        $intervention = $this->makeIntervention(riskTerm: 1);
        $this->assertNull($intervention->focus_component);

        $result = $this->service->compare($intervention);

        $this->assertSame('performance_task', $result['component']);
        $this->assertEquals(18.0, $result['change']);
    }

    /**
     * A frozen component can name one this subject has no evidence for at
     * all — the re-derived weakest could never do that, so this guard only
     * became reachable with the fix above. It must read 'unavailable'
     * rather than throw on a missing array key.
     */
    public function test_a_frozen_component_with_no_baseline_evidence_is_unavailable(): void
    {
        $this->score(1, 'written_work', 90);
        $this->score(1, 'performance_task', 60);
        // No examination evidence in the baseline term at all.

        $intervention = $this->makeIntervention(riskTerm: 1);
        $intervention->update(['focus_component' => 'examination']);

        $result = $this->service->compare($intervention->fresh());

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['component']);
    }
}
