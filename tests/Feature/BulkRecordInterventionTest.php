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
 * Task 4 of "close the intervention loop": "Record for all At Risk"
 * reduces the clicks, never the decision — see the ground rule "Never
 * auto-create an intervention." Every intervention created here still
 * has created_by = the Principal who confirmed the dialog, exactly like
 * a single Record Intervention action.
 */
class BulkRecordInterventionTest extends TestCase
{
    use RefreshDatabase;

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned, float $max = 100): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    private function makeAtRiskStudent(Section $section, Subject $subject, string $lastName, string $weakComponent = 'performance_task'): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => $lastName]);
        $this->score($section, $subject, $student, 'written_work', 50);
        $this->score($section, $subject, $student, $weakComponent, 50);
        return $student;
    }

    public function test_students_page_offers_the_bulk_action_when_at_risk_students_exist(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $this->makeAtRiskStudent($section, $subject, 'AtRiskOne');

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        // Renamed by TASK 2 of "subject scoping and bulk threshold" — the
        // trigger no longer means "At Risk only" unconditionally now that
        // the dialog's threshold can be widened.
        $response->assertSee('Record interventions in bulk');
        $response->assertSee('AtRiskOne');
    }

    public function test_bulk_record_creates_one_intervention_per_included_student_with_correct_type_and_created_by(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $studentA = $this->makeAtRiskStudent($section, $subject, 'IncludeMe');
        $studentB = $this->makeAtRiskStudent($section, $subject, 'ExcludeMe');

        // Simulates the Principal unchecking ExcludeMe in the dialog —
        // only IncludeMe is submitted in `included`.
        $response = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [$studentA->id],
            'types'          => [$studentA->id => 'additional_performance_task'],
            'notes'          => 'Batch note',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', [
            'student_id'       => $studentA->id,
            'subject_id'       => $subject->id,
            'recommended_type' => 'additional_performance_task',
            'created_by'       => $principal->id,
            'principal_notes'  => 'Batch note',
            // TASK 2 of "status clarity and progress consistency" — set
            // directly from the type->component mapping storeBulk()
            // already computes, not re-derived from the reason text.
            'focus_component'  => 'performance_task',
        ]);
        $this->assertDatabaseMissing('interventions', ['student_id' => $studentB->id]);
        $this->assertSame(1, Intervention::count());
    }

    public function test_bulk_record_skips_a_student_who_already_has_an_open_intervention(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = $this->makeAtRiskStudent($section, $subject, 'AlreadyHasOne');

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'status' => 'recommended', 'grading_period' => 1,
        ]);

        $response = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [$student->id],
            'types'          => [$student->id => 'remediation'],
        ]);

        $response->assertSessionHas('success');
        // Still just the one pre-existing intervention — nothing new created.
        $this->assertSame(1, Intervention::where('student_id', $student->id)->count());
    }

    /**
     * Superseded by TASK 1 of "bulk dialog and intervention closure": when
     * this student is the ONLY At Risk candidate and already has an open
     * intervention, the dialog no longer lists them individually — it
     * shows one consolidated line and offers no enabled create action at
     * all (a Confirm button with nothing to confirm is exactly the bug
     * that task fixes). The per-row "flagged, not silently dropped" note
     * this test used to check for still applies in the MIXED case — see
     * BulkInterventionDialogTest::test_dialog_shows_live_count_and_enabled_confirm_when_some_are_recordable().
     */
    public function test_the_dialog_shows_a_consolidated_message_when_the_only_at_risk_student_already_has_an_open_intervention(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = $this->makeAtRiskStudent($section, $subject, 'AlreadyOpen');

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'status' => 'recommended', 'grading_period' => 1,
        ]);

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('already has an open intervention for this subject', false);
        $response->assertSee('Go to Interventions');
        $response->assertDontSee('name="included[]"', false);
    }

    public function test_cancelling_the_dialog_writes_nothing(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $this->makeAtRiskStudent($section, $subject, 'NeverSubmitted');

        // "Cancel" in the UI never reaches a route at all — merely loading
        // the page (which builds and renders the dialog) must not create
        // anything.
        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $this->assertSame(0, Intervention::count());
    }

    public function test_adviser_cannot_access_the_bulk_store_route(): void
    {
        $adviser = User::factory()->create();

        $response = $this->actingAs($adviser)->post('/principal/interventions/bulk', [
            'subject_id' => 1, 'grading_period' => 1, 'included' => [1], 'types' => [1 => 'remediation'],
        ]);

        $response->assertForbidden();
    }
}
