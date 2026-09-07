<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Clarity, progress, and visual design pass" TASK 2c — a Principal
 * decision-view state distinct from both "Ready to Close" (the student
 * already reached On Track) and "nothing happened": the intervention's
 * focus component genuinely rose since delivery, but the student has not
 * reached On Track yet. Before this task these two cases looked
 * identical — this is purely a read signal (see
 * Principal\InterventionController::isImprovedButBelowTarget()) and never
 * changes anything itself.
 */
class WithinTermProgressTest extends TestCase
{
    use RefreshDatabase;

    private function scoreAt(Assessment $assessment, Student $student, float $score, Carbon $createdAt): void
    {
        $row = AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $score]);
        $row->timestamps = false;
        $row->created_at = $createdAt;
        $row->save();
    }

    public function test_improved_but_below_target_is_shown_when_the_focus_component_rose_but_the_student_is_not_on_track(): void
    {
        $adviser = User::factory()->create();
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level]);

        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        // Written Work / Performance Task already fine; Examination weak.
        $ww = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'WW1', 'component' => 'written_work', 'max_score' => 20,
        ]);
        $this->scoreAt($ww, $student, 18, $deliveredAt->copy()->subDay());
        $pt = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'PT1', 'component' => 'performance_task', 'max_score' => 20,
        ]);
        $this->scoreAt($pt, $student, 18, $deliveredAt->copy()->subDay());
        $exam1 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Exam1', 'component' => 'examination', 'max_score' => 20,
        ]);
        $this->scoreAt($exam1, $student, 8, $deliveredAt->copy()->subDay()); // 40% — below the 75 target

        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved',
            'focus_component' => 'examination',
            'acknowledged_at' => $deliveredAt, 'acknowledged_by' => $adviser->id,
            'delivered_at' => $deliveredAt, 'delivered_by' => $adviser->id,
            'delivery_notes' => 'Gave remedial examination practice.',
        ]);

        // Two NEW examination items after delivery — real improvement
        // (40% -> 60%), but still under the 75 target.
        $exam2 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Exam2', 'component' => 'examination', 'max_score' => 20,
        ]);
        $this->scoreAt($exam2, $student, 14, $deliveredAt->copy()->addDay());
        $exam3 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Exam3', 'component' => 'examination', 'max_score' => 20,
        ]);
        $this->scoreAt($exam3, $student, 14, $deliveredAt->copy()->addDays(2));
        // Examination now: (8+14+14)/60 = 60.0% — improved, still below 75.

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertSee('Improved, still below target', false);
        $response->assertDontSee('is now On Track', false);
    }

    public function test_improved_but_below_target_is_absent_when_there_is_no_new_evidence_yet(): void
    {
        $adviser = User::factory()->create();
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level]);

        $exam1 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Exam1', 'component' => 'examination', 'max_score' => 20,
        ]);
        $this->scoreAt($exam1, $student, 8, now()->subDay());

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved',
            'focus_component' => 'examination',
            'acknowledged_at' => now(), 'acknowledged_by' => $adviser->id,
            'delivered_at' => now(), 'delivered_by' => $adviser->id,
            'delivery_notes' => 'Gave remedial examination practice.',
        ]);

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertDontSee('Improved, still below target', false);
    }
}
