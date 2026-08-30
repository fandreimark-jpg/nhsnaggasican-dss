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

        $this->assertEquals(60.0, $result['before_percentage']);
        $this->assertNull($result['after_percentage']);
        $this->assertNull($result['change']);
    }

    public function test_returns_before_only_when_baseline_term_was_already_the_final_term(): void
    {
        $this->score(3, 'written_work', 90);
        $this->score(3, 'performance_task', 60);
        $this->score(3, 'examination', 90);

        $intervention = $this->makeIntervention(riskTerm: 3);

        $result = $this->service->compare($intervention);

        $this->assertNull($result['after_period']);
        $this->assertNull($result['change']);
    }

    public function test_returns_null_when_the_intervention_has_no_linked_subject(): void
    {
        $intervention = Intervention::factory()->create(['subject_id' => null, 'risk_result_id' => null]);

        $this->assertNull($this->service->compare($intervention));
    }
}
