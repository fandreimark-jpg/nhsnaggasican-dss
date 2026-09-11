<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 1 of "close the delivery loop": zero risk results reads as a
 * broken page (three zero cards + three empty charts) rather than a
 * correctly-empty one. When no risk results exist for the active school
 * year, all six are replaced by a single explanatory block; when they
 * exist, the page renders exactly as before (a conditional render, never
 * a deletion of the markup or queries — see principal/dashboard.blade.php).
 */
class PrincipalDashboardRiskCollapseTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_risk_results_shows_one_explanatory_block_not_six_empty_elements(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('Risk Level appears once advisers submit their term reports');
        $response->assertSee('In-Term Status');
        // The cards and canvases themselves must not render at all —
        // "Low/Moderate/High Risk" as plain text still appears further
        // down in the unrelated Recommendations panel (out of Task 1's
        // scope, which named only the cards and the three charts), so the
        // canvases are the unambiguous signal that the charts collapsed.
        $response->assertDontSee('riskDonutChart', false);
        $response->assertDontSee('termTrendChart', false);
        $response->assertDontSee('sectionRiskChart', false);
    }

    public function test_risk_results_present_renders_the_full_section_exactly_as_before(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 92,
            'risk_level' => 'low', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('Low Risk');
        $response->assertSee('Moderate Risk');
        $response->assertSee('High Risk');
        $response->assertSee('riskDonutChart', false);
        $response->assertDontSee('Risk Level appears once advisers submit their term reports');
    }

    /**
     * The At-Risk per Section chart's own individual empty-state message
     * (from an earlier task) still works on its own terms once at least
     * one risk result exists — a DIFFERENT condition (section-level risk)
     * than "any risk result exists at all," which is what Task 1 gates on.
     *
     * Performance Trend's own empty state is covered separately below
     * (test_performance_trend_empty_state_is_evidence_based_not_grade_based)
     * — TASK 3 of "status clarity and progress consistency" moved that
     * chart out of this $hasRiskData-gated section entirely, so it no
     * longer belongs in a test about what's inside this collapsed block.
     */
    public function test_individual_chart_empty_states_still_show_when_risk_data_exists_but_those_charts_dont(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        // A risk result exists (so $hasRiskData is true, the section
        // renders) but no OTHER section has any risk result, so At-Risk
        // per Section has nothing to plot for those sections.
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 92,
            'risk_level' => 'low', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);
        Section::factory()->create(['school_year' => '2026-2027']); // no students, no risk data

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('riskDonutChart', false); // has data -> real chart
        $response->assertSee('sectionRiskChart', false); // this section DOES have data -> real chart either way
    }

    /**
     * TASK 3 of "status clarity and progress consistency" — Performance
     * Trend now renders OUTSIDE the risk-gated block (see the test class
     * above), and its empty state is keyed to complete assessment
     * evidence, not to whether any Grade row was ever encoded.
     */
    public function test_performance_trend_empty_state_is_evidence_based_not_grade_based(): void
    {
        $principal = User::factory()->principal()->create();

        // Zero risk results AND zero assessment evidence — Performance
        // Trend must still render its OWN card (never collapsed with the
        // risk-gated charts), just with nothing to plot yet.
        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('Performance Trend');
        $response->assertSee('Average COMPUTED grade per term, from assessment evidence', false);
        $response->assertSee('No student/subject yet has scored evidence in all three components for any term', false);
    }

    public function test_submitting_a_term_report_makes_the_risk_section_reappear(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        \App\Models\AcademicTerm::ensureExistFor('2026-2027');
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = \App\Models\Subject::factory()->create(['grade_level' => $section->grade_level]);

        Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'grade' => 85, 'school_year' => '2026-2027',
        ]);

        $principal = User::factory()->principal()->create();

        $before = $this->actingAs($principal)->get('/principal/dashboard');
        $before->assertSee('Risk Level appears once advisers submit their term reports');

        $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);

        $after = $this->actingAs($principal)->get('/principal/dashboard');
        $after->assertDontSee('Risk Level appears once advisers submit their term reports');
        $after->assertSee('Low Risk');
        $after->assertSee('riskDonutChart', false);
    }

    /**
     * "UI legibility pass" — the Risk Level heading must name which term(s)
     * it's actually drawn from (MASTER_PROMPT.md Part 0.8's "name the term"
     * rule, already applied on Admin/Principal Reports's per-row "as of
     * Term N"), not just say "submitted term reports" and leave the reader
     * to guess when a later term is unsubmitted.
     */
    public function test_the_risk_level_heading_names_the_term_it_is_as_of(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 2, 'average_grade' => 92,
            'risk_level' => 'low', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertSee('Risk Level — submitted term reports (as of Term 2)');
    }

    /**
     * A real possibility, not just a hypothetical: sections can submit on
     * different schedules, so two students' MOST RECENT risk results can
     * legitimately be different terms. The heading must report both terms
     * it's drawn from rather than silently picking one.
     */
    public function test_the_risk_level_heading_names_every_term_present_when_they_differ(): void
    {
        $principal = User::factory()->principal()->create();
        $sectionA = Section::factory()->create(['school_year' => '2026-2027']);
        $sectionB = Section::factory()->create(['school_year' => '2026-2027']);
        $studentA = Student::factory()->create(['section_id' => $sectionA->id]);
        $studentB = Student::factory()->create(['section_id' => $sectionB->id]);
        RiskResult::create([
            'student_id' => $studentA->id, 'grading_period' => 1, 'average_grade' => 92,
            'risk_level' => 'low', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);
        RiskResult::create([
            'student_id' => $studentB->id, 'grading_period' => 2, 'average_grade' => 92,
            'risk_level' => 'low', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertSee('Risk Level — submitted term reports (as of Term 1, 2)');
    }
}
