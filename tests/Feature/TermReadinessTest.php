<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\TermReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Extends CLAUDE.md's Term Readiness check (previously grade-encoding
 * only) with assessment-evidence completeness — "Final grade
 * calculation available" from its checklist. Deliberately informational,
 * never a submission blocker (see TermReadinessService's doc comment).
 */
class TermReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_no_evidence_when_nothing_has_been_uploaded(): void
    {
        $section = Section::factory()->create();
        Student::factory()->create(['section_id' => $section->id]);
        Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        $status = (new TermReadinessService())->assessmentEvidenceStatus($section, 1);

        $this->assertFalse($status['has_any_evidence']);
        $this->assertSame(0, $status['complete']);
    }

    public function test_reports_complete_evidence_counts_matching_the_claude_md_example_shape(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $studentA = Student::factory()->create(['section_id' => $section->id]);
        $studentB = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            // Only studentA gets fully scored — studentB is incomplete.
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $studentA->id, 'score' => 90]);
        }

        $status = (new TermReadinessService())->assessmentEvidenceStatus($section, 1);

        $this->assertTrue($status['has_any_evidence']);
        $this->assertSame(2, $status['expected']); // 2 students x 1 subject
        $this->assertSame(1, $status['complete']);
        $this->assertSame(1, $status['incomplete']);
        $this->assertFalse($status['ready']);
    }

    public function test_submit_report_page_shows_assessment_evidence_readiness_without_blocking_submission(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        \App\Models\AcademicTerm::ensureExistFor('2026-2027');
        \App\Models\Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'grade' => 85, 'school_year' => '2026-2027',
        ]);

        // No assessment evidence uploaded at all — submission must still work.
        $response = $this->actingAs($adviser)->get('/adviser/submit-report');
        $response->assertOk();
        $response->assertDontSee('assessment evidence complete');

        $submitResponse = $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);
        $submitResponse->assertSessionMissing('error');
        $this->assertDatabaseHas('report_submissions', ['section_id' => $section->id, 'grading_period' => 1]);
    }
}
