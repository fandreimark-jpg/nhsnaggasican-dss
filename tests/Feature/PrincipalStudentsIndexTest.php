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
 * The Students index is the fix for the reachability gap described in
 * routes/web.php's principal.students comment: PerformanceAnalysisService
 * evidence must be reachable WITHOUT any submitted term report (no
 * whereHas('riskResults') anywhere in this path — that gate belongs only
 * to Interventions, driven by getAtRiskStudentsData()).
 */
class PrincipalStudentsIndexTest extends TestCase
{
    use RefreshDatabase;

    /** Creates a section + subject + $count students with full WW/PT/Exam evidence, no risk_results anywhere. */
    private function seedSectionWithEvidence(int $count = 3, int $gradeLevel = 11): array
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => $gradeLevel, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $gradeLevel, 'type' => 'core']);

        $ww = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20,
        ]);
        $pt = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Performance Task 1', 'component' => 'performance_task', 'max_score' => 20,
        ]);
        $exam = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Exam', 'component' => 'examination', 'max_score' => 20,
        ]);

        $students = [];
        for ($i = 0; $i < $count; $i++) {
            $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Student' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
            // Deliberately low scores for student 0 (the one that should sort to the top when worst-first).
            $score = $i === 0 ? 8 : 18;
            AssessmentScore::factory()->create(['assessment_id' => $ww->id, 'student_id' => $student->id, 'score' => $score]);
            AssessmentScore::factory()->create(['assessment_id' => $pt->id, 'student_id' => $student->id, 'score' => $score]);
            AssessmentScore::factory()->create(['assessment_id' => $exam->id, 'student_id' => $student->id, 'score' => $score]);
            $students[] = $student;
        }

        return compact('adviser', 'section', 'subject', 'students');
    }

    public function test_students_index_shows_component_percentages_with_zero_risk_results(): void
    {
        $seed = $this->seedSectionWithEvidence(3);
        $principal = User::factory()->principal()->create();

        $this->assertSame(0, \App\Models\RiskResult::count());

        $response = $this->actingAs($principal)
            ->get('/principal/students?subject_id=' . $seed['subject']->id . '&period=1');

        $response->assertOk();
        $response->assertSee($seed['students'][0]->last_name, false);
        $response->assertSee('40.00%', false); // 8/20 = 40%
        $response->assertSee('90.00%', false); // 18/20 = 90%
        $response->assertSee($seed['adviser']->name, false);
    }

    /**
     * The default sort key changed to in_term_status (At Risk first) —
     * see the "live in-term risk + stale data guard" prompt, Problem 2b
     * — but this scenario's worst student is also worst by computed
     * grade, so it still passes as a weaker corollary. See
     * InTermStatusTest::test_principal_students_page_sorts_at_risk_first_by_default_even_when_computed_grade_would_order_differently()
     * for the test that actually distinguishes the two mechanisms.
     */
    public function test_worst_computed_grade_sorts_first_by_default(): void
    {
        $seed = $this->seedSectionWithEvidence(3);
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)
            ->get('/principal/students?subject_id=' . $seed['subject']->id . '&period=1');

        $content = $response->getContent();
        $posWorst = strpos($content, $seed['students'][0]->last_name);
        $posOther = strpos($content, $seed['students'][1]->last_name);

        $this->assertNotFalse($posWorst);
        $this->assertNotFalse($posOther);
        $this->assertLessThan($posOther, $posWorst, 'The student with the lowest computed grade should render before a stronger student.');
    }

    public function test_missing_evidence_shows_an_em_dash_not_zero(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        Student::factory()->create(['section_id' => $section->id]);
        // No Assessment/AssessmentScore rows at all for this subject/term.

        $principal = User::factory()->principal()->create();
        $response = $this->actingAs($principal)
            ->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertDontSee('0.00%', false);
    }

    public function test_focus_filter_narrows_to_students_weakest_in_that_component(): void
    {
        $seed = $this->seedSectionWithEvidence(3);
        $principal = User::factory()->principal()->create();

        // Give student[1] a written_work-specific weakness on top of the base evidence.
        $wwAssessment = Assessment::where('component', 'written_work')->where('subject_id', $seed['subject']->id)->first();
        AssessmentScore::where('assessment_id', $wwAssessment->id)->where('student_id', $seed['students'][1]->id)
            ->update(['score' => 2]); // 2/20 = 10% -> written_work becomes the weakest component

        $response = $this->actingAs($principal)->get(
            '/principal/students?subject_id=' . $seed['subject']->id . '&period=1&focus=written_work'
        );

        $response->assertOk();
        $response->assertSee($seed['students'][1]->last_name, false);
        $response->assertSee('Showing students weakest in', false);
    }

    public function test_subject_analysis_component_cell_links_to_students_index_with_focus(): void
    {
        $seed = $this->seedSectionWithEvidence(3);
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/subject-analysis');

        $response->assertOk();
        $response->assertSee(
            route('principal.students', ['subject_id' => $seed['subject']->id, 'focus' => 'written_work'])
        );
    }

    public function test_students_index_paginates_at_25_per_page(): void
    {
        $seed = $this->seedSectionWithEvidence(30);
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)
            ->get('/principal/students?subject_id=' . $seed['subject']->id . '&period=1');

        $response->assertOk();
        $response->assertViewHas('students', function ($paginator) {
            return $paginator->count() === 25 && $paginator->total() === 30;
        });
    }
}
