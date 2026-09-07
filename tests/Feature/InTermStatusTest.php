<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2 of "live in-term risk + stale data guard": In-Term Status is
 * computed from assessment evidence alone and must be available with
 * ZERO submitted term reports — that's the entire point of adding it
 * alongside (never instead of) the classifier's Risk Level.
 */
class InTermStatusTest extends TestCase
{
    use RefreshDatabase;

    private InTermStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InTermStatusService();
    }

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned, float $max = 100, int $term = 1): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => $term, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_status_is_on_track_when_no_component_is_below_target(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subject, $student, $component, 90);
        }

        $result = $this->service->statusFor($student, $subject, $section, 1, $section->school_year);

        $this->assertSame('On Track', $result['status']);
        $this->assertSame(0, $result['components_below_target']);
        $this->assertSame(3, $result['item_count']);
    }

    public function test_status_is_needs_attention_with_exactly_one_component_below_target(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $this->score($section, $subject, $student, 'written_work', 90);
        $this->score($section, $subject, $student, 'performance_task', 60); // below 75 target
        $this->score($section, $subject, $student, 'examination', 90);

        $result = $this->service->statusFor($student, $subject, $section, 1, $section->school_year);

        $this->assertSame('Needs Attention', $result['status']);
        $this->assertSame(1, $result['components_below_target']);
        $this->assertSame('performance_task', $result['weakest_component']);
    }

    public function test_status_is_at_risk_with_two_or_more_components_below_target(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $this->score($section, $subject, $student, 'written_work', 60);
        $this->score($section, $subject, $student, 'performance_task', 60);
        $this->score($section, $subject, $student, 'examination', 90);

        $result = $this->service->statusFor($student, $subject, $section, 1, $section->school_year);

        $this->assertSame('At Risk', $result['status']);
        $this->assertSame(2, $result['components_below_target']);
    }

    public function test_item_count_reflects_only_this_students_scored_items_for_this_subject_and_term(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $otherStudent = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();

        $this->score($section, $subject, $student, 'written_work', 18, 20);
        $this->score($section, $subject, $student, 'written_work', 14, 20);
        $this->score($section, $subject, $student, 'performance_task', 16, 20);
        // Noise that must NOT be counted: another student, another subject, another term.
        $this->score($section, $subject, $otherStudent, 'written_work', 10, 20);
        $this->score($section, $otherSubject, $student, 'written_work', 10, 20);
        $this->score($section, $subject, $student, 'examination', 10, 20, term: 2);

        $result = $this->service->statusFor($student, $subject, $section, 1, $section->school_year);

        $this->assertSame(3, $result['item_count']);
    }

    /**
     * The whole point of this task: this must work identically with
     * ZERO submitted term reports (no risk_results at all).
     */
    public function test_status_is_produced_with_zero_risk_results(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $this->score($section, $subject, $student, 'written_work', 60);
        $this->score($section, $subject, $student, 'performance_task', 60);

        $this->assertSame(0, RiskResult::count());

        $result = $this->service->statusFor($student, $subject, $section, 1, $section->school_year);

        $this->assertNotNull($result['status']);
        $this->assertContains($result['status'], InTermStatusService::STATUSES);
        $this->assertSame(0, RiskResult::count(), 'In-Term Status must never write to risk_results.');
    }

    public function test_status_with_zero_evidence_is_on_track_with_zero_items(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $result = $this->service->statusFor($student, $subject, $section, 1, $section->school_year);

        $this->assertSame('On Track', $result['status']);
        $this->assertSame(0, $result['item_count']);
    }

    /**
     * End-to-end: the Principal Students page, with zero submitted term
     * reports, must show a distinct In-Term Status + item count for
     * every student.
     */
    public function test_principal_students_page_shows_in_term_status_and_item_count_for_every_student_with_zero_risk_results(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $strong = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Strong']);
        $weak = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Weak']);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subject, $strong, $component, 95);
        }
        $this->score($section, $subject, $weak, 'written_work', 50);
        $this->score($section, $subject, $weak, 'performance_task', 50);

        $this->assertSame(0, RiskResult::count());

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('In-Term Status');
        $response->assertSee('On Track');
        $response->assertSee('At Risk');
        $response->assertSee('item'); // "N item(s) scored so far"
    }

    /**
     * Sort default: At Risk first. Uses a scenario where computed_grade
     * ordering and in_term_status ordering would DIFFER, to prove the
     * real mechanism rather than coincidentally passing either way.
     */
    public function test_principal_students_page_sorts_at_risk_first_by_default_even_when_computed_grade_would_order_differently(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        // "Borderline" has ONE component just under target (74%) but a
        // HIGHER computed grade overall than "TwoWeak", which has TWO
        // components below target but each only slightly. In-Term Status
        // must rank TwoWeak (At Risk, 2 components) ahead of Borderline
        // (Needs Attention, 1 component) — the opposite of what sorting
        // by raw computed_grade alone would do here.
        $borderline = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Borderline']);
        $this->score($section, $subject, $borderline, 'written_work', 100);
        $this->score($section, $subject, $borderline, 'performance_task', 100);
        $this->score($section, $subject, $borderline, 'examination', 74); // 1 component below target -> Needs Attention

        $twoWeak = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'TwoWeak']);
        $this->score($section, $subject, $twoWeak, 'written_work', 74);
        $this->score($section, $subject, $twoWeak, 'performance_task', 74); // 2 components below target -> At Risk
        $this->score($section, $subject, $twoWeak, 'examination', 100);

        // Sanity check the premise: Borderline's computed_grade IS higher.
        $borderlineGrade = (100 * 0.25) + (100 * 0.5) + (74 * 0.25);
        $twoWeakGrade = (74 * 0.25) + (74 * 0.5) + (100 * 0.25);
        $this->assertGreaterThan($twoWeakGrade, $borderlineGrade);

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');
        $content = $response->getContent();

        $posTwoWeak = strpos($content, 'TwoWeak');
        $posBorderline = strpos($content, 'Borderline');

        $this->assertNotFalse($posTwoWeak);
        $this->assertNotFalse($posBorderline);
        $this->assertLessThan($posBorderline, $posTwoWeak, 'At Risk (TwoWeak) must sort before Needs Attention (Borderline) by default, even though Borderline has the higher computed grade.');
    }

    /**
     * Task 3's explicit final check: submitting a term report (running
     * the real classifier through the real /adviser/submit-report route,
     * completely untouched by this task) must still produce a Risk Level,
     * and it must appear ALONGSIDE — not replacing — In-Term Status.
     */
    public function test_submitting_a_term_report_shows_risk_level_alongside_in_term_status(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        \App\Models\AcademicTerm::ensureExistFor('2026-2027');

        $this->score($section, $subject, $student, 'written_work', 60);
        $this->score($section, $subject, $student, 'performance_task', 60);
        $this->score($section, $subject, $student, 'examination', 90);

        \App\Models\Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'grade' => 70, 'school_year' => '2026-2027',
        ]);

        $this->assertSame(0, RiskResult::count());

        $submit = $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);
        $submit->assertSessionDoesntHaveErrors();

        $this->assertGreaterThan(0, RiskResult::count(), 'The classifier must still write risk_results exactly as before.');

        $principal = User::factory()->principal()->create();
        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        // In-Term Status: computed from assessment evidence, present with zero dependency on the classifier.
        $response->assertSee('In-Term Status');
        $response->assertSee('scored so far', false);
        // Risk Level: the untouched classifier output, now shown side by side.
        $response->assertSee('Risk Level');
        $riskResult = RiskResult::where('student_id', $student->id)->first();
        $response->assertSee(ucfirst($riskResult->risk_level), false);
    }
}
