<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The FAILING layer" TASK 3 — the bulk dialog's third threshold. Failing
 * is a genuine third bucket, not a combination of the other two:
 * selecting it must record ONLY students where isFailing() is true, the
 * trigger reason must say Failing (never 'At Risk' for a Failing-only
 * student), and the existing-open-intervention skip must still apply.
 */
class BulkFailingThresholdTest extends TestCase
{
    use RefreshDatabase;

    private function makeFailingOnTrackStudent(Section $section, Subject $subject, string $lastName): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => $lastName]);
        $adviser = User::factory()->create();

        // On Track in-term (every component comfortably above target) but
        // a verified, non-provisional OFFICIAL grade of 60 — Failing.
        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $assessment = \App\Models\Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
                'school_year' => $section->school_year, 'name' => $component . '-' . uniqid(),
                'component' => $component, 'max_score' => 100,
            ]);
            \App\Models\AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 95]);
        }

        Grade::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => $section->school_year,
            'grade' => 60.0, 'is_verified' => true, 'is_provisional' => false,
        ]);

        return $student;
    }

    public function test_selecting_failing_threshold_records_only_failing_students_with_the_correct_trigger_reason(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        $failingStudent = $this->makeFailingOnTrackStudent($section, $subject, 'FailingOnly');

        $response = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [$failingStudent->id],
            'types'          => [$failingStudent->id => 'remediation'],
            'statuses'       => [$failingStudent->id => 'Failing'],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', ['student_id' => $failingStudent->id, 'subject_id' => $subject->id]);

        $intervention = Intervention::where('student_id', $failingStudent->id)->first();
        $this->assertStringContainsString('Failing', $intervention->recommendation_reason);
        $this->assertStringNotContainsString('At Risk', $intervention->recommendation_reason);
    }

    public function test_the_students_page_bulk_candidates_include_a_failing_on_track_student(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        $this->makeFailingOnTrackStudent($section, $subject, 'FailingRow');

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');
        $response->assertOk();

        // The dialog's radio set now offers a Failing-only option, and the
        // Failing-on-track student appears in the candidate list (present
        // in the DOM even though hidden by the default At-Risk-only view).
        $response->assertSee('Failing only (official grade 74 and below)', false);
        $response->assertSee('data-status="failing"', false);
        $response->assertSee('FailingRow', false);
    }

    public function test_a_failing_student_with_an_existing_open_intervention_is_still_skipped(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        $failingStudent = $this->makeFailingOnTrackStudent($section, $subject, 'AlreadyOpen');
        Intervention::factory()->create([
            'student_id' => $failingStudent->id, 'subject_id' => $subject->id, 'status' => 'recommended', 'grading_period' => 1,
        ]);

        $response = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [$failingStudent->id],
            'types'          => [$failingStudent->id => 'remediation'],
            'statuses'       => [$failingStudent->id => 'Failing'],
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(1, Intervention::where('student_id', $failingStudent->id)->count(), 'A student with an existing open intervention must be skipped, not duplicated.');
    }
}
