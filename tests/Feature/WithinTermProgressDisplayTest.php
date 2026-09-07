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
 * Task 3 of "close the delivery loop", end to end: record and deliver an
 * intervention, upload an additional assessment item for that subject
 * and term, and confirm the before/after appears with correct item
 * counts on both the adviser and principal Interventions pages.
 */
class WithinTermProgressDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function scoreAt(Assessment $assessment, Student $student, float $score, Carbon $createdAt): void
    {
        $row = AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $score]);
        $row->timestamps = false;
        $row->created_at = $createdAt;
        $row->save();
    }

    public function test_before_after_appears_on_both_pages_after_delivery_and_new_evidence(): void
    {
        $adviser = User::factory()->create();
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level]);

        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        // Weak performance_task evidence BEFORE delivery.
        $pt1 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'PT1', 'component' => 'performance_task', 'max_score' => 20,
        ]);
        $this->scoreAt($pt1, $student, 8, $deliveredAt->copy()->subDay());

        $intervention = Intervention::factory()->decided()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'acknowledged_at' => $deliveredAt, 'acknowledged_by' => $adviser->id,
            'delivered_at' => $deliveredAt, 'delivered_by' => $adviser->id,
            'delivery_notes' => 'Gave an additional performance task.',
            // TASK 2 of "status clarity and progress consistency" — the
            // comparison now anchors to this recorded focus component
            // rather than recomputing "weakest now."
            'focus_component' => 'performance_task',
        ]);

        // Two NEW performance_task items recorded after delivery (this is
        // the "upload an additional assessment item" step from the
        // Verify instructions).
        $pt2 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'PT2', 'component' => 'performance_task', 'max_score' => 20,
        ]);
        $this->scoreAt($pt2, $student, 18, $deliveredAt->copy()->addDay());
        $pt3 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'PT3', 'component' => 'performance_task', 'max_score' => 20,
        ]);
        $this->scoreAt($pt3, $student, 20, $deliveredAt->copy()->addDays(2));

        // TASK 3 of "bulk dialog and intervention closure" — points shown
        // alongside percentages, so a reader can reconcile the arithmetic
        // by hand: before = 8/20 (40.0%); after = (8+18+20)/60 = 46/60 (76.7%).
        $adviserResponse = $this->actingAs($adviser)->get('/adviser/interventions');
        $adviserResponse->assertOk();
        $adviserResponse->assertSee('Performance Task');
        $adviserResponse->assertSee('Before: 8/20 (40.0%), 1 item', false);
        $adviserResponse->assertSee('Now: 46/60 (76.7%), 3 items', false);
        $adviserResponse->assertSee('Change: +36.7 points', false);
        $adviserResponse->assertDontSee('caused', false);
        $adviserResponse->assertSee('added to the term', false);

        $principalResponse = $this->actingAs($principal)->get('/principal/interventions');
        $principalResponse->assertOk();
        $principalResponse->assertSee('Performance Task');
        $principalResponse->assertSee('Before: 8/20 (40.0%), 1 item', false);
        $principalResponse->assertSee('Now: 46/60 (76.7%), 3 items', false);
        $principalResponse->assertSee('Change: +36.7 points', false);
        $principalResponse->assertSee('Gave an additional performance task.');
        $principalResponse->assertSee('added to the term', false);
    }

    public function test_no_comparison_shown_before_delivery(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertSee('No within-term comparison yet');
    }

    /**
     * TASK 2 of "status clarity and progress consistency" — the exact
     * scenario named in that task's prompt: an intervention recorded
     * with Focus Area Examination must still report EXAMINATION progress
     * after Written Work evidence is added (never silently switching to
     * whichever component looks weakest), and the mismatch must be
     * visible on the page rather than left for the reader to notice.
     */
    public function test_examination_focus_survives_new_written_work_evidence_and_shows_the_mismatch_note(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level]);

        $deliveredAt = Carbon::parse('2026-09-01 12:00:00');

        $exam1 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Exam1', 'component' => 'examination', 'max_score' => 20,
        ]);
        $this->scoreAt($exam1, $student, 8, $deliveredAt->copy()->subDay());

        $intervention = Intervention::factory()->decided()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'recommended_type' => 'remediation',
            'recommendation_reason' => 'Recorded in bulk — Focus Area: Examination (In-Term Status: At Risk).',
            'focus_component' => 'examination',
            'acknowledged_at' => $deliveredAt, 'acknowledged_by' => $adviser->id,
            'delivered_at' => $deliveredAt, 'delivered_by' => $adviser->id,
            'delivery_notes' => 'Remedial exam session.',
        ]);

        // New evidence lands in WRITTEN WORK after delivery — a
        // different, and now numerically weaker, component than the
        // intervention's own recorded focus.
        $ww = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'WW1', 'component' => 'written_work', 'max_score' => 20,
        ]);
        $this->scoreAt($ww, $student, 4, $deliveredAt->copy()->addDay()); // 20% — weaker than Examination's 40%

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        // Still Examination, never Written Work.
        $response->assertSee('Examination');
        $response->assertSee('Before: 8/20 (40.0%), 1 item', false);
        // The mismatch note names both components.
        $response->assertSee('New evidence was also added in Written Work since delivery', false);
        $response->assertSee('this intervention is about Examination', false);
    }

    public function test_focus_component_not_recorded_is_shown_explicitly_rather_than_a_guess(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        Intervention::factory()->decided()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'recommended_type' => 'parent_conference',
            'recommendation_reason' => 'Classified High Risk overall. A parent/guardian conference is recommended.',
            'focus_component' => null,
            'acknowledged_at' => now(), 'acknowledged_by' => $adviser->id,
            'delivered_at' => now(), 'delivered_by' => $adviser->id,
            'delivery_notes' => 'Held the conference.',
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertSee('Focus component not recorded');
    }
}
