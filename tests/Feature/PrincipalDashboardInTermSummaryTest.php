<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 3 of "close the intervention loop": the Principal dashboard used
 * to show 0/0/0 Risk cards and three empty charts until a term report
 * was submitted — looking broken even though the zeros were correct. The
 * In-Term Status summary (DashboardAnalyticsService::getInTermStatusSummary())
 * populates from assessment evidence alone, immediately after upload.
 */
class PrincipalDashboardInTermSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned, float $max = 100): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_summary_counts_are_zero_on_an_empty_database(): void
    {
        $summary = (new DashboardAnalyticsService())->getInTermStatusSummary();

        $this->assertSame(0, $summary['inTermOnTrack']);
        $this->assertSame(0, $summary['inTermNeedsAttention']);
        $this->assertSame(0, $summary['inTermAtRisk']);
        $this->assertSame(0, $summary['inTermTotal']);
    }

    public function test_summary_buckets_students_correctly_from_assessment_evidence_alone(): void
    {
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $onTrack = Student::factory()->create(['section_id' => $section->id]);
        foreach (['written_work', 'performance_task', 'examination'] as $c) {
            $this->score($section, $subject, $onTrack, $c, 90);
        }

        $needsAttention = Student::factory()->create(['section_id' => $section->id]);
        $this->score($section, $subject, $needsAttention, 'written_work', 90);
        $this->score($section, $subject, $needsAttention, 'performance_task', 60);
        $this->score($section, $subject, $needsAttention, 'examination', 90);

        $atRisk = Student::factory()->create(['section_id' => $section->id]);
        $this->score($section, $subject, $atRisk, 'written_work', 50);
        $this->score($section, $subject, $atRisk, 'performance_task', 50);

        $this->assertSame(0, RiskResult::count());

        $summary = (new DashboardAnalyticsService())->getInTermStatusSummary();

        $this->assertSame(1, $summary['inTermOnTrack']);
        $this->assertSame(1, $summary['inTermNeedsAttention']);
        $this->assertSame(1, $summary['inTermAtRisk']);
        $this->assertSame(3, $summary['inTermTotal']);
    }

    public function test_a_student_with_no_scored_evidence_anywhere_is_not_counted(): void
    {
        $section = Section::factory()->create();
        Student::factory()->create(['section_id' => $section->id]); // no scores at all

        $summary = (new DashboardAnalyticsService())->getInTermStatusSummary();

        $this->assertSame(0, $summary['inTermTotal']);
    }

    public function test_worst_subject_wins_when_a_student_takes_more_than_one(): void
    {
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $goodSubject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $badSubject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $student = Student::factory()->create(['section_id' => $section->id]);
        foreach (['written_work', 'performance_task', 'examination'] as $c) {
            $this->score($section, $goodSubject, $student, $c, 90);
        }
        $this->score($section, $badSubject, $student, 'written_work', 50);
        $this->score($section, $badSubject, $student, 'performance_task', 50);

        $summary = (new DashboardAnalyticsService())->getInTermStatusSummary();

        $this->assertSame(0, $summary['inTermOnTrack']);
        $this->assertSame(1, $summary['inTermAtRisk'], 'The student takes one subject at At Risk and one On Track — the worse subject must decide the bucket.');
    }

    public function test_principal_dashboard_shows_in_term_cards_with_zero_submitted_reports(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->score($section, $subject, $student, 'written_work', 50);
        $this->score($section, $subject, $student, 'performance_task', 50);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('In-Term Status');
        $response->assertSee('On Track');
        $response->assertSee('Needs Attention');
        $response->assertSee('At Risk');
    }

    /**
     * Superseded by Task 1 of "close the delivery loop": with ZERO risk
     * results, the three Risk cards and three charts (this one included)
     * are now collapsed into a single explanatory block — see
     * PrincipalDashboardRiskCollapseTest. The Performance Trend and
     * At-Risk per Section charts' OWN individual empty-state text (still
     * present in the markup, per that task's "conditional render, not a
     * deletion") is covered instead by
     * PrincipalDashboardRiskCollapseTest::test_individual_chart_empty_states_still_show_when_risk_data_exists_but_those_charts_dont().
     */
    public function test_principal_dashboard_charts_explain_what_fills_them_when_empty(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        // No risk results at all -> the single consolidated block, not
        // the three individual per-chart messages.
        $response->assertSee('Risk Level appears once advisers submit their term reports');
        $response->assertDontSee('this fills in once an adviser submits a term report');
    }

    /**
     * "Correctness and interface pass" TASK 6b — getInTermStatusTrend()
     * must break the counts out PER TERM, not just report the currently
     * open one, and each term's counts must match what
     * getInTermStatusSummary() would report for that same term.
     */
    public function test_trend_reports_separate_counts_per_term(): void
    {
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        // Term 1: At Risk (two components below target).
        $t1 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'ww1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $t1->id, 'student_id' => $student->id, 'score' => 50]);
        $t1b = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'pt1', 'component' => 'performance_task', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $t1b->id, 'student_id' => $student->id, 'score' => 50]);

        // Term 2: On Track (all three components at 90). score() hardcodes
        // grading_period 1, so Term 2 rows are built directly here instead.
        foreach (['written_work', 'performance_task', 'examination'] as $c) {
            $a = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 2, 'school_year' => '2026-2027',
                'name' => $c . '-t2', 'component' => $c, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => 90]);
        }

        $trend = (new DashboardAnalyticsService())->getInTermStatusTrend();

        $this->assertCount(3, $trend);
        $byTerm = collect($trend)->keyBy('term');

        $this->assertSame(1, $byTerm[1]['atRisk']);
        $this->assertSame(0, $byTerm[1]['onTrack']);
        $this->assertSame(1, $byTerm[2]['onTrack']);
        $this->assertSame(0, $byTerm[2]['atRisk']);
        // Term 3 has no evidence at all yet.
        $this->assertSame(0, $byTerm[3]['total']);

        // Must agree with getInTermStatusSummary() for the currently open term.
        $summary = (new DashboardAnalyticsService())->getInTermStatusSummary();
        $this->assertSame($byTerm[$summary['inTermTerm']]['atRisk'], $summary['inTermAtRisk']);
        $this->assertSame($byTerm[$summary['inTermTerm']]['onTrack'], $summary['inTermOnTrack']);
    }

    /**
     * "UI legibility pass" item 4 — the Term-over-Term Trend panel's
     * per-term counts are abbreviated (OT/NA/AR) to fit on one line; a
     * legend stating the mapping in words must appear once on the panel
     * so a first-time reader isn't left inferring it from the stacked
     * bar's colours or the panel's own subtitle.
     */
    public function test_term_over_term_trend_shows_a_legend_and_labels_its_counts(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        // All three components well above target -> On Track (0 below).
        $this->score($section, $subject, $student, 'written_work', 90);
        $this->score($section, $subject, $student, 'performance_task', 90);
        $this->score($section, $subject, $student, 'examination', 90);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertSee('OT = On Track, NA = Needs Attention, AR = At Risk');
        $response->assertSee('1 OT', false);
    }

    public function test_in_term_summary_never_appears_under_a_risk_heading(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $this->score($section, $subject, $student, 'written_work', 50);
        $this->score($section, $subject, $student, 'performance_task', 50);

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $content = $response->getContent();

        // The In-Term Status heading must appear strictly before the Risk
        // Level heading — i.e. its own section, not folded into the Risk
        // cards' heading or numbers.
        $posInTerm = strpos($content, 'In-Term Status — Term');
        $posRisk = strpos($content, 'Risk Level — submitted term reports');

        $this->assertNotFalse($posInTerm);
        $this->assertNotFalse($posRisk);
        $this->assertLessThan($posRisk, $posInTerm);
    }
}
