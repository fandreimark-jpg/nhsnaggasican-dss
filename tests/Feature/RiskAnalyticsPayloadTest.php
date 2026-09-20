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
 * The exact JSON payload sent to classify.py, verified without needing a
 * real exec() call.
 *
 * This is Laravel's half of the contract that `analytics/schema.py` owns.
 * The nine canonical feature names are asserted literally here on purpose:
 * feature ORDER and feature NAMES are load-bearing across the language
 * boundary, and a silent rename on either side would feed the wrong column
 * into a fitted model. If this test and `analytics/schema.py` ever
 * disagree, one of them is wrong — they are not two independent lists.
 */
class RiskAnalyticsPayloadTest extends TestCase
{
    use RefreshDatabase;

    /** The nine features `analytics/schema.py`'s FEATURE_COLUMNS declares. */
    private const CANONICAL_FEATURES = [
        'ww_mean',
        'pt_mean',
        'exam_mean',
        'current_average',
        'prev_period_average',
        'trend_delta',
        'failing_subject_count',
        'weak_component_count',
        'missing_assessment_count',
    ];

    public function test_payload_includes_every_canonical_feature(): void
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

        $row = (new ReportController())->buildPythonPayload($gradesData, $section, 1)[0];

        foreach (self::CANONICAL_FEATURES as $feature) {
            $this->assertArrayHasKey($feature, $row, "canonical feature '{$feature}' is missing from the payload");
        }

        $this->assertSame($student->id, $row['student_id']);
        $this->assertSame(70.0, $row['current_average']);
        $this->assertSame(1, $row['failing_subject_count']);
        $this->assertEquals(60.0, $row['ww_mean']);
        $this->assertNull($row['pt_mean']);
        $this->assertNull($row['exam_mean']);
    }

    public function test_average_grade_is_kept_as_an_alias_of_current_average(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $row = (new ReportController())->buildPythonPayload(
            [['student_id' => $student->id, 'average_grade' => 83.5, 'failing_count' => 0]],
            $section,
            1
        )[0];

        // The deployed legacy prototype declares `average_grade` as its one
        // feature, and RiskResult.average_grade is where the figure lands.
        // Both spellings must be present and must agree.
        $this->assertSame(83.5, $row['average_grade']);
        $this->assertSame(83.5, $row['current_average']);
    }

    public function test_student_id_is_carried_but_is_not_one_of_the_features(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $row = (new ReportController())->buildPythonPayload(
            [['student_id' => $student->id, 'average_grade' => 80.0, 'failing_count' => 0]],
            $section,
            1
        )[0];

        $this->assertArrayHasKey('student_id', $row);
        $this->assertNotContains('student_id', self::CANONICAL_FEATURES);
    }

    /**
     * The distinction the whole missing-data policy rests on: an adviser
     * who entered a 0 has recorded a score, and a learner nobody entered
     * anything for has not. These must not produce the same number.
     */
    public function test_a_recorded_zero_is_not_counted_as_a_missing_assessment(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $scoredZero = Student::factory()->create(['section_id' => $section->id]);
        $notRecorded = Student::factory()->create(['section_id' => $section->id]);

        $assessment = Assessment::factory()->create([
            'section_id' => $section->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create([
            'assessment_id' => $assessment->id, 'student_id' => $scoredZero->id, 'score' => 0,
        ]);

        $payload = (new ReportController())->buildPythonPayload([
            ['student_id' => $scoredZero->id, 'average_grade' => 0.0, 'failing_count' => 1],
            ['student_id' => $notRecorded->id, 'average_grade' => 0.0, 'failing_count' => 1],
        ], $section, 1);

        $byStudent = collect($payload)->keyBy('student_id');

        $this->assertSame(0, $byStudent[$scoredZero->id]['missing_assessment_count'],
            'a recorded score of 0 is evidence, not a missing record');
        $this->assertSame(1, $byStudent[$notRecorded->id]['missing_assessment_count'],
            'a learner with no score row has one missing assessment');

        // And the component mean must tell the same story: a real zero is a
        // real 0.0%, an absent record is blank.
        $this->assertSame(0.0, $byStudent[$scoredZero->id]['ww_mean']);
        $this->assertNull($byStudent[$notRecorded->id]['ww_mean']);
    }

    public function test_missing_assessment_count_is_null_not_zero_when_nothing_is_expected(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $row = (new ReportController())->buildPythonPayload(
            [['student_id' => $student->id, 'average_grade' => 80.0, 'failing_count' => 0]],
            $section,
            1
        )[0];

        // No assessment items exist at all, so "how many are missing" has
        // no answer. Reporting 0 would claim complete evidence where there
        // is none.
        $this->assertNull($row['missing_assessment_count']);
    }

    public function test_a_first_period_learner_has_null_previous_average_never_zero(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $row = (new ReportController())->buildPythonPayload(
            [['student_id' => $student->id, 'average_grade' => 80.0, 'failing_count' => 0]],
            $section,
            1
        )[0];

        $this->assertNull($row['prev_period_average']);
        $this->assertNull($row['trend_delta']);
        $this->assertNotSame(0, $row['prev_period_average']);
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
