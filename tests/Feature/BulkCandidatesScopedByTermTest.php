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
 * "Correctness and interface pass" TASK 1 — BUG FIX: an intervention
 * belongs to one subject AND one term. The existing-intervention guard
 * used to check (student, subject) only, so an open Term 1 intervention
 * wrongly blocked recording a NEW one for the same student/subject in
 * Term 2 and Term 3 forever. See
 * Principal\StudentController::buildBulkInterventionCandidates() and the
 * other queries this task fixed in the same controller and
 * Principal\InterventionController::store()/storeBulk().
 */
class BulkCandidatesScopedByTermTest extends TestCase
{
    use RefreshDatabase;

    private function score(Section $section, Subject $subject, Student $student, int $term, string $component, float $earned): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => $term, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    private function makeAtRiskStudentInTerm(Section $section, Subject $subject, Student $student, int $term): void
    {
        $this->score($section, $subject, $student, $term, 'written_work', 40);
        $this->score($section, $subject, $student, $term, 'performance_task', 40);
    }

    public function test_an_open_term_1_intervention_does_not_block_recording_in_term_2(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'CarriesOver']);

        $this->makeAtRiskStudentInTerm($section, $subject, $student, 1);
        $this->makeAtRiskStudentInTerm($section, $subject, $student, 2);

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'status' => 'recommended', 'grading_period' => 1,
        ]);

        // Term 2's page must NOT report this student as already skipped.
        $termTwoPage = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=2');
        $termTwoPage->assertOk();
        $termTwoPage->assertDontSee('already has an open intervention for this subject in Term 2', false);

        $response = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 2,
            'included'       => [$student->id],
            'types'          => [$student->id => 'additional_performance_task'],
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(2, Intervention::where('student_id', $student->id)->count(), 'Term 1 and Term 2 interventions must both exist, independently.');
        $this->assertDatabaseHas('interventions', ['student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1]);
        $this->assertDatabaseHas('interventions', ['student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 2]);
    }

    public function test_the_same_student_recorded_in_term_2_is_then_skipped_in_term_2_only_not_term_3(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->makeAtRiskStudentInTerm($section, $subject, $student, 2);
        $this->makeAtRiskStudentInTerm($section, $subject, $student, 3);

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'status' => 'recommended', 'grading_period' => 2,
        ]);

        // Attempting to record again in Term 2 must skip (still open there).
        $response = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 2,
            'included'       => [$student->id],
            'types'          => [$student->id => 'additional_performance_task'],
        ]);
        $response->assertSessionHas('success');
        $this->assertSame(1, Intervention::where('student_id', $student->id)->where('grading_period', 2)->count());

        // Term 3 is a different term entirely and must still be recordable.
        $termThree = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 3,
            'included'       => [$student->id],
            'types'          => [$student->id => 'additional_performance_task'],
        ]);
        $termThree->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', ['student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 3]);
    }

    public function test_single_record_intervention_route_is_also_scoped_by_term(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'status' => 'recommended', 'grading_period' => 1,
        ]);

        $response = $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 2,
            'recommended_type' => 'remediation',
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(2, Intervention::where('student_id', $student->id)->count());
    }
}
