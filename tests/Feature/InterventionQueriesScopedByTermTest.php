<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Correctness and interface pass" TASK 1b — every place that checks
 * whether a student already has an "open intervention for this subject"
 * must also check the term, since an intervention belongs to one subject
 * AND one term. Covers the two remaining call sites in
 * Principal\StudentController besides buildBulkInterventionCandidates()
 * (see BulkCandidatesScopedByTermTest for that one):
 * $hasActiveInterventionByStudentId (the "Hide learners with an active
 * intervention" filter) and attachInterventionContext() (the modal's
 * existing_intervention status).
 */
class InterventionQueriesScopedByTermTest extends TestCase
{
    use RefreshDatabase;

    public function test_hide_active_intervention_filter_is_scoped_to_the_selected_term(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Term1OnlyIntervention']);

        // Open intervention exists for TERM 1 only.
        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'status' => 'recommended', 'grading_period' => 1,
        ]);

        // Viewing Term 2 with the filter on: this student has NO open
        // intervention in Term 2, so hiding must not remove them.
        $termTwo = $this->actingAs($principal)->get(
            '/principal/students?subject_id=' . $subject->id . '&period=2&hide_active_intervention=1'
        );
        $termTwo->assertOk();
        $termTwo->assertSee('Term1OnlyIntervention');

        // Viewing Term 1 with the filter on: this student DOES have an
        // open intervention in Term 1, so hiding removes them.
        $termOne = $this->actingAs($principal)->get(
            '/principal/students?subject_id=' . $subject->id . '&period=1&hide_active_intervention=1'
        );
        $termOne->assertOk();
        $termOne->assertDontSee('Term1OnlyIntervention');
    }

    public function test_existing_intervention_status_on_the_modal_is_scoped_to_the_selected_term(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'status' => 'in_progress', 'grading_period' => 1,
        ]);

        // A Term 1 intervention must show as the existing status on the
        // Term 1 page...
        $termOne = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');
        $termOne->assertOk();
        $termOne->assertSee('In progress');

        // ...but must NOT be mistaken for an existing Term 2 intervention —
        // the row should offer the normal Record Intervention action instead.
        $termTwo = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=2');
        $termTwo->assertOk();
        $termTwo->assertSee("onclick='openRecordInterventionModal(", false);
    }
}
