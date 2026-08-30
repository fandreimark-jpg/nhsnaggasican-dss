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
}
