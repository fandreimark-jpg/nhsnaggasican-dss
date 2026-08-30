<?php

namespace Tests\Feature;

use App\Http\Controllers\Adviser\ReportController;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exact JSON payload sent to classify.py — verifies the expanded
 * feature set (P-12) is actually included, without needing a real
 * exec() call. classify.py itself still only reads student_id and
 * average_grade (see its TRAINING DATA LIMITATION comment); this only
 * confirms Laravel's side of the contract is correct and ready for a
 * future retrain.
 */
class RiskAnalyticsPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_includes_the_full_expanded_feature_set(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $assessment = Assessment::factory()->create([
            'section_id' => $section->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 60]);

        $gradesData = [[
            'student_id' => $student->id, 'average_grade' => 70.0, 'failing_count' => 1,
        ]];

        $payload = (new ReportController())->buildPythonPayload($gradesData, $section, 1);

        $row = $payload[0];
        $this->assertSame($student->id, $row['student_id']);
        $this->assertSame(70.0, $row['average_grade']);
        $this->assertSame(1, $row['failing_subject_count']);
        $this->assertEquals(60.0, $row['ww_mean']);
        $this->assertNull($row['pt_mean']);
        $this->assertNull($row['exam_mean']);
        $this->assertArrayHasKey('weak_component_count', $row);
        $this->assertArrayHasKey('prev_term_average', $row);
        $this->assertArrayHasKey('trend_delta', $row);
    }

    public function test_payload_gracefully_handles_a_missing_student(): void
    {
        $section = Section::factory()->create();

        $payload = (new ReportController())->buildPythonPayload(
            [['student_id' => 999999, 'average_grade' => 80.0, 'failing_count' => 0]],
            $section,
            1
        );

        $this->assertSame(80.0, $payload[0]['average_grade']);
        $this->assertArrayNotHasKey('ww_mean', $payload[0]);
    }
}
