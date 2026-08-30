<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-8: DSS integration — the existing risk_results-based "Students
 * Needing Attention" widget gains component-level evidence for WHY the
 * weakest subject is weak, reusing PerformanceAnalysisService rather
 * than a second DSS. This only ever ADDS detail when real assessment
 * evidence exists; it must never fabricate or crash when it doesn't.
 */
class DssComponentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_submission_records_the_weakest_subjects_id_not_just_its_name(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $weakSubject   = Subject::factory()->create(['grade_level' => $section->grade_level, 'name' => 'Math']);
        $strongSubject = Subject::factory()->create(['grade_level' => $section->grade_level, 'name' => 'English']);

        AcademicTerm::ensureExistFor('2026-2027');

        Grade::create(['student_id' => $student->id, 'subject_id' => $weakSubject->id, 'section_id' => $section->id, 'encoded_by' => $adviser->id, 'grading_period' => 1, 'grade' => 65, 'school_year' => '2026-2027']);
        Grade::create(['student_id' => $student->id, 'subject_id' => $strongSubject->id, 'section_id' => $section->id, 'encoded_by' => $adviser->id, 'grading_period' => 1, 'grade' => 90, 'school_year' => '2026-2027']);

        $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);

        $this->assertDatabaseHas('risk_results', [
            'student_id'         => $student->id,
            'weakest_subject'    => 'Math',
            'weakest_subject_id' => $weakSubject->id,
        ]);
    }

    public function test_at_risk_widget_shows_weakest_component_when_assessment_evidence_exists(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        foreach ([['written_work', 84], ['performance_task', 60], ['examination', 70]] as [$component, $earned]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 71.33,
            'risk_level' => 'moderate', 'school_year' => '2026-2027',
            'weakest_subject' => $subject->name, 'weakest_subject_id' => $subject->id,
            'weakest_subject_grade' => 71.33, 'generated_at' => now(),
        ]);

        $rows = (new DashboardAnalyticsService())->getAtRiskStudentsData()['atRiskStudents'];

        $this->assertCount(1, $rows);
        $this->assertSame('performance_task', $rows[0]['weakest_subject_component']['key']);
        $this->assertEquals(-15.0, $rows[0]['weakest_subject_component']['gap']);
        $this->assertSame('Needs Attention', $rows[0]['weakest_subject_component']['status']);
    }

    public function test_at_risk_widget_has_no_component_breakdown_when_no_assessment_evidence_exists(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'school_year' => $section->school_year,
            'weakest_subject' => $subject->name, 'weakest_subject_id' => $subject->id,
            'weakest_subject_grade' => 65, 'generated_at' => now(),
        ]);

        $rows = (new DashboardAnalyticsService())->getAtRiskStudentsData()['atRiskStudents'];

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['weakest_subject_component']);
    }

    public function test_at_risk_widget_does_not_crash_for_pre_existing_risk_results_with_no_weakest_subject_id(): void
    {
        // Simulates data from before this migration/feature existed.
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'school_year' => $section->school_year,
            'weakest_subject' => 'Old Subject Name', 'weakest_subject_id' => null,
            'weakest_subject_grade' => 65, 'generated_at' => now(),
        ]);

        $rows = (new DashboardAnalyticsService())->getAtRiskStudentsData()['atRiskStudents'];

        $this->assertCount(1, $rows);
        $this->assertSame('Old Subject Name', $rows[0]['weakest_subject']);
        $this->assertNull($rows[0]['weakest_subject_component']);
    }
}
