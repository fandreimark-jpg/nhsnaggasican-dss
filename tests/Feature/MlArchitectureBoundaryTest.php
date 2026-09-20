<?php

namespace Tests\Feature;

use App\Http\Controllers\Adviser\ReportController;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The boundary between the ML layer and the rule-based academic layer, and
 * the boundary between Laravel and Python.
 *
 * The pipeline is:
 *
 *     academic data -> Random Forest -> ML PREDICTION
 *         -> rule-based academic checks -> FINAL DSS RESULT
 *         -> Principal review
 *
 * Both halves of that middle step are recorded separately and permanently
 * (`ml_risk_level`, `risk_level`, `was_overridden`), and this file exists
 * so a future change cannot quietly collapse them into one figure. Once
 * the two are merged, "what did the model actually say" is unrecoverable,
 * and the DSS can no longer distinguish a model's judgement from a rule's.
 */
class MlArchitectureBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function controller(): ReportController
    {
        return new ReportController();
    }

    // ---------------------------------------------------------------
    // The rule layer is separate from, and after, the ML prediction
    // ---------------------------------------------------------------

    public function test_the_rule_layer_never_alters_what_the_model_predicted(): void
    {
        $controller = $this->controller();

        // Same ML prediction, different rule input, different final result
        // — and the ML prediction itself is untouched in both cases.
        $this->assertSame('low', $controller->applyFailingSubjectOverride('low', 0));
        $this->assertSame('moderate', $controller->applyFailingSubjectOverride('low', 1));
        $this->assertSame('high', $controller->applyFailingSubjectOverride('low', 2));
    }

    public function test_the_rule_layer_only_ever_escalates_never_softens(): void
    {
        $controller = $this->controller();

        // A model that already said 'high' is not talked down by a rule
        // that would only have required 'moderate'. The rule is a floor on
        // severity, not a second opinion that can overrule a worse one.
        $this->assertSame('high', $controller->applyFailingSubjectOverride('high', 0));
        $this->assertSame('high', $controller->applyFailingSubjectOverride('high', 1));
        $this->assertSame('moderate', $controller->applyFailingSubjectOverride('moderate', 0));
    }

    public function test_no_rule_threshold_leaks_into_the_python_payload(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $row = $this->controller()->buildPythonPayload(
            [['student_id' => $student->id, 'average_grade' => 80.0, 'failing_count' => 0]],
            $section,
            1
        )[0];

        // The payload carries computed academic OUTCOMES (means, counts,
        // deltas). It must never carry a grading weight, a passing
        // threshold, a transmutation band or a risk boundary — those are
        // academic-rule concepts owned by GradingEngine and the rule layer,
        // and feeding them to a model would let a retrain start optimising
        // against school policy rather than against learner outcomes.
        foreach (['ww_weight', 'pt_weight', 'ex_weight', 'subject_group', 'passing_mark',
                  'target', 'transmutation', 'risk_level', 'ml_risk_level'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, "'{$forbidden}' must not be sent to the classifier");
        }
    }

    // ---------------------------------------------------------------
    // The Laravel <-> Python contract, exercised for real
    // ---------------------------------------------------------------

    public function test_the_real_classifier_accepts_the_real_payload_and_returns_a_valid_result(): void
    {
        $python = config('services.python_path');
        if (!$this->interpreterIsAvailable($python)) {
            $this->markTestSkipped("Python interpreter '{$python}' is not runnable in this environment.");
        }

        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $strong = Student::factory()->create(['section_id' => $section->id]);
        $weak = Student::factory()->create(['section_id' => $section->id]);

        $assessment = Assessment::factory()->create([
            'section_id' => $section->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $strong->id, 'score' => 92]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $weak->id, 'score' => 58]);

        $gradesData = [
            ['student_id' => $strong->id, 'average_grade' => 92.0, 'failing_count' => 0],
            ['student_id' => $weak->id, 'average_grade' => 58.0, 'failing_count' => 2],
        ];

        $payload = $this->controller()->buildPythonPayload($gradesData, $section, 1);
        $results = $this->runClassifier($python, $payload);

        $this->assertTrue(
            $this->controller()->classifierOutputIsValid($results, array_column($gradesData, 'student_id')),
            'the real classifier output did not pass the validation Laravel applies before persisting it'
        );

        $byStudent = collect($results)->keyBy('student_id');
        $this->assertSame('low', $byStudent[$strong->id]['risk_level']);
        $this->assertSame('high', $byStudent[$weak->id]['risk_level']);

        // Every result must be traceable to the model that produced it.
        foreach ($results as $result) {
            $this->assertArrayHasKey('model_version', $result);
            $this->assertArrayHasKey('feature_schema_version', $result);
        }
    }

    public function test_the_deployed_classifier_still_reports_itself_as_the_synthetic_prototype(): void
    {
        $python = config('services.python_path');
        if (!$this->interpreterIsAvailable($python)) {
            $this->markTestSkipped("Python interpreter '{$python}' is not runnable in this environment.");
        }

        $results = $this->runClassifier($python, [[
            'student_id' => 1, 'average_grade' => 88.0, 'current_average' => 88.0,
        ]]);

        // If this ever changes, a real model has been promoted — which is
        // the intended end state, but it means every claim in
        // analytics/README.md about the active model being synthetic needs
        // rewriting, and this assertion is where that gets noticed.
        $this->assertSame('legacy_synthetic_prototype', $results[0]['model_version']);
        $this->assertSame('synthetic', $results[0]['dataset_type']);
    }

    public function test_a_classifier_error_object_is_rejected_rather_than_persisted(): void
    {
        // classify.py writes {"error": {...}} rather than a traceback when
        // it cannot serve a prediction. Laravel must treat that as a failed
        // analysis, never as a result set.
        $errorPayload = ['error' => ['code' => 'no_model_available', 'message' => 'no model']];

        $this->assertFalse($this->controller()->classifierOutputIsValid($errorPayload, [1, 2]));
    }

    private function interpreterIsAvailable(string $python): bool
    {
        $process = new Process([$python, '--version']);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    /** Runs the real analytics/classify.py exactly the way runAnalytics() does. */
    private function runClassifier(string $python, array $payload): array
    {
        $in = tempnam(sys_get_temp_dir(), 'dss_in_');
        $out = tempnam(sys_get_temp_dir(), 'dss_out_');

        try {
            file_put_contents($in, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = new Process([$python, base_path('analytics/classify.py'), $in, $out]);
            $process->setTimeout(120);
            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                'classify.py exited non-zero: ' . $process->getErrorOutput()
            );

            return json_decode(file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
