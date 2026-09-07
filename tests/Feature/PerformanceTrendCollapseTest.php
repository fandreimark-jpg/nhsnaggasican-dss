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
 * TASK 3 of "status clarity and progress consistency" — Zone 3 of the
 * Principal dashboard used to collapse Performance Trend along with Risk
 * Distribution and At-Risk per Section whenever no term report had been
 * submitted, even though Performance Trend's own data (now: average
 * COMPUTED grade from assessment evidence — see
 * DashboardAnalyticsService::computeAssessmentEvidenceTrend()) never
 * actually depended on risk_results. This is the exact verify scenario
 * from that task's prompt: assessment evidence exists, zero reports are
 * submitted, Performance Trend must render with real values while Risk
 * Distribution and At-Risk per Section stay collapsed with their
 * existing explanation.
 */
class PerformanceTrendCollapseTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_trend_renders_with_real_values_while_risk_charts_stay_collapsed(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        // Complete evidence (all three components) for one student/subject
        // in Term 1 — enough for computeAssessmentEvidenceTrend() to
        // produce a real Term 1 average. No RiskResult anywhere.
        foreach ([['written_work', 80], ['performance_task', 90], ['examination', 70]] as [$component, $earned]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        // Grade 11 in SY 2026-2027 resolves to the do015_2026 scheme
        // (see TransmutationService::schemeFor()); this subject has no
        // subject_group override so it defaults to 'core_academic',
        // whose weights are 20/50/30 (see SubjectGroupWeightsSeeder) —
        // NOT the do8_2015 25/50/25 flat split.
        // Expected average computed grade for term 1: 80*.20 + 90*.5 + 70*.30 = 82.0.
        $expected = 82.0;

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        // Performance Trend renders a REAL chart (canvas present), not the empty state.
        $response->assertSee('termTrendChart', false);
        $response->assertDontSee('No student/subject yet has scored evidence', false);
        $response->assertSee((string) $expected, false);

        // Risk Distribution and At-Risk per Section are still collapsed,
        // with their existing explanation — no risk_results exist at all.
        $response->assertDontSee('riskDonutChart', false);
        $response->assertDontSee('sectionRiskChart', false);
        $response->assertSee('Risk Level appears once advisers submit their term reports');
    }

    public function test_performance_trend_only_counts_students_with_evidence_in_all_three_components(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        // Only Written Work scored — incomplete, must not produce a trend value.
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'WW1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 80]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertDontSee('termTrendChart', false);
        $response->assertSee('No student/subject yet has scored evidence in all three components for any term', false);
    }
}
