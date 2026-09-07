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
use Tests\TestCase;

/**
 * TASK 1 of "bulk dialog and intervention closure" — the "Record for all
 * At Risk" dialog must never offer an enabled Confirm button with nothing
 * to confirm. See resources/views/principal/students.blade.php's
 * $recordableCount branch and Principal\InterventionController::storeBulk().
 */
class BulkInterventionDialogTest extends TestCase
{
    use RefreshDatabase;

    private function makeAtRiskStudent(Section $section, Subject $subject, string $lastName): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => $lastName]);

        foreach (['written_work', 'performance_task'] as $component) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
                'school_year' => $section->school_year, 'name' => $component . '-' . uniqid(),
                'component' => $component, 'max_score' => 20,
            ]);
            // 40% — below the 75 target on two components -> At Risk.
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 8]);
        }

        return $student;
    }

    public function test_dialog_offers_no_confirm_action_when_every_candidate_is_skipped(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $student = $this->makeAtRiskStudent($section, $subject, 'AllSkipped');
        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'status' => 'recommended', 'grading_period' => 1,
        ]);

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('already', false);
        $response->assertSee('an open intervention for this subject', false);
        $response->assertSee('Go to Interventions');
        // The list of checkboxes is replaced entirely — no create action left.
        $response->assertDontSee('name="included[]"', false);
        $response->assertDontSee('Confirm and Record');
    }

    public function test_dialog_shows_live_count_and_enabled_confirm_when_some_are_recordable(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $skipped = $this->makeAtRiskStudent($section, $subject, 'Skipped');
        Intervention::factory()->create([
            'student_id' => $skipped->id, 'subject_id' => $subject->id, 'status' => 'recommended', 'grading_period' => 1,
        ]);
        $this->makeAtRiskStudent($section, $subject, 'Recordable');

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        // TASK 2 of "subject scoping and bulk threshold" — the summary now
        // reports three figures (matches/skipped/recorded) for the
        // currently-selected threshold, not two.
        $response->assertSee('2 match, 1 skipped', false);
        $response->assertSee('Record 1 intervention', false);
        $response->assertSee('name="included[]"', false);
    }

    public function test_post_with_empty_selection_creates_nothing_and_returns_a_validation_error(): void
    {
        $principal = User::factory()->principal()->create();
        $subject = Subject::factory()->create();

        $response = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [],
            'types'          => [],
        ]);

        $response->assertSessionHasErrors('included');
        $response->assertSessionMissing('success');
        $this->assertSame(0, Intervention::count());
    }
}
