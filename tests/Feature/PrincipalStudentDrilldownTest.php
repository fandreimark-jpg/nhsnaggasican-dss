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
 * The drill-down CLAUDE.md describes: Dashboard -> Student -> Subject ->
 * Component -> Evidence. Verifies the destination page itself renders
 * the full chain, and that the dashboard/interventions list rows link
 * to it (for Principal only — Admin has no such route).
 */
class PrincipalStudentDrilldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_detail_page_shows_component_breakdown_and_evidence(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core', 'name' => 'Statistics']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Reyes', 'first_name' => 'Ana']);

        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 15]);

        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 78,
            'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->get('/principal/students/' . $student->id);

        $response->assertOk();
        $response->assertSee('Reyes, Ana');
        $response->assertSee('Statistics');
        $response->assertSee('Quiz 1');
        $response->assertSee('15.00 / 20.00');
        $response->assertSee('Moderate');
    }

    public function test_student_detail_shows_the_4_bucket_dss_status(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'school_year' => $section->school_year, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->get('/principal/students/' . $student->id);

        $response->assertOk();
        $response->assertSee('DSS Status');
        $response->assertSee('At Risk');
    }

    public function test_student_detail_shows_intervention_history(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        Intervention::factory()->create(['student_id' => $student->id, 'status' => 'approved', 'recommended_type' => 'remediation']);

        $response = $this->actingAs($principal)->get('/principal/students/' . $student->id);

        $response->assertOk();
        $response->assertSee('Remediation');
        $response->assertSee('Approved');
    }

    public function test_dashboard_at_risk_list_links_to_student_detail_for_principal(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'school_year' => $section->school_year, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee(route('principal.students.show', $student->id), false);
    }

    public function test_admin_dashboard_at_risk_list_does_not_link_to_principal_student_route(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'school_year' => $section->school_year, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertDontSee('/principal/students/');
    }

    public function test_adviser_cannot_access_the_principal_student_detail_route(): void
    {
        $adviser = User::factory()->create();
        $student = Student::factory()->create();

        $this->actingAs($adviser)->get('/principal/students/' . $student->id)->assertForbidden();
    }
}
