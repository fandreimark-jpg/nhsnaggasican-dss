<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AssessmentStatusService;
use App\Services\ExaminationRoles;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Examination Type (assessments.exam_role) on the manual Add / Edit item
 * forms, and its identity across manual entry and uploads.
 *
 *  - Only an Examination item carries a type; DO 015 subjects need one of
 *    the roles their profile uses (all three, or the catalog's subset);
 *    DO 8 (k12_2013) takes none; a profile with no Examination takes no
 *    Examination item at all.
 *  - One REGULAR item per type per subject/section/term/year, identified
 *    by exam_role, never by the item name — so a manual "Summative Test 1"
 *    and an uploaded "ST1" column are the same ST1.
 */
class ExaminationTypeTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;

    private User $adviser;
    private Section $section;
    private Subject $subject;
    private Student $ana;
    private Student $ben;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        $this->subject = Subject::factory()->create(['name' => 'General Science', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id, 'lrn' => '110000000001']);
        $this->ben = Student::factory()->create(['section_id' => $this->section->id, 'lrn' => '110000000002']);
    }

    /** Add Assessment — details only (teacher.assessments.store). */
    private function add(array $fields, ?Subject $subject = null, int $term = 1, ?User $as = null)
    {
        $subject ??= $this->subject;

        return $this->actingAs($as ?? $this->adviser)
            ->from(route('teacher.assessments.create', ['section_id' => $this->section->id, 'period' => $term, 'subject_id' => $subject->id]))
            ->post(route('teacher.assessments.store'), $fields + [
                'section_id' => $this->section->id, 'subject_id' => $subject->id, 'grading_period' => $term, 'max_score' => 30,
            ]);
    }

    /** Edit Assessment — details only (teacher.assessments.update), opened just now. */
    private function edit(Assessment $item, array $fields)
    {
        return $this->actingAs($this->adviser)
            ->from(route('teacher.assessments.edit', $item))
            ->put(route('teacher.assessments.update', $item), $fields + [
                'max_score' => (float) $item->max_score,
                'loaded_fingerprint' => \App\Services\ClassRecordService::definitionFingerprint($item),
            ]);
    }

    private function item(string $name): Assessment
    {
        return Assessment::where('name', $name)->sole();
    }

    // --- 1-6 Add -----------------------------------------------------------------

    public function test_1_2_written_work_and_performance_task_carry_no_type(): void
    {
        $this->add(['item_name' => 'Quiz 1', 'component' => 'written_work'])->assertSessionHas('success');
        $this->add(['item_name' => 'Project', 'component' => 'performance_task'])->assertSessionHas('success');

        $this->assertNull($this->item('Quiz 1')->exam_role);
        $this->assertSame('written_work', $this->item('Quiz 1')->component);
        $this->assertNull($this->item('Project')->exam_role);
    }

    public function test_3_each_examination_type_persists_its_canonical_value(): void
    {
        foreach (ExaminationRoles::ITEM_NAMES as $role => $name) {
            $this->add(['item_name' => $name, 'component' => 'examination', 'exam_role' => $role])->assertSessionHas('success');
            $this->assertSame($role, $this->item($name)->exam_role);
        }
        $this->assertSame(['st1', 'st2', 'term_exam'], ExaminationRoles::ROLES);
    }

    public function test_4_5_6_invalid_misplaced_or_missing_types_are_refused(): void
    {
        $this->add(['item_name' => 'Exam', 'component' => 'examination', 'exam_role' => 'st9'])->assertSessionHasErrors('exam_role', null, 'addItem');
        $this->add(['item_name' => 'Quiz 1', 'component' => 'written_work', 'exam_role' => 'st1'])
            ->assertSessionHasErrors(['exam_role' => 'Examination Type applies only to Examination items — choose Examination as the component, or leave the type empty.'], null, 'addItem');
        $this->add(['item_name' => 'Exam', 'component' => 'examination'])
            ->assertSessionHasErrors('exam_role', null, 'addItem');

        $this->assertSame(0, Assessment::count(), 'nothing half-created');
    }

    public function test_the_type_choices_follow_the_subject_grading_profile(): void
    {
        // DO 8 (explicit k12_2013): one flat Examination — a type is refused, none is fine.
        $do8 = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027', 'curriculum' => 'k12_2013']);
        $do8Subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        $this->assertSame(['has_examination' => true, 'roles' => []], (new ExaminationRoles())->forContext($do8, $do8Subject));

        // A profile with no Examination component takes no Examination item.
        $immersion = Subject::factory()->create(['name' => 'Work Immersion', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'work_immersion']);
        $this->assertFalse((new ExaminationRoles())->forContext($this->section, $immersion)['has_examination']);
        $this->add(['item_name' => 'Exam', 'component' => 'examination', 'exam_role' => 'term_exam'], $immersion)
            ->assertSessionHasErrors('component', null, 'addItem');
        $this->actingAs($this->adviser)->get(route('teacher.assessments.create', ['section_id' => $this->section->id, 'period' => 1, 'subject_id' => $immersion->id]))
            ->assertOk()->assertSee("Examination — not in this subject's grading profile", false);

        // DO 015: all three roles.
        $this->assertSame(ExaminationRoles::ROLES, (new ExaminationRoles())->forContext($this->section, $this->subject)['roles']);
    }

    // --- 7-8 one regular item per type -------------------------------------------

    public function test_7_8_a_second_st1_is_refused_but_other_contexts_and_support_items_are_not(): void
    {
        $this->add(['item_name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1'])->assertSessionHas('success');

        // Same scope, different NAME — still the same logical ST1.
        $this->add(['item_name' => 'First Summative', 'component' => 'examination', 'exam_role' => 'st1'])
            ->assertSessionHasErrors('exam_role', null, 'addItem');
        $this->assertStringContainsString('already has a ST1 — Summative Test 1 for Term 1: "Summative Test 1"', session('errors')->getBag('addItem')->first('exam_role'));

        // Another subject, another term: no false conflict.
        $other = Subject::factory()->create(['name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->add(['item_name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1'], $other)->assertSessionHas('success');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 2)->update(['is_open' => true]);
        $this->add(['item_name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1'], null, 2)->assertSessionHas('success');

        // A within-term additional-support exam may share the role (the engine sums it into ST1).
        $this->add(['item_name' => 'ST1 Re-take', 'component' => 'examination', 'exam_role' => 'st1', 'is_additional_support' => '1'])->assertSessionHas('success');
        $this->assertSame(4, Assessment::where('exam_role', 'st1')->count());
    }

    // --- 9-13 Edit -----------------------------------------------------------------

    public function test_9_the_edit_payload_carries_the_current_type_and_the_page_uses_the_ecr_wording(): void
    {
        $this->add(['item_name' => 'Summative Test 2', 'component' => 'examination', 'exam_role' => 'st2']);

        // The full-page Edit form (it replaced the modal) opens on the item's
        // CURRENT type; the Add form uses the ECR wording and names the taken type.
        $edit = $this->actingAs($this->adviser)->get(route('teacher.assessments.edit', $this->item('Summative Test 2')->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="st2"[^>]*selected/', $edit);
        $this->assertStringContainsString('ST2 — Summative Test 2', $edit);

        $html = $this->actingAs($this->adviser)->get(route('teacher.assessments.create', ['section_id' => $this->section->id, 'period' => 1, 'subject_id' => $this->subject->id]))->assertOk()->getContent();
        $this->assertStringContainsString('ST2 — Summative Test 2', $html);
        $this->assertStringContainsString('already added (Summative Test 2)', $html);
        $this->assertStringContainsString('placeholder="e.g. Quiz 1, Activity 1, Performance Task 1"', $html);
        $this->assertStringNotContainsString('e.g. Remedial Quiz 1', $html);
    }

    public function test_10_11_12_13_edit_changes_type_and_component_in_place_with_the_same_rules(): void
    {
        $this->add(['item_name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1']);
        $this->add(['item_name' => 'Unit Test', 'component' => 'examination', 'exam_role' => 'st2']);
        $this->add(['item_name' => 'Quiz 1', 'component' => 'written_work']);
        $unit = $this->item('Unit Test');

        // 11: to a type another regular item holds — refused, unchanged.
        $this->edit($unit, ['component' => 'examination', 'exam_role' => 'st1'])->assertSessionHasErrors('exam_role', null, 'editItem');
        $this->assertSame('st2', $unit->fresh()->exam_role);

        // 10: to a free type — applied to this same item.
        $this->edit($unit, ['component' => 'examination', 'exam_role' => 'term_exam'])->assertSessionHas('success');
        $this->assertSame('term_exam', $unit->fresh()->exam_role);

        // 12: out of Examination — the type is cleared.
        $this->edit($unit, ['component' => 'performance_task'])->assertSessionHas('success');
        $this->assertSame(['performance_task', null], [$unit->fresh()->component, $unit->fresh()->exam_role]);

        // 13: into Examination — a type is required.
        $quiz = $this->item('Quiz 1');
        $this->edit($quiz, ['component' => 'examination'])->assertSessionHasErrors('exam_role', null, 'editItem');
        $this->assertSame('written_work', $quiz->fresh()->component);
        $this->edit($quiz, ['component' => 'examination', 'exam_role' => 'st2'])->assertSessionHas('success');
        $this->assertSame(['examination', 'st2', 'Quiz 1'], [$quiz->fresh()->component, $quiz->fresh()->exam_role, $quiz->fresh()->name]);
        $this->assertSame(3, Assessment::count(), 'every change was made in place');
    }

    public function test_a_legacy_item_with_no_type_can_still_have_its_scores_entered(): void
    {
        $legacy = Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Old Exam', 'component' => 'examination', 'exam_role' => null, 'max_score' => 50,
        ]);

        // Scores go through the Class Record; the item's missing type is not a blocker there.
        $this->saveCells($this->adviser, $legacy, [$this->ana->id => '40'])->assertOk();
        // Saving its details unchanged is not a classification change, so it is allowed too.
        $this->edit($legacy, ['component' => 'examination', 'exam_role' => ''])->assertSessionHas('success');
        $this->assertNull($legacy->fresh()->exam_role, 'never auto-mapped');
        $this->assertEquals(40, AssessmentScore::where('assessment_id', $legacy->id)->value('score'));
    }

    public function test_14_to_17_blank_zero_missing_semantics_are_unchanged_for_examination_items(): void
    {
        $this->add(['item_name' => 'Term Exam', 'component' => 'examination', 'exam_role' => 'term_exam'])->assertSessionHas('success');
        $exam = $this->item('Term Exam');
        $this->saveCells($this->adviser, $exam, [$this->ana->id => '0', $this->ben->id => ''])->assertOk();

        $this->assertSame(1, AssessmentLearnerStatus::count(), '14: a blank saved with the assessment becomes Missing (automatic)');
        $statuses = (new AssessmentStatusService())->statusesFor(collect([$exam]), [$this->ana->id, $this->ben->id]);
        $this->assertSame(AssessmentStatusService::COMPLETED, $statuses[$exam->id][$this->ana->id], '15: 0 is Completed, never Missing');
        $this->assertSame(AssessmentStatusService::MISSING, $statuses[$exam->id][$this->ben->id]);
        $this->assertSame(1, AcademicConcern::open()->where('student_id', $this->ben->id)->count(), '16: the saved Missing opens a concern');

        $this->saveCells($this->adviser, $exam, [$this->ben->id => 'M'])->assertOk()->assertJson(['changed' => 0]);
        $this->assertSame(1, AcademicConcern::open()->where('student_id', $this->ben->id)->count(), 'saving it again opens no second concern');

        // 17: a score and Missing together for one learner is refused.
        $this->actingAs($this->adviser)->postJson(route('teacher.class-record.save'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $exam->id, 'student_id' => $this->ben->id, 'score' => '20', 'missing' => true, 'base_score' => null, 'base_missing' => true]],
        ])->assertStatus(422);
    }

    public function test_25_26_a_closed_term_and_another_adviser_are_refused(): void
    {
        $this->add(['item_name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1'], null, 2)
            ->assertSessionHasErrors('item_name', null, 'addItem');

        $this->add(['item_name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1']);
        $other = User::factory()->create(['role' => 'adviser']);
        Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11, 'adviser_id' => $other->id]);
        $this->actingAs($other)->put(route('teacher.assessments.update', $this->item('Summative Test 1')), [
            'max_score' => 30, 'component' => 'examination', 'exam_role' => 'st2',
            'loaded_fingerprint' => \App\Services\ClassRecordService::definitionFingerprint($this->item('Summative Test 1')),
        ])->assertForbidden();
        $this->assertSame('st1', $this->item('Summative Test 1')->exam_role);

        $principal = User::factory()->principal()->create();
        $this->actingAs($principal)->post(route('teacher.assessments.store'), ['subject_id' => $this->subject->id])->assertForbidden();
    }

    public function test_27_an_exam_grades_by_its_role_not_its_name(): void
    {
        // ST1 30 / ST2 30 / TE 40 shares, equal split fallback when no row is seeded — whichever
        // the engine uses, manual and imported items must produce the same grade.
        foreach (['Summative Test 1' => ['st1', 24], 'Summative Test 2' => ['st2', 27], 'Term Exam' => ['term_exam', 32]] as $name => [$role, $score]) {
            $this->add(['item_name' => $name, 'component' => 'examination', 'exam_role' => $role, 'max_score' => 40])->assertSessionHas('success');
            $this->saveCells($this->adviser, $this->item($name), [$this->ana->id => (string) $score])->assertOk();
        }
        $manual = (new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027')['components']['examination'];

        foreach (Assessment::all() as $item) {
            $item->update(['name' => $item->name . ' (imported)']); // a different name, same roles and scores
        }
        GradingEngine::invalidateEvidence();
        $imported = (new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027')['components']['examination'];

        $this->assertNotNull($manual);
        $this->assertEquals($manual, $imported, 'identity is the role, not the name');
    }
}
