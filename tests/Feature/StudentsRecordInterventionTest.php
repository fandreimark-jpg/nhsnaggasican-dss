<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 1 of "separate intervention discovery from tracking": the
 * Students page can now record an intervention per row, and — the whole
 * point — this must work identically whether or not a risk result exists
 * for that student/term yet.
 */
class StudentsRecordInterventionTest extends TestCase
{
    use RefreshDatabase;

    private function scoreEvidence(Section $section, Subject $subject, Student $student, int $term = 1): void
    {
        foreach (['written_work' => 84, 'performance_task' => 60, 'examination' => 70] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => $term, 'school_year' => $section->school_year,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }
    }

    public function test_students_page_offers_record_intervention_with_no_risk_result_and_no_pre_selected_type(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $student   = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Evidence']);
        $subject   = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $this->scoreEvidence($section, $subject, $student);
        $this->assertSame(0, RiskResult::count());

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        // has_risk_result:false must be in the row's JSON payload — the
        // modal uses this to decide whether to pre-select a type.
        $response->assertSee('"has_risk_result":false', false);
        $response->assertSee('The DSS recommendation becomes available once the adviser submits the term report', false);
    }

    public function test_students_page_pre_selects_the_dss_recommendation_when_a_risk_result_exists(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $student   = Student::factory()->create(['section_id' => $section->id]);
        $subject   = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $this->scoreEvidence($section, $subject, $student);

        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 71.33,
            'risk_level' => 'moderate', 'school_year' => '2026-2027',
            'weakest_subject' => $subject->name, 'weakest_subject_id' => $subject->id,
            'weakest_subject_grade' => 71.33, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('"has_risk_result":true', false);
        // Performance Task is the weakest component here (84/60/70 -> PT
        // at 60% is furthest below the 75 target) -> additional_performance_task.
        $response->assertSee('"type":"additional_performance_task"', false);
    }

    public function test_students_page_shows_existing_status_instead_of_a_second_create_action(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $student   = Student::factory()->create(['section_id' => $section->id]);
        $subject   = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $this->scoreEvidence($section, $subject, $student);

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'status' => 'in_progress', 'grading_period' => 1,
        ]);

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('In progress');
        // The JS function definition is always present on the page — what
        // must be absent is a ROW actually invoking it.
        $response->assertDontSee("onclick='openRecordInterventionModal(", false);
    }

    public function test_recording_from_the_students_page_shape_of_request_succeeds_with_null_risk_result(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $student   = Student::factory()->create(['section_id' => $section->id]);
        $subject   = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $this->scoreEvidence($section, $subject, $student);
        $this->assertSame(0, RiskResult::count());

        // Exactly the shape the Students page modal posts.
        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'             => $student->id,
            'subject_id'             => $subject->id,
            'grading_period'         => 1,
            'recommended_type'       => 'additional_performance_task',
            'recommendation_reason'  => '',
            'principal_notes'        => 'Discussed with adviser before the term report was even submitted.',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'risk_result_id'   => null,
            'principal_notes'  => 'Discussed with adviser before the term report was even submitted.',
        ]);
    }
}
