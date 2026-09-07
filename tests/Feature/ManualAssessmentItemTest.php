<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 1 and TASK 3 of "add an assessment item by hand" — the manual
 * entry path alongside the file upload (AssessmentUploadWorkflowTest
 * covers that one). Ground rule: manual entry must produce IDENTICAL
 * Assessment/AssessmentScore rows to the file path — same updateOrCreate
 * keys, same audit trail (see AdviserAssessmentController::storeItem()/
 * updateItem()).
 */
class ManualAssessmentItemTest extends TestCase
{
    use RefreshDatabase;

    private function setUpSectionWithStudents(int $count = 10): array
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor($section->school_year);

        $students = Student::factory($count)->create(['section_id' => $section->id]);

        return compact('adviser', 'section', 'subject', 'students');
    }

    public function test_adding_an_item_for_two_of_ten_students_creates_exactly_two_scores_and_leaves_the_rest_untouched(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents(10);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'item_name'      => 'Remedial Quiz 1',
            'component'      => 'written_work',
            'max_score'      => 20,
            'scores'         => [
                $students[0]->id => 18,
                $students[1]->id => 15,
            ],
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $assessment = Assessment::where('name', 'Remedial Quiz 1')->first();
        $this->assertNotNull($assessment);
        $this->assertSame('written_work', $assessment->component);
        $this->assertEquals(20, $assessment->max_score);
        $this->assertSame($adviser->id, $assessment->uploaded_by);

        $this->assertSame(2, AssessmentScore::where('assessment_id', $assessment->id)->count());
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $assessment->id, 'student_id' => $students[0]->id, 'score' => 18]);
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $assessment->id, 'student_id' => $students[1]->id, 'score' => 15]);

        // The other 8 students never had a score row created for them at all.
        for ($i = 2; $i < 10; $i++) {
            $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $assessment->id, 'student_id' => $students[$i]->id]);
        }

        // An audit trail row exists, distinguishing manual entry from a file.
        $this->assertDatabaseHas('assessment_uploads', [
            'section_id' => $section->id, 'subject_id' => $subject->id, 'status' => 'manual_entry', 'imported_count' => 2,
        ]);

        // Appears in the item list exactly like an imported one would.
        $listResponse = $this->actingAs($adviser)->get('/adviser/assessments?subject_id=' . $subject->id . '&period=1');
        $listResponse->assertSee('Remedial Quiz 1');
    }

    public function test_a_blank_score_field_is_not_taken_never_a_zero(): void
    {
        ['adviser' => $adviser, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents(3);

        $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'item_name'      => 'Remedial Quiz',
            'component'      => 'performance_task',
            'max_score'      => 10,
            'scores'         => [
                $students[0]->id => 8,
                $students[1]->id => '', // explicitly left blank
                // $students[2] omitted entirely
            ],
        ])->assertSessionHas('success');

        $assessment = Assessment::where('name', 'Remedial Quiz')->first();
        $this->assertSame(1, AssessmentScore::where('assessment_id', $assessment->id)->count());
        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $assessment->id, 'student_id' => $students[1]->id]);
        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $assessment->id, 'student_id' => $students[2]->id]);
    }

    public function test_duplicate_item_name_is_blocked_and_names_the_existing_items_max_score_and_component(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents(2);

        Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => 'Quiz 1', 'component' => 'examination', 'max_score' => 50,
        ]);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'item_name'      => 'Quiz 1',
            'component'      => 'written_work',
            'max_score'      => 20,
            'scores'         => [],
        ]);

        $response->assertSessionHasErrors('item_name', null, 'addItem');
        $errors = session('errors')->getBag('addItem');
        $this->assertStringContainsString('Examination', $errors->first('item_name'));
        $this->assertStringContainsString('50.00', $errors->first('item_name'));
        $this->assertSame(1, Assessment::where('name', 'Quiz 1')->count());
    }

    public function test_a_score_exceeding_the_max_is_rejected_and_names_the_student(): void
    {
        ['adviser' => $adviser, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents(2);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'item_name'      => 'Quiz X',
            'component'      => 'written_work',
            'max_score'      => 10,
            'scores'         => [$students[0]->id => 15],
        ]);

        $response->assertSessionHasErrors('scores', null, 'addItem');
        $this->assertStringContainsString($students[0]->last_name, session('errors')->getBag('addItem')->first('scores'));
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_a_non_numeric_score_is_rejected(): void
    {
        ['adviser' => $adviser, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents(1);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'item_name'      => 'Quiz X',
            'component'      => 'written_work',
            'max_score'      => 10,
            'scores'         => [$students[0]->id => 'abc'],
        ]);

        $response->assertSessionHasErrors('scores', null, 'addItem');
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_suspicious_max_shows_a_non_blocking_warning_and_still_saves(): void
    {
        ['adviser' => $adviser, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents(3);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'item_name'      => 'Quiz X',
            'component'      => 'written_work',
            'max_score'      => 100,
            'scores'         => [
                $students[0]->id => 20,
                $students[1]->id => 25,
                $students[2]->id => 22,
            ],
        ]);

        $response->assertSessionHas('warning');
        $response->assertSessionMissing('success');
        $this->assertDatabaseHas('assessments', ['name' => 'Quiz X', 'max_score' => 100]);
        $this->assertSame(3, AssessmentScore::whereHas('assessment', fn($q) => $q->where('name', 'Quiz X'))->count());
    }

    public function test_manual_entry_is_blocked_when_the_term_is_closed(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents(1);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => $subject->id,
            'grading_period' => 2, // term 2 is closed by default (only term 1 opens via ensureExistFor)
            'item_name'      => 'Quiz X',
            'component'      => 'written_work',
            'max_score'      => 10,
            'scores'         => [$students[0]->id => 5],
        ]);

        $response->assertSessionHasErrors('item_name', null, 'addItem');
        $this->assertStringContainsString('closed', session('errors')->getBag('addItem')->first('item_name'));
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_adviser_cannot_add_an_item_to_a_subject_outside_their_sections_scope(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'students' => $students] = $this->setUpSectionWithStudents(1);
        // A core subject at a DIFFERENT grade level than the adviser's own
        // section — guaranteed out of scope regardless of which grade the
        // section factory randomly picked (see SectionFactory).
        $foreignSubject = Subject::factory()->create(['grade_level' => $section->grade_level === 11 ? 12 : 11, 'type' => 'core']);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => $foreignSubject->id,
            'grading_period' => 1,
            'item_name'      => 'Quiz X',
            'component'      => 'written_work',
            'max_score'      => 10,
            'scores'         => [],
        ]);

        $response->assertSessionHasErrors('item_name', null, 'addItem');
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_principal_cannot_add_an_assessment_item(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->post('/adviser/assessments/item', [
            'subject_id' => 1, 'grading_period' => 1, 'item_name' => 'x', 'component' => 'written_work', 'max_score' => 10,
        ]);

        $response->assertForbidden();
    }

    // =============================================
    // TASK 3 — editing an existing item
    // =============================================

    public function test_editing_the_max_score_recomputes_and_reports_before_after_percentages(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents(2);

        // Wrong max score typed on purpose (should have been 20, typed 40).
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 40,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $students[0]->id, 'score' => 18]); // 45% under wrong max
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $students[1]->id, 'score' => 20]); // 50% under wrong max

        $response = $this->actingAs($adviser)->put('/adviser/assessments/item/' . $assessment->id, [
            'grading_period' => 1,
            'max_score'      => 20, // corrected
            'scores'         => [
                $students[0]->id => 18,
                $students[1]->id => 20,
            ],
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionHas('edit_summary');

        $assessment->refresh();
        $this->assertEquals(20, $assessment->max_score);
        $this->assertSame('Quiz 1', $assessment->name); // unchanged
        $this->assertSame('written_work', $assessment->component); // unchanged

        $summary = collect(session('edit_summary'))->keyBy('name');
        $before1 = $summary->get($students[0]->last_name . ', ' . $students[0]->first_name)['before'];
        $after1 = $summary->get($students[0]->last_name . ', ' . $students[0]->first_name)['after'];
        $this->assertEqualsWithDelta(45.0, $before1, 0.01);
        $this->assertEqualsWithDelta(90.0, $after1, 0.01);

        $after2 = $summary->get($students[1]->last_name . ', ' . $students[1]->first_name)['after'];
        $this->assertEqualsWithDelta(100.0, $after2, 0.01);
    }

    public function test_editing_cannot_change_component_or_name_even_if_posted(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents(1);

        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20,
        ]);

        $this->actingAs($adviser)->put('/adviser/assessments/item/' . $assessment->id, [
            'grading_period' => 1,
            'max_score'      => 25,
            // A tampered/extra request cannot change these — the
            // controller never reads them from the request at all.
            'name'           => 'Renamed Item',
            'component'      => 'examination',
            'scores'         => [],
        ])->assertSessionHas('success');

        $assessment->refresh();
        $this->assertSame('Quiz 1', $assessment->name);
        $this->assertSame('written_work', $assessment->component);
        $this->assertEquals(25, $assessment->max_score);
    }

    public function test_clearing_a_score_on_edit_removes_the_row_rather_than_storing_a_zero(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents(1);

        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $students[0]->id, 'score' => 15]);

        $this->actingAs($adviser)->put('/adviser/assessments/item/' . $assessment->id, [
            'grading_period' => 1,
            'max_score'      => 20,
            'scores'         => [$students[0]->id => ''],
        ])->assertSessionHas('success');

        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $assessment->id, 'student_id' => $students[0]->id]);
    }

    public function test_editing_is_blocked_when_the_term_is_closed(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents(1);

        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 2,
            'school_year' => $section->school_year, 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20,
        ]);

        $response = $this->actingAs($adviser)->put('/adviser/assessments/item/' . $assessment->id, [
            'grading_period' => 2, 'max_score' => 25, 'scores' => [],
        ]);

        $response->assertSessionHas('error');
        $assessment->refresh();
        $this->assertEquals(20, $assessment->max_score);
    }

    public function test_another_advisers_section_cannot_edit_this_item(): void
    {
        ['section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents(1);
        $otherAdviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $otherAdviser->id]);

        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20,
        ]);

        $response = $this->actingAs($otherAdviser)->put('/adviser/assessments/item/' . $assessment->id, [
            'grading_period' => 1, 'max_score' => 25, 'scores' => [],
        ]);

        $response->assertForbidden();
    }
}
