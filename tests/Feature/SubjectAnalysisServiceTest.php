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
    public function test_at_risk_count_reflects_students_whose_weakest_subject_is_this_one(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();
        $studentHigh = Student::factory()->create(['section_id' => $section->id]);
        $studentLow = Student::factory()->create(['section_id' => $section->id]);
        $studentOtherWeakest = Student::factory()->create(['section_id' => $section->id]);

        RiskResult::create([
            'student_id' => $studentHigh->id, 'grading_period' => 1, 'average_grade' => 60,
            'risk_level' => 'high', 'weakest_subject_id' => $subject->id, 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);
        RiskResult::create([
            'student_id' => $studentLow->id, 'grading_period' => 1, 'average_grade' => 95,
            'risk_level' => 'low', 'weakest_subject_id' => $subject->id, 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);
        RiskResult::create([
            'student_id' => $studentOtherWeakest->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => 'high', 'weakest_subject_id' => $otherSubject->id, 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $row = collect($summaries)->firstWhere('subject.id', $subject->id);
        // Only studentHigh counts: low risk doesn't count, and the third
        // student's weakest subject is the OTHER subject.
        $this->assertSame(1, $row['at_risk_count']);
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
