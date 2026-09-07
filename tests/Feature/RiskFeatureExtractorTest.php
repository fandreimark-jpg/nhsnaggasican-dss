<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\RiskFeatureExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskFeatureExtractorTest extends TestCase
{
    use RefreshDatabase;

    private RiskFeatureExtractor $extractor;
    private Section $section;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new RiskFeatureExtractor();
        $this->section = Section::factory()->create(['school_year' => '2026-2027']);
        $this->student = Student::factory()->create(['section_id' => $this->section->id]);
    }

    private function score(string $component, float $earned, float $max, int $term = 1, ?Subject $subject = null): void
    {
        $subject ??= Subject::factory()->create();
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $this->section->id,
            'grading_period' => $term, 'school_year' => '2026-2027',
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $this->student->id, 'score' => $earned]);
    }

    public function test_all_features_are_null_when_no_assessment_evidence_or_prior_term_exists(): void
    {
        $result = $this->extractor->extract($this->student, $this->section, 1, '2026-2027', 80.0);

        $this->assertNull($result['ww_mean']);
        $this->assertNull($result['pt_mean']);
        $this->assertNull($result['exam_mean']);
        $this->assertNull($result['weak_component_count']);
        $this->assertNull($result['prev_term_average']);
        $this->assertNull($result['trend_delta']);
    }

    public function test_component_means_aggregate_across_every_subject_the_student_has_evidence_for(): void
    {
        $mathSubject = Subject::factory()->create();
        $sciSubject  = Subject::factory()->create();

        $this->score('written_work', 18, 20, subject: $mathSubject); // 90%
        $this->score('written_work', 12, 20, subject: $sciSubject);  // 60% -> combined 30/40=75%

        $result = $this->extractor->extract($this->student, $this->section, 1, '2026-2027', 80.0);

        $this->assertEquals(75.0, $result['ww_mean']);
    }

    public function test_weak_component_count_only_counts_components_with_data(): void
    {
        $this->score('written_work', 50, 100); // below target (75)
        $this->score('performance_task', 90, 100); // above target
        // No examination evidence at all.

        $result = $this->extractor->extract($this->student, $this->section, 1, '2026-2027', 70.0);

        $this->assertSame(1, $result['weak_component_count']);
        $this->assertNull($result['exam_mean']);
    }

    public function test_previous_term_average_and_trend_delta_use_the_prior_risk_result(): void
    {
        RiskResult::create([
            'student_id' => $this->student->id, 'grading_period' => 1, 'average_grade' => 70,
            'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $result = $this->extractor->extract($this->student, $this->section, 2, '2026-2027', 82.0);

        $this->assertEquals(70.0, $result['prev_term_average']);
        $this->assertEquals(12.0, $result['trend_delta']);
    }

    public function test_term_1_never_has_a_previous_term(): void
    {
        $result = $this->extractor->extract($this->student, $this->section, 1, '2026-2027', 80.0);

        $this->assertNull($result['prev_term_average']);
        $this->assertNull($result['trend_delta']);
    }

    /**
     * componentMeans() used to run 3 identical Assessment::where('section_id',
     * ...)->pluck('id') queries PER STUDENT — the fix (a single grouped
     * query per section/period/year, cached on $this) must not blur
     * results together across students who share that same instance.
     * ReportController::buildPythonPayload() calls extract() in a loop
     * over every student in a section using ONE shared
     * RiskFeatureExtractor instance, exactly like this test does — the
     * real N+1 this was fixing, and the real risk of getting the fix
     * wrong.
     */
    public function test_multiple_students_in_the_same_section_get_independent_results_from_one_shared_instance(): void
    {
        $mathSubject = Subject::factory()->create();
        $sciSubject  = Subject::factory()->create();

        $studentA = Student::factory()->create(['section_id' => $this->section->id]);
        $studentB = Student::factory()->create(['section_id' => $this->section->id]);
        $studentC = Student::factory()->create(['section_id' => $this->section->id]); // no evidence at all

        $this->scoreFor($studentA, 'written_work', 18, 20, subject: $mathSubject);
        $this->scoreFor($studentA, 'written_work', 16, 20, subject: $sciSubject); // combined WW: 34/40 = 85%
        $this->scoreFor($studentA, 'performance_task', 10, 20, subject: $mathSubject); // 50%
        $this->scoreFor($studentA, 'examination', 15, 20, subject: $mathSubject); // 75%

        $this->scoreFor($studentB, 'written_work', 10, 20, subject: $mathSubject); // 50%
        $this->scoreFor($studentB, 'performance_task', 18, 20, subject: $mathSubject); // 90%
        $this->scoreFor($studentB, 'examination', 5, 20, subject: $mathSubject); // 25%

        RiskResult::create([
            'student_id' => $studentA->id, 'grading_period' => 1, 'average_grade' => 70,
            'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);
        RiskResult::create([
            'student_id' => $studentB->id, 'grading_period' => 1, 'average_grade' => 60,
            'risk_level' => 'high', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        // One shared instance across all three — same pattern as
        // ReportController::buildPythonPayload()'s array_map loop.
        $resultA = $this->extractor->extract($studentA, $this->section, 2, '2026-2027', 90.0);
        $resultB = $this->extractor->extract($studentB, $this->section, 2, '2026-2027', 55.0);
        $resultC = $this->extractor->extract($studentC, $this->section, 2, '2026-2027', 80.0);

        $this->assertEquals(85.0, $resultA['ww_mean']);
        $this->assertEquals(50.0, $resultA['pt_mean']);
        $this->assertEquals(75.0, $resultA['exam_mean']);
        $this->assertSame(1, $resultA['weak_component_count']); // only performance_task below 75
        $this->assertEquals(70.0, $resultA['prev_term_average']);
        $this->assertEquals(20.0, $resultA['trend_delta']);

        $this->assertEquals(50.0, $resultB['ww_mean']);
        $this->assertEquals(90.0, $resultB['pt_mean']);
        $this->assertEquals(25.0, $resultB['exam_mean']);
        $this->assertSame(2, $resultB['weak_component_count']); // written_work and examination
        $this->assertEquals(60.0, $resultB['prev_term_average']);
        $this->assertEquals(-5.0, $resultB['trend_delta']);

        $this->assertNull($resultC['ww_mean']);
        $this->assertNull($resultC['pt_mean']);
        $this->assertNull($resultC['exam_mean']);
        $this->assertNull($resultC['weak_component_count']);
        $this->assertNull($resultC['prev_term_average']); // no RiskResult on record for C
    }

    private function scoreFor(Student $student, string $component, float $earned, float $max, int $term = 2, ?Subject $subject = null): void
    {
        $subject ??= Subject::factory()->create();
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $this->section->id,
            'grading_period' => $term, 'school_year' => '2026-2027',
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }
}
