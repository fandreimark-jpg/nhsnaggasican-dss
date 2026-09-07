<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Grade;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Problem 1a of "live in-term risk + stale data guard": a risk level
 * with no surviving grades behind it must be visibly flagged, not
 * silently deleted, everywhere a risk level is displayed.
 */
class StaleRiskWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_risk_terms_is_empty_when_grades_and_risk_results_are_consistent(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        Grade::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1, 'grade' => 85, 'school_year' => '2026-2027']);
        RiskResult::create(['student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 85, 'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now()]);

        $this->assertSame([], AcademicTerm::staleRiskTerms('2026-2027'));
    }

    public function test_stale_risk_terms_detects_a_risk_result_with_no_surviving_grades(): void
    {
        $student = Student::factory()->create();
        RiskResult::create(['student_id' => $student->id, 'grading_period' => 2, 'average_grade' => 70, 'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now()]);

        $this->assertSame(0, Grade::where('school_year', '2026-2027')->where('grading_period', 2)->count());
        $this->assertSame([2], AcademicTerm::staleRiskTerms('2026-2027'));
    }

    public function test_stale_risk_terms_ignores_a_term_with_no_risk_results_at_all(): void
    {
        // No grades, no risk results — nothing to flag (this is just an
        // unused term, not a stale-data incident).
        $this->assertSame([], AcademicTerm::staleRiskTerms('2026-2027'));
    }

    public function test_adviser_dashboard_shows_the_stale_warning_when_detected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create(['student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 70, 'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now()]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('may be out of date');
        $response->assertSee('Term 1');
    }

    public function test_adviser_dashboard_does_not_show_the_warning_when_data_is_consistent(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        Student::factory()->create(['section_id' => $section->id]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertDontSee('may be out of date');
    }

    public function test_principal_dashboard_shows_the_stale_warning_when_detected(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create(['student_id' => $student->id, 'grading_period' => 3, 'average_grade' => 70, 'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now()]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('may be out of date');
        $response->assertSee('Term 3');
    }

    public function test_reports_page_shows_the_stale_warning_named_with_the_school_year(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2025-2026']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create(['student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 70, 'risk_level' => 'moderate', 'school_year' => '2025-2026', 'generated_at' => now()]);

        $response = $this->actingAs($principal)->get('/principal/reports');

        $response->assertOk();
        $response->assertSee('may be out of date');
        $response->assertSee('2025-2026');
    }

    public function test_principal_students_page_shows_the_stale_warning_when_detected(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create(['student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 70, 'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now()]);

        $response = $this->actingAs($principal)->get('/principal/students');

        $response->assertOk();
        $response->assertSee('may be out of date');
    }
}
