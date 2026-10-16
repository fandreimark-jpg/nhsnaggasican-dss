<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The DSS is rule-based ongoing academic monitoring. Normal operation needs
 * no Python, no scikit-learn, no model_cache.pkl and no risk prediction:
 * nothing launches a process, nothing writes risk_results, and no page
 * presents classifier output as decision support. The legacy risk_results
 * rows stay as protected, archived history only.
 */
class NoOperationalMlDependencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, string> relative path => contents */
    private function sources(array $dirs, string $suffix): iterable
    {
        foreach ($dirs as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (str_ends_with($file->getFilename(), $suffix)) {
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
                    yield $relative => file_get_contents($file->getPathname());
                }
            }
        }
    }

    public function test_no_application_code_launches_python_or_references_the_classifier(): void
    {
        $forbidden = ['classify.py', 'model_cache', 'RiskFeatureExtractor', 'InterventionRecommender', 'python_path',
            'Symfony\\Component\\Process', 'proc_open(', 'shell_exec(', 'runAnalytics', 'buildPythonPayload'];

        foreach ($this->sources(['app', 'routes', 'config', 'bootstrap'], '.php') as $path => $code) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$path} must not reference {$needle}");
            }
        }

        $this->assertFalse(class_exists(\App\Services\RiskFeatureExtractor::class));
        $this->assertFalse(class_exists(\App\Services\InterventionRecommender::class));
        $this->assertNull(config('services.python_path'));
    }

    public function test_only_history_protection_touches_risk_results_in_controllers_and_services(): void
    {
        // History protection only: a section holding archived rows cannot be
        // deleted, and a subject edit cannot orphan them.
        $allowed = [
            'app/Http/Controllers/Admin/SectionController.php',
            'app/Services/SubjectApplicabilityService.php',
        ];

        foreach ($this->sources(['app/Http', 'app/Services'], '.php') as $path => $code) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $code); // comments may explain the history
            if (in_array($path, $allowed, true)) {
                continue;
            }
            foreach (['RiskResult', 'risk_results', 'riskResults', "'riskResult'"] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$path} must not read classifier output ({$needle})");
            }
        }
    }

    public function test_no_view_presents_classifier_output(): void
    {
        foreach ($this->sources(['resources/views'], '.blade.php') as $path => $blade) {
            $visible = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
            foreach (['Low Risk', 'Moderate Risk', 'High Risk', 'Risk Level', 'Random Forest', 'classifier', 'decision trees', 'risk_level',
                      'Risk classification', 'Academic Risk'] as $needle) {
                $this->assertStringNotContainsString($needle, $visible, "{$path} must not show '{$needle}'");
            }
        }
    }

    public function test_the_whole_monitoring_cycle_runs_with_no_python_and_writes_no_risk_results(): void
    {
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        config(['services.python_path' => 'this-python-binary-does-not-exist-xyz']);

        $adviser = User::factory()->create(['role' => 'adviser']);
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027', 'adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core', 'subject_group' => 'core_academic']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $quiz = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $adviser->id,
        ]);

        (new AssessmentStatusService())->markMissing($quiz, $student->id, $adviser->id);
        app(AcademicMonitoringService::class)->synchronizeAssessments([$quiz->id]);
        $concern = \App\Models\AcademicConcern::sole();

        // The Principal records an intervention from the concern.
        $this->actingAs($principal)->post(route('principal.interventions.store'), [
            'academic_concern_id' => $concern->id, 'student_id' => $student->id, 'subject_id' => $subject->id,
            'grading_period' => 1, 'recommended_type' => 'additional_learning_activity',
        ])->assertSessionHas('success');

        $log = ActivityLog::where('action', 'create_intervention')->sole();
        $this->assertStringContainsString('from academic concern #' . $concern->id, $log->description);
        $this->assertStringNotContainsString('term report', $log->description);

        // A second intervention not tied to a concern says where it came from, factually.
        $other = Subject::factory()->create(['grade_level' => 12, 'type' => 'core', 'subject_group' => 'core_academic']);
        $this->actingAs($principal)->post(route('principal.interventions.store'), [
            'student_id' => $student->id, 'subject_id' => $other->id, 'grading_period' => 1, 'recommended_type' => 'teacher_monitoring',
        ])->assertSessionHas('success');
        $second = ActivityLog::where('action', 'create_intervention')->latest('id')->first();
        $this->assertStringContainsString('from current assessment evidence', $second->description);
        $this->assertStringNotContainsString('no submitted term report', $second->description);

        // Evidence changes; monitoring resolves the concern. Still no risk rows.
        (new AssessmentStatusService())->recordScore($quiz, $student->id, 16);
        app(AcademicMonitoringService::class)->synchronizeAssessments([$quiz->id]);
        $this->assertSame('resolved', $concern->fresh()->status);

        $this->assertSame(0, RiskResult::count(), 'nothing in the cycle writes classifier output');
        $this->assertNull(\App\Models\Intervention::first()->risk_result_id);
    }

    public function test_the_ml_prototype_and_its_model_artifact_are_gone_from_the_checkout(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertDirectoryDoesNotExist($root . '/analytics', 'the retired prototype lives in Git history only');
        $this->assertFileDoesNotExist($root . '/analytics/model_cache.pkl');
        $this->assertNull(config('services.python_path'), 'no Python interpreter is configured');

        $rules = array_map(fn($c) => class_basename($c), config('dss.monitoring_rules'));
        $this->assertSame(['MissingRequirementRule', 'OfficialFailingSubjectRule'], $rules,
            'only the two approved, non-predictive rules are enabled');
    }
}
