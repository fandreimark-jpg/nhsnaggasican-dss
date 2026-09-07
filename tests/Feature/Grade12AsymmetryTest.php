<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The FAILING layer" TASK 5 — encodes the Grade 11/Grade 12 asymmetry
 * as an executable assertion. Under DO 8, s. 2015 (Grade 12's scheme),
 * a computed grade of 62 transmutes to a PASSING reported grade (well
 * above 74), so it is never Failing — but the same raw mastery still
 * has one component below the flat 75% target, so In-Term Status must
 * still surface it. This is exactly why the component-based In-Term
 * Status rule must not be replaced by the Failing threshold — see
 * CLAUDE.md's "Known limitations" for the full reasoning.
 */
class Grade12AsymmetryTest extends TestCase
{
    use RefreshDatabase;

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned, float $max = 100, int $term = 1): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => $term, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_a_grade_12_student_with_a_computed_grade_of_62_is_not_failing_but_is_surfaced_by_in_term_status(): void
    {
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $adviser = User::factory()->create();

        // WW=90 (On Track), PT=62 (below the flat 75% target -> Needs
        // Attention), Exam=90 (On Track). Weighted (25/50/25): 90*.25 +
        // 62*.50 + 90*.25 = 22.5 + 31 + 22.5 = 76.0 computed — still
        // one component below target, so In-Term Status must flag it.
        $this->score($section, $subject, $student, 'written_work', 90);
        $this->score($section, $subject, $student, 'performance_task', 62);
        $this->score($section, $subject, $student, 'examination', 90);

        $status = (new InTermStatusService())->statusFor($student, $subject, $section, 1, $section->school_year);
        $this->assertSame('Needs Attention', $status['status'], 'One component below the flat 75% target must still surface, regardless of the reported grade.');

        // do8_2015: an Initial Grade of 60.00 transmutes to 75 (the
        // passing anchor) — so a raw score comfortably above 60 reports
        // well above 74 and is never Failing.
        $grade = Grade::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => $section->school_year,
            'grade' => 88.0, 'computed_grade' => $status['computed_grade'],
            'is_verified' => true, 'is_provisional' => false,
        ]);

        $this->assertFalse(InTermStatusService::isFailing($grade), 'A Grade 12 learner with this raw mastery reports as passing under do8_2015 and must never be Failing.');
    }
}
