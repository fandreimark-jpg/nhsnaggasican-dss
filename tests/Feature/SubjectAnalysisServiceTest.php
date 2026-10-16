<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\SubjectAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_no_assessment_evidence_exists(): void
    {
        Subject::factory()->create();

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $this->assertSame([], $summaries);
    }

    public function test_aggregates_component_averages_across_multiple_students(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['name' => 'Statistics']);
        $studentA = Student::factory()->create(['section_id' => $section->id]);
        $studentB = Student::factory()->create(['section_id' => $section->id]);

        $quiz = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $quiz->id, 'student_id' => $studentA->id, 'score' => 90]);
        AssessmentScore::factory()->create(['assessment_id' => $quiz->id, 'student_id' => $studentB->id, 'score' => 60]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $this->assertCount(1, $summaries);
        $this->assertSame('Statistics', $summaries[0]['subject']->name);
        $this->assertEquals(75.0, $summaries[0]['components']['written_work']['avg_percentage']); // (90+60)/2
        $this->assertSame(2, $summaries[0]['components']['written_work']['student_count']);
        $this->assertNull($summaries[0]['components']['performance_task']);
        $this->assertSame('written_work', $summaries[0]['weakest_component']);
    }

    public function test_identifies_the_weakest_component_across_all_three(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work' => 90, 'performance_task' => 55, 'examination' => 80] as $component => $score) {
            $a = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => $score]);
        }

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $this->assertSame('performance_task', $summaries[0]['weakest_component']);
        $this->assertSame('Needs Attention', $summaries[0]['components']['performance_task']['status']);
        $this->assertSame('On Track', $summaries[0]['components']['written_work']['status']);
    }

    public function test_defaults_to_the_active_school_year_when_none_given(): void
    {
        $section = Section::factory()->create(['school_year' => '2027-2028']);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $a = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2027-2028',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => 80]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries();

        $this->assertCount(1, $summaries);
    }

    /**
     * SYSTEM_FIXES_AND_ML_AUDIT.md, "Subject Analysis" -- failure_rate
     * reads Grade::scopeFailing(), the same official grade <= 74 rule the
     * dashboard's own Failing tile uses, not a second copy of the rule.
     */
    public function test_failure_rate_counts_verified_non_provisional_failing_grades(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create();

        Grade::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'school_year' => '2026-2027',
            'grade' => 90, 'is_verified' => true, 'is_provisional' => false,
        ]);
        Grade::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'school_year' => '2026-2027',
            'grade' => 60, 'is_verified' => true, 'is_provisional' => false, // failing: <= 74
        ]);
        // Not verified yet -- must not count either way.
        Grade::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'school_year' => '2026-2027',
            'grade' => 50, 'is_verified' => false, 'is_provisional' => false,
        ]);
        // Provisional -- excluded, same as InTermStatusService::isFailing().
        Grade::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'school_year' => '2026-2027',
            'grade' => 40, 'is_verified' => true, 'is_provisional' => true,
        ]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $row = collect($summaries)->firstWhere('subject.id', $subject->id);
        $this->assertSame(2, $row['graded_count']);
        $this->assertSame(1, $row['failing_count']);
        $this->assertEquals(50.0, $row['failure_rate']);
    }

    public function test_a_subject_with_no_grades_yet_reports_a_null_failure_rate(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $a = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => 80]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $this->assertNull($summaries[0]['failure_rate']);
        $this->assertSame(0, $summaries[0]['graded_count']);
    }

    /**
     * at_risk_count reads the same "latest risk result per student this
     * school year" rows DashboardAnalyticsService::getSummaryData() uses,
     * counting a student under whichever subject is their weakest_subject_id,
     * moderate/high only.
     */
    public function test_open_concern_counts_come_from_open_academic_concerns_in_this_subject_and_scope(): void
    {
        $adviser = \App\Models\User::factory()->create(['role' => 'adviser']);
        $section = Section::factory()->create(['school_year' => '2026-2027', 'adviser_id' => $adviser->id]);
        $otherSection = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create();
        $quietSubject = Subject::factory()->create();
        [$ana, $ben, $cara, $dan] = Student::factory()->count(4)->create(['section_id' => $section->id])->all();
        $elsewhere = Student::factory()->create(['section_id' => $otherSection->id]);

        $item = fn(Section $s, string $name, int $term = 1) => Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $s->id, 'grading_period' => $term, 'school_year' => '2026-2027',
            'name' => $name, 'component' => 'written_work', 'max_score' => 20,
        ]);
        $quiz1 = $item($section, 'Quiz 1');
        $quiz2 = $item($section, 'Quiz 2');
        $term2Quiz = $item($section, 'Term 2 Quiz', 2);
        $otherQuiz = $item($otherSection, 'Quiz 1');

        $statuses = new \App\Services\AssessmentStatusService();
        $statuses->markMissing($quiz1, $ana->id, $adviser->id);   // Ana: 2 open concerns
        $statuses->markMissing($quiz2, $ana->id, $adviser->id);
        $statuses->markMissing($quiz1, $ben->id, $adviser->id);   // Ben: 1 open concern
        $statuses->markMissing($quiz1, $cara->id, $adviser->id);  // Cara: resolved below
        $statuses->markMissing($term2Quiz, $dan->id, $adviser->id);   // other term
        $statuses->markMissing($otherQuiz, $elsewhere->id, $adviser->id); // other section
        $monitoring = app(\App\Services\AcademicMonitoringService::class);
        $monitoring->synchronizeAssessments([$quiz1->id, $quiz2->id, $term2Quiz->id, $otherQuiz->id]);
        $statuses->recordScore($quiz1, $cara->id, 0); // a real 0 is evidence: Cara's concern resolves
        $monitoring->synchronizeAssessments([$quiz1->id]);

        // A legacy classifier row counts for nothing.
        RiskResult::create([
            'student_id' => $ben->id, 'grading_period' => 1, 'average_grade' => 60,
            'risk_level' => 'high', 'weakest_subject_id' => $quietSubject->id, 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $service = new SubjectAnalysisService();
        $row = fn(array $summaries, Subject $s) => collect($summaries)->firstWhere('subject.id', $s->id);

        // Whole school year: 5 open (Ana 2, Ben 1, Dan 1, elsewhere 1) across 4 learners.
        $all = $row($service->getSubjectSummaries('2026-2027'), $subject);
        $this->assertSame(5, $all['open_concern_count']);
        $this->assertSame(4, $all['open_concern_learners']);
        $this->assertNull($row($service->getSubjectSummaries('2026-2027'), $quietSubject), 'a legacy risk row does not make a subject appear');

        // One section, one term: Ana's 2 + Ben's 1.
        $scoped = $row($service->getSubjectSummaries('2026-2027', $section->id, 1), $subject);
        $this->assertSame(3, $scoped['open_concern_count']);
        $this->assertSame(2, $scoped['open_concern_learners']);
    }

    public function test_section_filter_narrows_the_summary_to_one_section(): void
    {
        $sectionA = Section::factory()->create(['school_year' => '2026-2027']);
        $sectionB = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create();
        $studentA = Student::factory()->create(['section_id' => $sectionA->id]);
        $studentB = Student::factory()->create(['section_id' => $sectionB->id]);

        $aInA = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $sectionA->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $aInA->id, 'student_id' => $studentA->id, 'score' => 90]);

        $aInB = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $sectionB->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $aInB->id, 'student_id' => $studentB->id, 'score' => 40]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027', $sectionA->id);

        $this->assertSame(90.0, $summaries[0]['components']['written_work']['avg_percentage']);
        $this->assertSame(1, $summaries[0]['components']['written_work']['student_count']);
    }

    public function test_term_filter_narrows_the_summary_to_one_grading_period(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        $term1 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $term1->id, 'student_id' => $student->id, 'score' => 90]);

        $term2 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 2, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $term2->id, 'student_id' => $student->id, 'score' => 40]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027', null, 1);

        $this->assertSame(90.0, $summaries[0]['components']['written_work']['avg_percentage']);
    }
}
