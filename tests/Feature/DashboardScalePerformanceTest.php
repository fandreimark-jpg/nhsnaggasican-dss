<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * Task 3a in the "remaining system issues" prompt: DashboardAnalyticsService
 * used to run two unbounded Student::with([...])->get() calls per Principal
 * dashboard load. getAtRiskStudentsData() is now paginated at 25/page (with
 * the expensive weakest_subject_component deferred to the current page
 * only). It must produce EXACTLY the same rows/order/values as before —
 * this file is the regression net for that "without changing any result"
 * constraint.
 *
 * The Academic Honors lists that getSummaryData() used to also return were
 * removed entirely by the terminology/interface cleanup task (see
 * CLAUDE.md's "Known limitations" — the feature was never reachable from
 * any Principal workflow), so this file no longer covers that.
 */
class DashboardScalePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAtRiskStudent(int $averageGrade, string $riskLevel, ?Section $section = null): Student
    {
        $section ??= Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => $averageGrade,
            'risk_level' => $riskLevel, 'school_year' => $section->school_year, 'generated_at' => now(),
        ]);

        return $student;
    }

    public function test_at_risk_list_paginates_at_25_per_page_and_total_covers_the_whole_matching_set(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->makeAtRiskStudent(60 + $i, 'high');
        }

        $data = (new DashboardAnalyticsService())->getAtRiskStudentsData();

        $this->assertInstanceOf(LengthAwarePaginator::class, $data['atRiskStudents']);
        $this->assertSame(25, $data['atRiskStudents']->count(), 'Page 1 must show exactly 25 rows.');
        $this->assertSame(30, $data['atRiskStudentsTotal'], 'The total must cover the whole matching set, not just the page.');
        $this->assertSame(30, $data['atRiskStudents']->total());
    }

    /**
     * NOT a "correct sort order" assertion — while writing this test,
     * comparing against the pre-pagination code (via a temporary git
     * stash) showed that Collection::sortBy() given an array of plain
     * Closures (as getAtRiskStudentsData()'s 3-key sort does) does NOT
     * actually sort by risk_level/consecutive_decline/average in that
     * priority order the way the code appears to intend — the resulting
     * order is a pre-existing latent bug, present before this task and
     * confirmed byte-identical after it. Task 3a's constraint is "fix
     * the N+1 without changing any result," so this test locks in the
     * ACTUAL (not idealized) order pagination must reproduce — fixing
     * the underlying sortBy quirk is a separate, out-of-scope change.
     */
    public function test_pagination_reproduces_the_exact_same_order_as_before_this_task(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $level = $i < 15 ? 'high' : 'moderate';
            $avg = 50 + $i;
            $this->makeAtRiskStudent($avg, $level);
        }

        $data = (new DashboardAnalyticsService())->getAtRiskStudentsData();
        $page1Averages = $data['atRiskStudents']->pluck('average')->all();

        // Captured from the pre-Task-3a code against this exact fixture
        // (30 students, same construction loop) via a temporary git
        // stash — see the docblock above.
        $expectedFirst25 = [51, 76, 78, 72, 74, 68, 70, 57, 58, 55, 56, 54, 53, 52, 66, 59, 67, 60, 69, 61, 71, 62, 73, 63, 75];
        // assertEquals (not assertSame) — this is checking ORDER, not the
        // exact int/float/string representation average_grade happens to
        // come back as.
        $this->assertEquals($expectedFirst25, $page1Averages);
    }

    public function test_weakest_subject_component_is_still_correct_on_a_paginated_page(): void
    {
        $section = Section::factory()->create();
        $student = $this->makeAtRiskStudent(65, 'high', $section);
        $subject = Subject::factory()->create();

        RiskResult::where('student_id', $student->id)->update([
            'weakest_subject' => $subject->name, 'weakest_subject_id' => $subject->id, 'weakest_subject_grade' => 65,
        ]);

        foreach ([['written_work', 84], ['performance_task', 60], ['examination', 70]] as [$component, $earned]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $rows = (new DashboardAnalyticsService())->getAtRiskStudentsData()['atRiskStudents'];

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('performance_task', $row['weakest_subject_component']['key']);
        $this->assertArrayNotHasKey('_student', $row, 'The temporary model reference must never leak into the returned row.');
        $this->assertArrayNotHasKey('_latest_risk', $row);
    }

    public function test_ar_component_filter_still_matches_correctly_when_the_match_is_off_the_first_page(): void
    {
        // 26 high-risk students with NO assessment evidence (weakest
        // component always null) plus 1 more, on page 2, that DOES have
        // performance_task evidence — the filter must still find it even
        // though it isn't among the deferred-computation page-1 rows.
        for ($i = 0; $i < 26; $i++) {
            $this->makeAtRiskStudent(90 - $i, 'high');
        }

        $section = Section::factory()->create();
        $target = $this->makeAtRiskStudent(50, 'high', $section);
        $subject = Subject::factory()->create();
        RiskResult::where('student_id', $target->id)->update([
            'weakest_subject' => $subject->name, 'weakest_subject_id' => $subject->id, 'weakest_subject_grade' => 50,
        ]);
        foreach ([['written_work', 90], ['performance_task', 40], ['examination', 90]] as [$component, $earned]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $target->id, 'score' => $earned]);
        }

        request()->merge(['ar_component' => 'performance_task']);
        $data = (new DashboardAnalyticsService())->getAtRiskStudentsData();

        $this->assertSame(1, $data['atRiskStudentsTotal']);
        $this->assertSame($target->id, $data['atRiskStudents']->first()['student_id']);
    }
}
