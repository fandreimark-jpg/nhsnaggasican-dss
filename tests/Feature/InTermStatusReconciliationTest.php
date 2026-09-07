<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "In-Term Status reconciliation" work order — regression coverage for the
 * defect where the Adviser and Principal dashboards disagreed on the same
 * learner's Overall In-Term Status for the same section and term.
 *
 * Root cause: DashboardAnalyticsService::computeInTermStatusCounts() ran its
 * own flat SUM(earned)/SUM(max_score) aggregate SQL query instead of calling
 * GradingEngine, so it silently ignored the DO 015 exam-role weighting
 * (ExamRoleShare) and incorrectly folded in no-role additional-support items
 * that GradingEngine::examinationPercentage() deliberately excludes once any
 * role-tagged item exists. Both dashboards now call the single
 * InTermStatusService::overallStatusForSection() — this file locks that in.
 */
class InTermStatusReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function fillWrittenWorkAndPerformanceTask(Subject $subject, Section $section, Student $student, string $schoolYear, int $term, float $score = 90): void
    {
        foreach (['written_work', 'performance_task'] as $component) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => $term, 'school_year' => $schoolYear,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $score]);
        }
    }

    /**
     * Reproduces the exact class of defect. The flat-sum bug pulled this
     * learner's Examination percentage above target (On Track); the
     * role-weighted, additional-support-excluded calculation GradingEngine
     * actually uses keeps it below target (Needs Attention). Both the
     * Adviser dashboard's computation and the Principal dashboard's
     * whole-school summary must agree on the LOWER (correct) status.
     */
    public function test_adviser_and_principal_agree_on_a_learner_whose_exam_uses_roles_and_additional_support(): void
    {
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);

        $adviser = User::factory()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11, 'adviser_id' => $adviser->id]);
        AcademicTerm::create(['school_year' => '2026-2027', 'term' => 3, 'is_open' => true]);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic', 'type' => 'core', 'grade_level' => 11]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->fillWrittenWorkAndPerformanceTask($subject, $section, $student, '2026-2027', 3);

        // Role-tagged items, same shape as the real incident (Molave,
        // Vince Oribello, Term 3, General Mathematics).
        $st1 = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 3, 'school_year' => '2026-2027', 'name' => 'ST1', 'component' => 'examination', 'exam_role' => 'st1', 'max_score' => 30]);
        AssessmentScore::factory()->create(['assessment_id' => $st1->id, 'student_id' => $student->id, 'score' => 22]);
        $st2 = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 3, 'school_year' => '2026-2027', 'name' => 'ST2', 'component' => 'examination', 'exam_role' => 'st2', 'max_score' => 30]);
        AssessmentScore::factory()->create(['assessment_id' => $st2->id, 'student_id' => $student->id, 'score' => 22]);
        $termExam = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 3, 'school_year' => '2026-2027', 'name' => 'Term Exam', 'component' => 'examination', 'exam_role' => 'term_exam', 'max_score' => 60]);
        AssessmentScore::factory()->create(['assessment_id' => $termExam->id, 'student_id' => $student->id, 'score' => 43]);
        // No-role additional-support item: GradingEngine excludes this from
        // the role-weighted calc; the old flat SQL sum wrongly folded it in.
        $remedial = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 3, 'school_year' => '2026-2027', 'name' => 'Remedial', 'component' => 'examination', 'exam_role' => null, 'max_score' => 60]);
        AssessmentScore::factory()->create(['assessment_id' => $remedial->id, 'student_id' => $student->id, 'score' => 50]);

        // Role-weighted (30/30/40): 73.33*.3 + 73.33*.3 + 71.67*.4 = 72.67 -> below 75 target -> Needs Attention.
        // Old flat sum including the remedial item: (22+22+43+50)/(30+30+60+60) = 137/180 = 76.11 -> wrongly On Track.

        $summary = (new DashboardAnalyticsService())->getInTermStatusSummary();

        $this->assertSame(3, $summary['inTermTerm']);
        $this->assertSame(1, $summary['inTermNeedsAttention'], 'Principal dashboard must count this learner as Needs Attention, not On Track.');
        $this->assertSame(0, $summary['inTermOnTrack']);
        $this->assertSame(0, $summary['inTermAtRisk']);

        // Cross-check directly against the exact shared method the Adviser
        // dashboard calls, for the same section/term.
        $rows = (new InTermStatusService())->overallStatusForSection($section, collect([$student]), collect([$subject]), 3);
        $this->assertSame('Needs Attention', $rows->first()['in_term_status']['status']);
    }

    public function test_a_learner_with_one_subject_lacking_evidence_produces_the_same_status_on_both_paths(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11, 'adviser_id' => $adviser->id]);
        AcademicTerm::create(['school_year' => '2026-2027', 'term' => 3, 'is_open' => true]);
        $subjectWithEvidence = Subject::factory()->create(['subject_group' => 'core_academic', 'type' => 'core', 'grade_level' => 11]);
        $subjectNoEvidence = Subject::factory()->create(['subject_group' => 'core_academic', 'type' => 'core', 'grade_level' => 11]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        // Only the first subject has evidence; the second is genuinely
        // unassessed this term — must be excluded from the worst-of
        // reduction, not treated as On Track.
        $this->fillWrittenWorkAndPerformanceTask($subjectWithEvidence, $section, $student, '2026-2027', 3);
        $exam = Assessment::factory()->create(['subject_id' => $subjectWithEvidence->id, 'section_id' => $section->id, 'grading_period' => 3, 'school_year' => '2026-2027', 'name' => 'Exam', 'component' => 'examination', 'max_score' => 100]);
        AssessmentScore::factory()->create(['assessment_id' => $exam->id, 'student_id' => $student->id, 'score' => 40]); // well below target

        $rows = (new InTermStatusService())->overallStatusForSection($section, collect([$student]), collect([$subjectWithEvidence, $subjectNoEvidence]), 3);
        $row = $rows->first();

        $this->assertSame('Needs Attention', $row['in_term_status']['status']); // exam below target, WW/PT fine -> 1 component below
        $this->assertSame(1, $row['subjects_evaluated']);
        $this->assertSame(2, $row['subjects_total']);

        $summary = (new DashboardAnalyticsService())->getInTermStatusSummary();
        $this->assertSame(1, $summary['inTermNeedsAttention']);
        $this->assertSame(0, $summary['inTermOnTrack']);
        $this->assertSame(0, $summary['inTermAtRisk']);
    }

    public function test_component_threshold_is_unchanged(): void
    {
        $this->assertSame('On Track', InTermStatusService::classify(0));
        $this->assertSame('Needs Attention', InTermStatusService::classify(1));
        $this->assertSame('At Risk', InTermStatusService::classify(2));
        $this->assertSame('At Risk', InTermStatusService::classify(3));
    }

    /**
     * Structural regression net: if a duplicate implementation of this
     * calculation reappears anywhere, this fails, because one of the two
     * dashboards would stop calling the shared method.
     */
    public function test_both_dashboards_reach_the_same_shared_service_method(): void
    {
        // Stubbed (not passthru) — Mockery::mock() never runs the real
        // constructor, so the promoted PerformanceAnalysisService property
        // stays uninitialised; the point here is purely structural (WHICH
        // method each dashboard calls), not the real computation.
        $spy = \Mockery::mock(InTermStatusService::class)->makePartial();
        $spy->shouldReceive('overallStatusForSection')
            ->atLeast()->once()
            ->andReturnUsing(fn($section, $students, $subjects, $term) => $students->map(fn($s) => [
                'student' => $s, 'in_term_status' => null, 'focus_subject' => null,
                'transmuted_grade' => null, 'subjects_evaluated' => 0,
                'subjects_total' => $subjects->count(), 'by_subject' => [],
            ]));
        $this->app->instance(InTermStatusService::class, $spy);
        // DashboardAnalyticsService is resolved with a plain PHP default
        // (`= new DashboardAnalyticsService()`), and Laravel's container
        // only re-resolves a default-valued class parameter through the
        // container when that class itself is "bound" — otherwise it uses
        // the raw `new` expression, bypassing the container (and this
        // spy) entirely for everything DashboardAnalyticsService itself
        // constructs. Binding it (even with no concrete override) forces
        // the container to build it normally, so its own InTermStatusService
        // parameter is resolved from the container too.
        $this->app->bind(DashboardAnalyticsService::class);

        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        Student::factory()->create(['section_id' => $section->id]);
        $principal = User::factory()->principal()->create();

        $this->actingAs($adviser)->get('/adviser/dashboard')->assertOk();
        $spy->shouldHaveReceived('overallStatusForSection')->atLeast()->once();

        $this->actingAs($principal)->get('/principal/dashboard')->assertOk();
        $spy->shouldHaveReceived('overallStatusForSection')->atLeast()->twice();
    }
}
