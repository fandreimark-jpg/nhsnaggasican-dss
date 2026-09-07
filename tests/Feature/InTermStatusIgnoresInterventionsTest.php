<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Clarity, progress, and visual design pass" TASK 4c — In-Term Status is
 * a calculation over CURRENT assessment evidence, never a record of what
 * was done about it (see CLAUDE.md, INTERVENTION section: "the status
 * clears on its own when new evidence lifts the learner's components
 * above target, which is the honest mechanism and is already working").
 * Creating, deciding, acknowledging, delivering, and closing an
 * intervention must never by itself change the status — only new
 * assessment evidence can.
 */
class InTermStatusIgnoresInterventionsTest extends TestCase
{
    use RefreshDatabase;

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_status_is_unchanged_across_the_full_intervention_lifecycle(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        // WW=84 (on track), PT=60, Exam=70 (both below the 75 target) -> At Risk.
        $this->score($section, $subject, $student, 'written_work', 84);
        $this->score($section, $subject, $student, 'performance_task', 60);
        $this->score($section, $subject, $student, 'examination', 70);

        $service = new InTermStatusService();
        $before = $service->statusFor($student, $subject, $section, 1, $section->school_year);
        $this->assertSame('At Risk', $before['status']);

        $intervention = Intervention::factory()->create([
            'student_id'     => $student->id,
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'status'         => 'recommended',
        ]);
        $this->assertSame('At Risk', $service->statusFor($student, $subject, $section, 1, $section->school_year)['status']);

        $intervention->update(['status' => 'approved', 'decided_by' => $adviser->id, 'decided_at' => now()]);
        $this->assertSame('At Risk', $service->statusFor($student, $subject, $section, 1, $section->school_year)['status']);

        $intervention->update(['acknowledged_at' => now(), 'acknowledged_by' => $adviser->id]);
        $this->assertSame('At Risk', $service->statusFor($student, $subject, $section, 1, $section->school_year)['status']);

        $intervention->update(['delivered_at' => now(), 'delivered_by' => $adviser->id, 'delivery_notes' => 'Gave extra support during class.']);
        $this->assertSame('At Risk', $service->statusFor($student, $subject, $section, 1, $section->school_year)['status']);

        $intervention->update(['status' => 'completed']);
        $this->assertSame(
            'At Risk',
            $service->statusFor($student, $subject, $section, 1, $section->school_year)['status'],
            'Closing the intervention must not itself change In-Term Status — only new assessment evidence can.'
        );

        // Confirm the mechanism really IS still live: it's new assessment
        // evidence — not the intervention above — that moves the status.
        $this->score($section, $subject, $student, 'performance_task', 95);
        $this->score($section, $subject, $student, 'performance_task', 95);
        $this->assertNotSame(
            'At Risk',
            $service->statusFor($student, $subject, $section, 1, $section->school_year)['status'],
            'New assessment evidence, not the intervention lifecycle, is what should move the status.'
        );
    }
}
