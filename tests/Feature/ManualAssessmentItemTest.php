<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Add / Edit Assessment — DETAILS ONLY ("Class Record refinements",
 * 2026-09-28; formerly the manual add/edit item endpoints). The definition
 * endpoints create or correct an assessment column and never write a score,
 * a zero or a Missing mark; scores are entered in the Class Record grid.
 */
class ManualAssessmentItemTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;
    use \Tests\Concerns\UsesClassRecord;

    private function setUpSectionWithStudents(int $count = 5): array
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor($section->school_year);
        $students = Student::factory($count)->create(['section_id' => $section->id]);

        return compact('adviser', 'section', 'subject', 'students');
    }

    private function item(Section $section, Subject $subject, User $by, float $max = 20, string $name = 'Quiz 1'): Assessment
    {
        return Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => $name, 'component' => 'written_work', 'max_score' => $max, 'uploaded_by' => $by->id]);
    }

    public function test_saving_an_assessment_creates_the_column_only_with_no_score_zero_or_missing_mark(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents();

        $response = $this->addAssessment($adviser, $section, $subject, ['item_name' => 'Quiz 1', 'max_score' => 20, 'assessment_date' => '2026-07-01']);

        $item = Assessment::where('name', 'Quiz 1')->firstOrFail();
        $response->assertRedirect(route('teacher.class-record', ['school_year' => $section->school_year, 'section_id' => $section->id, 'subject_id' => $subject->id, 'period' => 1]));
        $response->assertSessionHas('success', 'Assessment added. Enter scores in the Class Record.');
        $response->assertSessionHas('new_assessment_id', $item->id);
        $this->assertSame(['written_work', 20.0, $adviser->id, '2026-07-01'], [$item->component, (float) $item->max_score, $item->uploaded_by, $item->assessment_date->format('Y-m-d')]);
        $this->assertSame(0, AssessmentScore::count());
        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertSame(0, AcademicConcern::count(), 'a new column opens no concern');
        $this->assertDatabaseHas('assessment_uploads', ['section_id' => $section->id, 'subject_id' => $subject->id, 'status' => 'manual_entry', 'imported_count' => 0]);

        $this->actingAs($adviser)->get(route('teacher.class-record', ['section_id' => $section->id, 'subject_id' => $subject->id, 'period' => 1]))
            ->assertOk()->assertSee('data-item-head="' . $item->id . '"', false)->assertSee('Assessment added. Enter scores in the Class Record.');
    }

    public function test_the_add_page_has_no_score_or_missing_inputs(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents();

        $html = $this->actingAs($adviser)->get(route('teacher.assessments.create', ['section_id' => $section->id, 'subject_id' => $subject->id, 'period' => 1]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('name="scores[', $html);
        $this->assertStringNotContainsString('name="missing[', $html);
        $this->assertStringContainsString('Highest Possible Score', $html);
        $this->assertStringContainsString('Save Assessment', $html);
        $this->assertStringNotContainsString($students[0]->last_name . ', ' . $students[0]->first_name, $html);
    }

    public function test_score_and_missing_payloads_are_refused_and_nothing_is_created(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents();

        foreach ([['scores' => [$students[0]->id => 10]], ['missing' => [$students[0]->id => '1']], ['scores' => []]] as $extra) {
            $this->addAssessment($adviser, $section, $subject, array_merge(['item_name' => 'Quiz X'], $extra))
                ->assertSessionHasErrors('scores', null, 'addItem');
        }
        $this->actingAs($adviser)->postJson(route('teacher.assessments.store'), [
            'section_id' => $section->id, 'subject_id' => $subject->id, 'grading_period' => 1, 'item_name' => 'Quiz X',
            'component' => 'written_work', 'max_score' => 20, 'scores' => [$students[0]->id => 10],
        ])->assertStatus(422);

        $this->assertSame(0, Assessment::count());
        $this->assertSame(0, AssessmentScore::count());
    }

    public function test_a_repeated_submission_creates_the_assessment_once(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents();
        $token = (string) Str::uuid();

        $this->addAssessment($adviser, $section, $subject, ['item_name' => 'Quiz 1', 'submission_token' => $token])->assertSessionHas('success');
        $this->addAssessment($adviser, $section, $subject, ['item_name' => 'Quiz 1', 'submission_token' => $token])
            ->assertSessionHas('success')->assertSessionHasNoErrors();
        $this->assertSame(1, Assessment::count());

        // Without the same form token, a second item with that name is refused, naming the first.
        $this->addAssessment($adviser, $section, $subject, ['item_name' => 'Quiz 1', 'max_score' => 30])
            ->assertSessionHasErrors('item_name', null, 'addItem');
        $this->assertStringContainsString('already exists', session('errors')->getBag('addItem')->first('item_name'));
        $this->assertSame(1, Assessment::count());
    }

    public function test_the_highest_possible_score_must_be_positive(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents();
        foreach (['0', '-5', 'ten', ''] as $bad) {
            $this->addAssessment($adviser, $section, $subject, ['item_name' => 'Quiz 1', 'max_score' => $bad])->assertSessionHasErrors('max_score', null, 'addItem');
        }
        $this->assertSame(0, Assessment::count());
    }

    public function test_adding_is_blocked_when_the_term_is_closed_or_the_subject_is_out_of_scope(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents();

        AcademicTerm::where('school_year', '2026-2027')->where('term', 2)->update(['is_open' => false]);
        $this->addAssessment($adviser, $section, $subject, ['item_name' => 'Quiz 1'], 2)->assertSessionHasErrors('item_name', null, 'addItem');

        $otherGrade = Subject::factory()->create(['grade_level' => $section->grade_level === 11 ? 12 : 11, 'type' => 'core']);
        $this->addAssessment($adviser, $section, $otherGrade, ['item_name' => 'Quiz 1'])->assertSessionHasErrors('item_name', null, 'addItem');
        $this->assertSame(0, Assessment::count());
    }

    public function test_principal_cannot_add_or_edit_an_assessment(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents();
        $item = $this->item($section, $subject, $adviser);
        $principal = User::factory()->principal()->create();

        $this->addAssessment($principal, $section, $subject, ['item_name' => 'Quiz P'])->assertForbidden();
        $this->editAssessment($principal, $item, ['max_score' => 30])->assertForbidden();
        $this->assertSame(1, Assessment::count());
        $this->assertEquals(20, $item->fresh()->max_score);
    }

    public function test_editing_changes_details_only_and_keeps_every_recorded_score(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents();
        $item = $this->item($section, $subject, $adviser);
        $this->saveCells($adviser, $item, [$students[0]->id => '18', $students[1]->id => '0', $students[2]->id => 'M'])->assertOk();

        $this->editAssessment($adviser, $item, ['max_score' => 25, 'component' => 'performance_task', 'assessment_date' => '2026-07-02'])
            ->assertSessionHas('success');

        $item->refresh();
        $this->assertSame(['performance_task', 25.0, 'Quiz 1', '2026-07-02'], [$item->component, (float) $item->max_score, $item->name, $item->assessment_date->format('Y-m-d')]);
        $this->assertSame(['18.00', '0.00'], AssessmentScore::where('assessment_id', $item->id)->orderBy('student_id')->pluck('score')->map(fn($s) => (string) $s)->all());
        $this->assertSame(1, AssessmentLearnerStatus::where('assessment_id', $item->id)->count());
    }

    public function test_editing_refuses_scores_a_lower_hps_than_a_recorded_score_and_a_new_scope(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'students' => $students] = $this->setUpSectionWithStudents();
        $item = $this->item($section, $subject, $adviser);
        $this->saveCells($adviser, $item, [$students[0]->id => '18'])->assertOk();

        $this->editAssessment($adviser, $item, ['max_score' => 15])->assertSessionHasErrors('max_score', null, 'editItem');
        $this->editAssessment($adviser, $item, ['scores' => [$students[1]->id => 5]])->assertSessionHasErrors('scores', null, 'editItem');
        $other = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $this->editAssessment($adviser, $item, ['subject_id' => $other->id])->assertSessionHasErrors('component', null, 'editItem');
        $this->editAssessment($adviser, $item, ['grading_period' => 2])->assertSessionHasErrors('component', null, 'editItem');
        $this->editAssessment($adviser, $item, ['max_score' => 0])->assertSessionHasErrors('max_score', null, 'editItem');

        $item->refresh();
        $this->assertSame([20.0, $subject->id, 1], [(float) $item->max_score, $item->subject_id, (int) $item->grading_period]);
        $this->assertSame(1, AssessmentScore::where('assessment_id', $item->id)->count());

        // Down to exactly the recorded score is allowed.
        $this->editAssessment($adviser, $item, ['max_score' => 18])->assertSessionHasNoErrors();
        $this->assertEquals(18, $item->fresh()->max_score);
    }

    public function test_a_stale_edit_form_is_refused(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents();
        $item = $this->item($section, $subject, $adviser);
        $opened = ClassRecordService::definitionFingerprint($item);

        $this->editAssessment($adviser, $item, ['max_score' => 30])->assertSessionHasNoErrors(); // another tab saved first
        $this->actingAs($adviser)->put(route('teacher.assessments.update', $item->id), [
            'max_score' => 40, 'component' => 'written_work', 'loaded_fingerprint' => $opened,
        ])->assertSessionHasErrors('component', null, 'editItem');
        $this->assertEquals(30, $item->fresh()->max_score);

        // No fingerprint at all is refused too.
        $this->actingAs($adviser)->put(route('teacher.assessments.update', $item->id), ['max_score' => 40, 'component' => 'written_work'])
            ->assertSessionHasErrors('loaded_fingerprint', null, 'editItem');
    }

    public function test_editing_is_blocked_when_the_term_is_closed(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents();
        $item = $this->item($section, $subject, $adviser);
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        $this->editAssessment($adviser, $item, ['max_score' => 25])->assertSessionHas('error');
        $this->actingAs($adviser)->get(route('teacher.assessments.edit', $item->id))->assertRedirect()->assertSessionHas('error');
        $this->assertEquals(20, $item->fresh()->max_score);
    }

    public function test_another_advisers_section_cannot_edit_this_item(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject] = $this->setUpSectionWithStudents();
        $item = $this->item($section, $subject, $adviser);
        $otherAdviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $otherAdviser->id, 'school_year' => '2026-2027']);

        $this->editAssessment($otherAdviser, $item, ['max_score' => 30])->assertForbidden();
        $this->actingAs($otherAdviser)->get(route('teacher.assessments.edit', $item->id))->assertForbidden();
        $this->assertEquals(20, $item->fresh()->max_score);
    }
}
