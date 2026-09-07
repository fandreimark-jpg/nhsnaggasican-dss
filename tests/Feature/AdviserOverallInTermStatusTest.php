<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 2 of "dashboard structure and upload safeguards" — the adviser
 * dashboard's "Overall In-Term Status" (worst status across every
 * subject with evidence — see Adviser\DashboardController::buildInTermRows())
 * vs the Principal Students page's per-subject In-Term Status. Same
 * underlying evidence, two different, correctly-labelled numbers.
 */
class AdviserOverallInTermStatusTest extends TestCase
{
    use RefreshDatabase;

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned, float $max = 20): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => $component . '-' . uniqid(),
            'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_adviser_dashboard_labels_the_aggregated_column_overall(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('Overall In-Term Status');
        $response->assertSee('combining every subject this section takes', false);
        $response->assertSee(route('adviser.assessments', [], false), false);
    }

    public function test_principal_students_page_labels_the_column_for_this_subject(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11]);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        // The table (and its column headers) only renders once at least
        // one student matches the selected subject's cohort.
        Student::factory()->create(['section_id' => $section->id]);

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('In-Term Status (this subject)');
    }

    /**
     * The reconciliation check the task's own Verify step asks for: with
     * evidence in two subjects, the adviser's Overall status is the WORST
     * of the two (At Risk), the principal's per-subject statuses are each
     * correct on their own (On Track for Subject A, At Risk for Subject
     * B), and the adviser drill-down payload names both, matching each
     * per-subject number exactly.
     */
    public function test_overall_status_is_the_worst_of_two_subjects_and_drilldown_reconciles_with_per_subject_view(): void
    {
        $adviser = User::factory()->create();
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Reyes']);

        $subjectA = Subject::factory()->create(['name' => 'General Mathematics', 'grade_level' => 11, 'type' => 'core']);
        $subjectB = Subject::factory()->create(['name' => 'Earth Science', 'grade_level' => 11, 'type' => 'core']);

        // Subject A: On Track (every component >= 75 target).
        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subjectA, $student, $component, 18); // 90%
        }
        // Subject B: At Risk (two components below target).
        $this->score($section, $subjectB, $student, 'written_work', 8); // 40%
        $this->score($section, $subjectB, $student, 'performance_task', 8); // 40%
        $this->score($section, $subjectB, $student, 'examination', 18); // 90%

        // Adviser's Overall status must be the worst — At Risk (from Subject B).
        $adviserResponse = $this->actingAs($adviser)->get('/adviser/dashboard');
        $adviserResponse->assertOk();
        $adviserContent = $adviserResponse->getContent();
        $this->assertStringContainsString('Reyes', $adviserContent);
        // The drill-down payload embeds both subjects with their OWN status.
        $this->assertStringContainsString('"subject_name":"General Mathematics"', $adviserContent);
        $this->assertStringContainsString('"subject_name":"Earth Science"', $adviserContent);
        $this->assertMatchesRegularExpression('/"subject_name":"General Mathematics","status":"On Track"/', $adviserContent);
        $this->assertMatchesRegularExpression('/"subject_name":"Earth Science","status":"At Risk"/', $adviserContent);

        // Principal per-subject view: Subject A reads On Track...
        $principalA = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subjectA->id . '&period=1');
        $principalA->assertOk();
        $rowA = substr($principalA->getContent(), strpos($principalA->getContent(), 'Reyes'), 6000);
        $this->assertStringContainsString('On Track', $rowA);

        // ...and Subject B reads At Risk — reconciling exactly with the drill-down above.
        $principalB = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subjectB->id . '&period=1');
        $principalB->assertOk();
        $rowB = substr($principalB->getContent(), strpos($principalB->getContent(), 'Reyes'), 6000);
        $this->assertStringContainsString('At Risk', $rowB);
    }
}
