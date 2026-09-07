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
 * "The FAILING layer" Hard Constraint 1: adding the Failing signal must
 * not replace, weaken, or gate In-Term Status. A student with one
 * component below target must still surface as Needs Attention even
 * when their OFFICIAL grade is passing (well above 74) — the two
 * signals answer different questions and must stay fully independent.
 */
class InTermStatusUnaffectedByFailingTest extends TestCase
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

    public function test_one_component_below_target_still_returns_needs_attention_with_a_passing_official_grade(): void
    {
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $adviser = User::factory()->create();

        $this->score($section, $subject, $student, 'written_work', 90);
        $this->score($section, $subject, $student, 'performance_task', 60); // below 75 target
        $this->score($section, $subject, $student, 'examination', 90);

        // A verified, non-provisional, comfortably-passing OFFICIAL grade —
        // the opposite of Failing.
        $grade = Grade::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => $section->school_year,
            'grade' => 90.0, 'is_verified' => true, 'is_provisional' => false,
        ]);

        $service = new InTermStatusService();
        $result = $service->statusFor($student, $subject, $section, 1, $section->school_year);

        $this->assertSame('Needs Attention', $result['status']);
        $this->assertFalse(InTermStatusService::isFailing($grade), 'This grade must not be Failing — it is the contrast case.');
    }
}
