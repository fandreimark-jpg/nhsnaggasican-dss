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
 * AcademicTerm::staleRiskTerms() — a legacy risk_results row with no
 * surviving grades behind it is still detected (dss:check-integrity reports
 * it). Since the rule-based cut-over no operational page shows a risk level,
 * so no page shows a stale-risk banner either: legacy classifier rows stay
 * archived and never surface as decision support.
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

    public function test_legacy_stale_risk_rows_never_surface_on_operational_pages(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create(['student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 70, 'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now()]);
        $this->assertSame([1], AcademicTerm::staleRiskTerms('2026-2027'), 'the legacy row is stale (no grades behind it)');

        $principal = User::factory()->principal()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([[$adviser, '/adviser/dashboard'], [$adviser, '/adviser/grades'], [$principal, '/principal/dashboard'],
                  [$principal, '/principal/students'], [$admin, '/admin/reports']] as [$user, $url]) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertDontSee('may be out of date')
                ->assertDontSee('Risk Level');
        }
    }
}
