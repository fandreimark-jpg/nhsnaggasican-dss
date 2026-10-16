<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentEvidenceAudit;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\Track;
use App\Models\User;
use App\Services\AssessmentStatusService;
use App\Services\ClassRecordExport;
use App\Services\ClassRecordService;
use App\Services\GradingEngine;
use App\Services\StudentEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\TestCase;

/**
 * "Subject Teacher and Class Record" pass (2026-09-28) — test of record.
 *
 * Scenario (the work order's own example): Teacher A advises Agila and
 * teaches CESC there; Teacher B (a Subject Teacher) teaches Mathematics in
 * Agila. A reads every Agila subject but edits only CESC; B edits only
 * Mathematics and reads nothing else.
 */
class SubjectTeacherClassRecordTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    private User $admin;
    private User $principal;
    private User $teacherA;
    private User $teacherB;
    private Section $agila;
    private Section $other;
    private Subject $cesc;
    private Subject $math;
    private Subject $science;
    private Student $ana;
    private Student $ben;
    private Student $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->admin = User::factory()->admin()->create();
        $this->principal = User::factory()->principal()->create();
        $this->teacherA = User::factory()->create(['role' => 'adviser', 'email' => 'teacher.a@x.test']);
        $this->teacherB = User::factory()->create(['role' => 'subject_teacher', 'email' => 'teacher.b@x.test']);

        $track = Track::factory()->create(['code' => 'ACAD']);
        $this->agila = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'track_id' => $track->id, 'adviser_id' => $this->teacherA->id]);
        $this->other = Section::factory()->create(['name' => 'Other', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'track_id' => $track->id, 'adviser_id' => null]);

        foreach (['cesc' => 'Community Engagement', 'math' => 'General Mathematics', 'science' => 'General Science'] as $prop => $name) {
            $this->{$prop} = Subject::factory()->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
            $this->{$prop}->syncTerms([1, 2, 3]);
        }

        $this->ana = Student::factory()->create(['section_id' => $this->agila->id, 'last_name' => 'ABAD', 'first_name' => 'ANA', 'lrn' => '012345678901', 'gender' => 'female']);
        $this->ben = Student::factory()->create(['section_id' => $this->agila->id, 'last_name' => 'BAUTISTA', 'first_name' => 'BEN', 'lrn' => '012345678902', 'gender' => 'female']);
        $this->outsider = Student::factory()->create(['section_id' => $this->other->id, 'last_name' => 'CRUZ', 'first_name' => 'CARLO', 'lrn' => '012345678903']);

        $this->assign($this->teacherA, $this->agila, $this->cesc);
        $this->assign($this->teacherB, $this->agila, $this->math);
    }

    // --- helpers -----------------------------------------------------------------

    private function assign(User $teacher, Section $section, Subject $subject, int $term = 1): TeachingAssignment
    {
        return TeachingAssignment::create(['user_id' => $teacher->id, 'section_id' => $section->id, 'subject_id' => $subject->id,
            'school_year' => $section->school_year, 'grading_period' => $term]);
    }

    private function item(Subject $subject, string $name = 'Quiz 1', float $max = 20, string $component = 'written_work', ?Section $section = null): Assessment
    {
        $section ??= $this->agila;

        return Assessment::create(['subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => $name, 'assessment_type' => $name, 'component' => $component, 'max_score' => $max, 'uploaded_by' => $this->admin->id]);
    }

    private function cell(Assessment $a, Student $s, ?string $score, bool $missing = false, ?string $base = null, bool $baseMissing = false): array
    {
        return ['assessment_id' => $a->id, 'student_id' => $s->id, 'score' => $score, 'missing' => $missing, 'base_score' => $base, 'base_missing' => $baseMissing];
    }

    private function save(User $user, Subject $subject, array $cells, ?Section $section = null)
    {
        return $this->actingAs($user)->postJson(route('teacher.class-record.save'), [
            'section_id' => ($section ?? $this->agila)->id, 'subject_id' => $subject->id, 'period' => 1, 'cells' => $cells,
        ]);
    }

    private function score(Assessment $a, Student $s): ?string
    {
        $v = AssessmentScore::where('assessment_id', $a->id)->where('student_id', $s->id)->value('score');

        return $v === null ? null : (string) $v;
    }

    // --- authorization ------------------------------------------------------------

    public function test_adviser_reads_every_advisory_subject_but_edits_only_the_assigned_one(): void
    {
        $record = $this->actingAs($this->teacherA)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->math->id, 'period' => 1]));
        $record->assertOk()->assertViewHas('canEdit', false)->assertSee('View only');
        $record->assertViewHas('subjects', fn($s) => $s->pluck('id')->sort()->values()->all() === collect([$this->cesc->id, $this->math->id, $this->science->id])->sort()->values()->all());

        $this->actingAs($this->teacherA)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))
            ->assertOk()->assertViewHas('canEdit', true);

        $mathItem = $this->item($this->math);
        $this->save($this->teacherA, $this->math, [$this->cell($mathItem, $this->ana, '15')])->assertStatus(403);
        $this->assertNull($this->score($mathItem, $this->ana));

        $cescItem = $this->item($this->cesc);
        $this->save($this->teacherA, $this->cesc, [$this->cell($cescItem, $this->ana, '15')])->assertOk();
        $this->assertSame('15.00', $this->score($cescItem, $this->ana));
    }

    public function test_subject_teacher_edits_only_their_subject_and_cannot_read_unrelated_ones(): void
    {
        $mathItem = $this->item($this->math);
        $cescItem = $this->item($this->cesc);

        $this->save($this->teacherB, $this->math, [$this->cell($mathItem, $this->ben, '12')])->assertOk();
        $this->assertSame('12.00', $this->score($mathItem, $this->ben));

        // CESC in the same section, same learners: not readable, not editable, not exportable.
        $this->actingAs($this->teacherB)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))->assertForbidden();
        $this->actingAs($this->teacherB)->get(route('teacher.class-record.export', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))->assertForbidden();
        $this->actingAs($this->teacherB)->get(route('teacher.class-record.copy', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))->assertForbidden();
        $this->save($this->teacherB, $this->cesc, [$this->cell($cescItem, $this->ana, '10')])->assertStatus(403);
        $this->editAssessment($this->teacherB, $cescItem, ['max_score' => 30])->assertForbidden();
        $this->actingAs($this->teacherB)->get(route('teacher.assessments.edit', $cescItem->id))->assertForbidden();
        $this->actingAs($this->teacherB)->get(route('teacher.assessments.history', $cescItem->id))->assertForbidden();
        $this->assertEquals(20, $cescItem->fresh()->max_score);

        // A section the teacher has no assignment in at all.
        $this->actingAs($this->teacherB)->get(route('teacher.class-record', ['section_id' => $this->other->id]))->assertForbidden();

        // The subject list offered is only the assigned subject.
        $this->actingAs($this->teacherB)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'period' => 1]))
            ->assertOk()->assertViewHas('subjects', fn($s) => $s->pluck('id')->all() === [$this->math->id]);

        // No master data, no Principal decisions, no advisory pages.
        $this->actingAs($this->teacherB)->post(route('admin.students.store'), ['lrn' => '999999999999', 'last_name' => 'X', 'first_name' => 'Y', 'gender' => 'male'])->assertForbidden();
        $this->actingAs($this->teacherB)->get(route('adviser.advisory-overview'))->assertForbidden();
        $this->actingAs($this->teacherB)->post(route('principal.interventions.store'), [])->assertForbidden();
    }

    public function test_forged_cells_learners_and_definition_requests_are_refused_without_writing(): void
    {
        $mathItem = $this->item($this->math);
        $otherSectionItem = $this->item($this->math, 'Quiz 1', 20, 'written_work', $this->other);

        // An item from another section smuggled into an authorized batch: nothing saved.
        $this->save($this->teacherB, $this->math, [$this->cell($mathItem, $this->ana, '10'), $this->cell($otherSectionItem, $this->outsider, '10')])
            ->assertStatus(422)->assertJsonPath('errors.' . $otherSectionItem->id . ':' . $this->outsider->id, 'This assessment is not part of this class record.');
        // A learner from another section on an authorized item.
        $this->save($this->teacherB, $this->math, [$this->cell($mathItem, $this->outsider, '10')])->assertStatus(422);
        $this->assertSame(0, AssessmentScore::count());

        // Add Assessment for an unassigned subject in a readable section: refused.
        $this->addAssessment($this->teacherB, $this->agila, $this->cesc, ['item_name' => 'Forged'])->assertSessionHasErrors('item_name', null, 'addItem');
        $this->assertStringContainsString('not assigned to teach', session('errors')->getBag('addItem')->first('item_name'));
        // A section the teacher cannot read at all is a 403.
        $this->addAssessment($this->teacherB, $this->other, $this->math, ['item_name' => 'X'])->assertForbidden();
        $this->assertSame(0, AssessmentScore::count());
        $this->assertSame(2, Assessment::count());
    }

    public function test_principal_and_admin_do_not_encode_scores(): void
    {
        $mathItem = $this->item($this->math);
        $this->save($this->principal, $this->math, [$this->cell($mathItem, $this->ana, '10')])->assertForbidden();
        $this->save($this->admin, $this->math, [$this->cell($mathItem, $this->ana, '10')])->assertForbidden();
        $this->actingAs($this->principal)->get(route('teacher.class-record'))->assertForbidden();
        $this->assertSame(0, AssessmentScore::count());
    }

    public function test_a_subject_teacher_signs_in_through_the_real_login_and_lands_on_my_classes(): void
    {
        $this->post('/login', ['email' => $this->teacherB->email, 'password' => 'password'])->assertRedirect(route('teacher.classes'));
        $this->get(route('teacher.classes'))->assertOk()->assertSee('General Mathematics')->assertDontSee('Community Engagement');
        $this->post('/logout');

        $this->teacherB->is_active = false;
        $this->teacherB->save();
        $this->post('/login', ['email' => $this->teacherB->email, 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_add_assessment_is_details_only_and_the_grid_records_zero_blank_and_missing_distinctly(): void
    {
        $this->actingAs($this->teacherA)->get(route('teacher.assessments.create', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))
            ->assertOk()->assertSee('Highest Possible Score')->assertDontSee('name="missing[', false)->assertDontSee('name="scores[', false);

        $token = (string) \Illuminate\Support\Str::uuid();
        $payload = ['submission_token' => $token, 'item_name' => 'Reflection Paper', 'component' => 'performance_task',
            'assessment_date' => '2026-07-15', 'max_score' => 25];
        $response = $this->addAssessment($this->teacherA, $this->agila, $this->cesc, $payload);
        $item = Assessment::where('name', 'Reflection Paper')->firstOrFail();
        $response->assertRedirect(route('teacher.class-record', ['school_year' => '2026-2027', 'section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]));
        $response->assertSessionHas('new_assessment_id', $item->id)->assertSessionHas('success', 'Assessment added. Enter scores in the Class Record.');
        $this->assertSame('2026-07-15', $item->assessment_date->format('Y-m-d'));
        $this->assertSame(0, AssessmentScore::count());
        $this->assertSame(0, AcademicConcern::count(), 'creating a column marks nobody Missing');

        // The same form submitted again (double click / resend) creates nothing new.
        $this->addAssessment($this->teacherA, $this->agila, $this->cesc, $payload)->assertSessionHas('success');
        $this->assertSame(1, Assessment::where('name', 'Reflection Paper')->count());

        // Scores are entered in the grid.
        $this->saveCells($this->teacherA, $item, [$this->ana->id => '0', $this->ben->id => 'M'])->assertOk();
        $statuses = new AssessmentStatusService();
        $this->assertSame('0.00', $this->score($item, $this->ana));
        $this->assertSame(AssessmentStatusService::COMPLETED, $statuses->statusFor($item, $this->ana->id));
        $this->assertSame(AssessmentStatusService::MISSING, $statuses->statusFor($item, $this->ben->id));
        $this->assertSame(1, AcademicConcern::open()->where('student_id', $this->ben->id)->count(), 'explicit Missing opened the Missing Requirement concern');
        $this->assertSame(0, AcademicConcern::open()->where('student_id', $this->ana->id)->count(), 'a recorded 0 is Completed, never a concern');

        $this->actingAs($this->teacherA)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))
            ->assertOk()->assertSee('Reflection Paper')->assertSee('data-item-head="' . $item->id . '"', false);
    }

    public function test_grid_rejects_invalid_scores_and_contradictions_and_add_rejects_a_bad_hps(): void
    {
        $quiz = $this->item($this->cesc, 'Quiz 9', 10);
        foreach (['11', '-1', 'ten'] as $bad) {
            $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, $bad)])->assertStatus(422);
        }
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, '5', true)])->assertStatus(422);
        $this->addAssessment($this->teacherA, $this->agila, $this->cesc, ['item_name' => 'Quiz 10', 'max_score' => '0'])
            ->assertSessionHasErrors('max_score', null, 'addItem');
        $this->assertSame(1, Assessment::count());
        $this->assertSame(0, AssessmentScore::count());
    }

    public function test_batch_save_is_all_or_nothing_and_keeps_blank_zero_and_missing_distinct(): void
    {
        $quiz = $this->item($this->cesc, 'Quiz 1', 20);
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->ana->id, 'score' => 18]);

        // One invalid cell (above HPS) blocks the valid one too.
        $this->save($this->teacherA, $this->cesc, [
            $this->cell($quiz, $this->ben, '12'),
            $this->cell($quiz, $this->ana, '25', false, '18'),
        ])->assertStatus(422)->assertJsonStructure(['errors' => [$quiz->id . ':' . $this->ana->id]]);
        $this->assertNull($this->score($quiz, $this->ben));
        $this->assertSame('18.00', $this->score($quiz, $this->ana));

        // Clear Ana (row removed, never a 0 — the saved blank becomes Missing
        // automatically, 2026-10-04) and Ben 0 (a zero is never Missing).
        $this->save($this->teacherA, $this->cesc, [
            $this->cell($quiz, $this->ana, null, false, '18'),
            $this->cell($quiz, $this->ben, '0'),
        ])->assertOk()->assertJsonPath('changed', 2);
        $statuses = new AssessmentStatusService();
        $this->assertSame(AssessmentStatusService::MISSING, $statuses->statusFor($quiz, $this->ana->id));
        $this->assertSame('0.00', $this->score($quiz, $this->ben));
        $this->assertSame(AssessmentStatusService::COMPLETED, $statuses->statusFor($quiz, $this->ben->id));

        // Saving it as Missing again changes nothing.
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, null, true, null, true)])->assertOk()->assertJsonPath('changed', 0);
        $this->assertSame(AssessmentStatusService::MISSING, $statuses->statusFor($quiz, $this->ana->id));
        $this->assertSame(1, AcademicConcern::open()->where('student_id', $this->ana->id)->count());

        // A score and Missing together is refused.
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ben, '5', true, '0')])->assertStatus(422);

        // Correcting the evidence resolves the concern (review/delivery never do).
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, '14', false, null, true)])->assertOk();
        $this->assertSame(0, AcademicConcern::open()->where('student_id', $this->ana->id)->count());
        $this->assertSame(1, AcademicConcern::resolved()->where('student_id', $this->ana->id)->count(), 'the resolved concern is kept as history');
    }

    public function test_a_stale_cell_is_refused_and_nothing_is_overwritten(): void
    {
        $quiz = $this->item($this->cesc, 'Quiz 1', 20);
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->ana->id, 'score' => 10]);

        // Another tab changed Ana to 16 after this page loaded it as 10.
        AssessmentScore::where('assessment_id', $quiz->id)->where('student_id', $this->ana->id)->first()->update(['score' => 16]);

        $this->save($this->teacherA, $this->cesc, [
            $this->cell($quiz, $this->ana, '12', false, '10'),
            $this->cell($quiz, $this->ben, '9'),
        ])->assertStatus(409)->assertJsonPath('conflicts.' . $quiz->id . ':' . $this->ana->id . '.current_score', '16');
        $this->assertSame('16.00', $this->score($quiz, $this->ana));
        $this->assertNull($this->score($quiz, $this->ben), 'the whole batch rolled back');

        // Re-based on the current value (the explicit "keep mine"), it saves.
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, '12', false, '16')])->assertOk();
        $this->assertSame('12.00', $this->score($quiz, $this->ana));
    }

    public function test_closed_terms_and_historical_years_refuse_writes(): void
    {
        $quiz = $this->item($this->cesc);
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, '10')])->assertStatus(423);
        $this->actingAs($this->teacherA)->get(route('teacher.assessments.create', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->teacherA)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))
            ->assertOk()->assertViewHas('canEdit', false);
        $this->assertSame(0, AssessmentScore::count());
    }

    public function test_edit_revalidates_scores_against_a_lowered_hps_and_refuses_a_stale_form(): void
    {
        $quiz = $this->item($this->cesc, 'Quiz 1', 20);
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->ana->id, 'score' => 18]);

        $this->editAssessment($this->teacherA, $quiz, ['max_score' => 15])->assertSessionHasErrors('max_score', null, 'editItem');
        $this->assertEquals(20, $quiz->fresh()->max_score);

        $fingerprint = ClassRecordService::definitionFingerprint($quiz);
        $this->editAssessment($this->teacherA, $quiz, ['max_score' => 30])->assertSessionHasNoErrors(); // another tab saved first
        $this->actingAs($this->teacherA)->put(route('teacher.assessments.update', $quiz->id), [
            'max_score' => 25, 'component' => 'written_work', 'loaded_fingerprint' => $fingerprint,
        ])->assertSessionHasErrors('component', null, 'editItem');
        $this->assertEquals(30, $quiz->fresh()->max_score, 'the newer edit was not overwritten');
        $this->assertSame('18.00', $this->score($quiz, $this->ana), 'scores untouched');
    }

    public function test_every_evidence_change_is_audited_with_actor_values_year_and_term(): void
    {
        $quiz = $this->item($this->cesc, 'Quiz 1', 20);
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, '11')])->assertOk();
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, '13', false, '11')])->assertOk();
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, null, true, '13')])->assertOk();

        $rows = AssessmentEvidenceAudit::where('assessment_id', $quiz->id)->where('student_id', $this->ana->id)->orderBy('id')->get();
        $this->assertSame([
            ['not_yet_given', 'completed', null, '11.00'],
            ['completed', 'completed', '11.00', '13.00'],
            ['completed', 'not_yet_given', '13.00', null],
            ['not_yet_given', 'missing', null, null],
        ], $rows->map(fn($r) => [$r->old_status, $r->new_status, $r->old_score === null ? null : (string) $r->old_score, $r->new_score === null ? null : (string) $r->new_score])->all());
        $this->assertTrue($rows->every(fn($r) => $r->changed_by === $this->teacherA->id && $r->school_year === '2026-2027'
            && $r->grading_period === 1 && $r->assessment_name === 'Quiz 1'));
        // The blank-to-Missing step is recorded as automatic.
        $this->assertSame(['class_record', 'class_record', 'class_record', ClassRecordService::AUTO_MISSING_SOURCE], $rows->pluck('source')->all());

        $this->actingAs($this->teacherA)->get(route('teacher.assessments.history', $quiz->id))->assertOk()->assertSee('13');
    }

    // --- monitoring / advisory overview -----------------------------------------

    public function test_one_official_74_makes_the_learner_for_review_despite_a_high_average(): void
    {
        foreach ([[$this->cesc, 90], [$this->math, 88], [$this->science, 74]] as [$subject, $grade]) {
            Grade::create(['student_id' => $this->ana->id, 'subject_id' => $subject->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
                'school_year' => '2026-2027', 'grade' => $grade, 'is_verified' => true, 'is_provisional' => false, 'encoded_by' => $this->teacherA->id]);
        }
        foreach ([[$this->math, 70], [$this->science, 72]] as [$subject, $grade]) {
            Grade::create(['student_id' => $this->ben->id, 'subject_id' => $subject->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
                'school_year' => '2026-2027', 'grade' => $grade, 'is_verified' => true, 'is_provisional' => false, 'encoded_by' => $this->teacherA->id]);
        }

        $response = $this->actingAs($this->teacherA)->get(route('adviser.advisory-overview', ['period' => 1]));
        $response->assertOk();
        $rows = $response->viewData('rows')->keyBy(fn($r) => $r['student']->id);

        $ana = $rows[$this->ana->id];
        $this->assertSame([2, 1], [$ana['official']['passed'], $ana['official']['failed']]);
        $this->assertTrue($ana['monitoring']->isForReview());
        $this->assertSame(1, $ana['monitoring']->openCount());

        $ben = $rows[$this->ben->id];
        $this->assertSame(2, $ben['monitoring']->openCount(), 'two failed subjects are two concerns');
        $this->assertSame(['learners' => 2, 'for_review' => 2, 'open_concerns' => 3], $response->viewData('summary'), 'learners are counted once');
        $this->assertSame('no_evidence', $ben['results'][$this->cesc->id]['state'], 'a subject with nothing recorded is No Evidence Yet, never passing');
        $response->assertSee('No Evidence Yet');
    }

    public function test_no_current_concern_is_not_presented_as_complete_or_passing(): void
    {
        $response = $this->actingAs($this->teacherA)->get(route('adviser.advisory-overview', ['period' => 1]));
        $rows = $response->viewData('rows')->keyBy(fn($r) => $r['student']->id);
        $this->assertFalse($rows[$this->ana->id]['monitoring']->isForReview());
        $this->assertSame('No Evidence Yet', $rows[$this->ana->id]['completeness']['label']);
        $response->assertSee('No Current Concern')->assertSee('mean the learner passed', false);
    }

    // --- history ------------------------------------------------------------------

    public function test_a_new_year_enrollment_leaves_the_previous_years_record_and_report_intact(): void
    {
        $quiz = $this->item($this->cesc, 'Quiz 1', 20);
        $this->save($this->teacherA, $this->cesc, [$this->cell($quiz, $this->ana, '17')])->assertOk();

        AcademicYear::create(['school_year' => '2027-2028', 'is_active' => false]);
        $next = Section::factory()->create(['name' => 'Agila 12', 'grade_level' => 12, 'curriculum' => 'sshs', 'school_year' => '2027-2028', 'adviser_id' => null]);
        (new StudentEnrollmentService())->enroll($this->ana, $next);

        $record = $this->actingAs($this->teacherA)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]));
        $record->assertOk()->assertSee('ABAD');
        $this->assertSame(17.0, $record->viewData('record')['scores'][$quiz->id][$this->ana->id]);

        $report = $this->actingAs($this->teacherA)->get(route('teacher.learners.report', ['student' => $this->ana->id, 'school_year' => '2026-2027', 'period' => 1]));
        $report->assertOk()->assertSee('Agila')->assertDontSee('Agila 12')->assertSee('Community Engagement');

        // The adviser has no role in the new year's section.
        $this->actingAs($this->teacherA)->get(route('teacher.class-record', ['section_id' => $next->id]))->assertForbidden();
    }

    public function test_learner_reports_are_scoped_to_the_readers_authority(): void
    {
        Grade::create(['student_id' => $this->ana->id, 'subject_id' => $this->cesc->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'grade' => 91, 'is_verified' => true, 'is_provisional' => false, 'encoded_by' => $this->teacherA->id]);

        // Adviser: consolidated, every subject.
        $this->actingAs($this->teacherA)->get(route('teacher.learners.report', ['student' => $this->ana->id, 'school_year' => '2026-2027', 'period' => 1]))
            ->assertOk()->assertSee('General Mathematics')->assertSee('General Science')->assertSee('Official — Passed');

        // Subject teacher: only their subject — CESC's grade is not shown.
        $this->actingAs($this->teacherB)->get(route('teacher.learners.report', ['student' => $this->ana->id, 'school_year' => '2026-2027', 'period' => 1]))
            ->assertOk()->assertSee('General Mathematics')->assertDontSee('Community Engagement')->assertDontSee('91.00');

        // Nobody else.
        $stranger = User::factory()->create(['role' => 'subject_teacher']);
        $this->actingAs($stranger)->get(route('teacher.learners.report', ['student' => $this->ana->id, 'school_year' => '2026-2027', 'period' => 1]))->assertForbidden();
        $this->actingAs($this->teacherB)->get(route('teacher.learners.report', ['student' => $this->outsider->id, 'school_year' => '2026-2027', 'period' => 1]))->assertForbidden();
    }

    // --- excel / copy -------------------------------------------------------------

    public function test_the_excel_export_matches_the_app_and_keeps_lrn_zero_blank_and_missing_distinct(): void
    {
        $this->ben->update(['first_name' => '=HYPERLINK("http://x.test","BEN")']);
        $quiz = $this->item($this->cesc, 'Quiz 1', 20);
        $pt = $this->item($this->cesc, '+PT 1', 50, 'performance_task');
        $exam = $this->item($this->cesc, 'Term Exam', 40, 'examination');
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->ana->id, 'score' => 0]);
        AssessmentScore::create(['assessment_id' => $pt->id, 'student_id' => $this->ana->id, 'score' => 40]);
        AssessmentScore::create(['assessment_id' => $exam->id, 'student_id' => $this->ana->id, 'score' => 30]);
        (new AssessmentStatusService())->markMissing($quiz, $this->ben->id, $this->teacherA->id);

        $response = $this->actingAs($this->teacherA)->get(route('teacher.class-record.export', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]));
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'cr') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        // Client-style layout (2026-10-04): row 13 slot labels, row 14 item
        // names, row 15 HPS; learners by gender block, LRN in column C.
        $highest = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $letters = collect(range(1, $highest))->map(fn($c) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c));
        $find = fn(int $row, string $header) => $letters->first(fn($col) => (string) $sheet->getCell($col . $row)->getValue() === $header);
        $rowOf = fn(string $lrn) => collect(range(16, $sheet->getHighestRow()))->first(fn($r) => $sheet->getCell('C' . $r)->getValue() === $lrn);

        $this->assertStringContainsString('NOT the official DepEd E-Class Record workbook', (string) $sheet->getCell('A2')->getValue());
        $this->assertStringContainsString('SYSTEM-GENERATED', (string) $sheet->getCell('A1')->getValue());
        $ana = $rowOf('012345678901');
        $ben = $rowOf('012345678902');
        $this->assertNotNull($ana);
        $this->assertSame('FEMALE', $sheet->getCell('B' . ($ana - 1))->getValue(), 'learners sit under their recorded-gender block');
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C' . $ana)->getDataType(), 'LRN is text');
        $quizCol = $find(14, 'Quiz 1');
        $this->assertSame('1', $sheet->getCell($quizCol . '13')->getValue(), 'Quiz 1 fills WW slot 1');
        $this->assertEquals(20, $sheet->getCell($quizCol . '15')->getValue(), 'HPS row');
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($quizCol . $ana)->getDataType());
        $this->assertEquals(0, $sheet->getCell($quizCol . $ana)->getValue(), 'a recorded 0 stays a number');
        $this->assertSame('MISSING', $sheet->getCell($quizCol . $ben)->getValue());
        // An Examination item with no type (and no typed item in the class) is the pooled legacy Examination.
        $examCol = $find(14, 'Term Exam (no Examination Type — counted in a pooled Examination)');
        $this->assertNotNull($examCol);
        $this->assertNull($sheet->getCell($examCol . $ben)->getValue(), 'no evidence stays empty');

        // Untrusted text is stored as text, never as a formula.
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B' . $ben)->getDataType());
        $this->assertStringStartsWith('BAUTISTA, =HYPERLINK', (string) $sheet->getCell('B' . $ben)->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($find(14, '+PT 1') . '14')->getDataType());
        foreach ($sheet->getCoordinates() as $c) {
            $this->assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell($c)->getDataType(), "{$c} is a formula");
        }

        // The initial grade in the sheet is GradingEngine's own figure; the Term
        // Grade sits beside it only when complete, and the official grade apart.
        $engine = (new GradingEngine())->computeGrade($this->ana, $this->cesc, $this->agila, 1, '2026-2027');
        $this->assertEqualsWithDelta($engine['computed_grade'] ?? $engine['running_grade'], (float) $sheet->getCell($find(13, 'INITIAL GRADE') . $ana)->getValue(), 0.001);
        $this->assertSame($engine['computed_grade'] === null ? 'running · partial' : 'provisional', $sheet->getCell($find(13, 'Initial Grade status') . $ana)->getValue());
        $this->assertNull($sheet->getCell($find(13, 'Official Grade') . $ana)->getValue(), 'nothing verified, nothing official');
    }

    public function test_copy_scores_is_tab_separated_and_formula_safe(): void
    {
        $this->ben->update(['last_name' => '@BAUTISTA']);
        $quiz = $this->item($this->cesc, 'Quiz 1', 20);
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->ana->id, 'score' => 0]);
        (new AssessmentStatusService())->markMissing($quiz, $this->ben->id, $this->teacherA->id);

        $text = $this->actingAs($this->teacherA)->get(route('teacher.class-record.copy', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]))
            ->assertOk()->getContent();
        $lines = explode("\r\n", trim($text));
        $this->assertSame("LRN\tLearner\tQuiz 1", $lines[0]);
        $this->assertSame("\tHighest Possible Score\t20", $lines[1]);
        // Rows follow the roster order the page uses; the name starting with
        // "@" is prefixed so Excel reads it as text, never a formula.
        // The LRN carries a leading apostrophe so Excel keeps it as text with its zeros.
        $this->assertContains("'012345678901\tABAD, ANA\t0", $lines);
        $this->assertContains("'012345678902\t'@BAUTISTA, BEN\tMISSING", $lines);
        $this->assertCount(4, $lines);

        // "Scores only": the score block alone, same learner and column order, no labels.
        $scores = $this->actingAs($this->teacherA)->get(route('teacher.class-record.copy', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1, 'mode' => 'scores']))
            ->assertOk()->getContent();
        $rows = explode("\r\n", rtrim($scores, "\r\n"));
        $order = collect([$this->ana, $this->ben])->sortBy(fn($s) => $s->last_name)->values();
        $this->assertSame($order->map(fn($s) => $s->id === $this->ana->id ? '0' : 'MISSING')->all(), $rows);
    }

    // --- admin ---------------------------------------------------------------------

    public function test_admin_records_assignments_without_guessing_or_silent_replacement(): void
    {
        $item = $this->item($this->science); // evidence, nobody assigned
        $page = $this->actingAs($this->admin)->get(route('admin.teaching-assignments'));
        $page->assertOk()->assertSee('Recorded evidence with no teaching assignment')->assertSee('General Science');

        // Already assigned to B for Term 1 — never replaced silently.
        $this->actingAs($this->admin)->post(route('admin.teaching-assignments.store'), [
            'user_id' => $this->teacherA->id, 'section_id' => $this->agila->id, 'subject_id' => $this->math->id, 'terms' => [1],
        ])->assertSessionHasErrors('terms');
        $this->assertSame($this->teacherB->id, TeachingAssignment::where('subject_id', $this->math->id)->where('grading_period', 1)->value('user_id'));

        // A principal account cannot be assigned; a Grade 12 subject is not applicable to a Grade 11 section.
        $this->actingAs($this->admin)->post(route('admin.teaching-assignments.store'), [
            'user_id' => $this->principal->id, 'section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'terms' => [1],
        ])->assertSessionHasErrors('user_id');
        $g12 = Subject::factory()->create(['name' => 'G12 Only', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        $this->actingAs($this->admin)->post(route('admin.teaching-assignments.store'), [
            'user_id' => $this->teacherB->id, 'section_id' => $this->agila->id, 'subject_id' => $g12->id, 'terms' => [1],
        ])->assertSessionHasErrors('terms');

        $this->actingAs($this->admin)->post(route('admin.teaching-assignments.store'), [
            'user_id' => $this->teacherB->id, 'section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'terms' => [1, 2],
        ])->assertSessionHas('success');
        $this->assertSame(2, TeachingAssignment::where('subject_id', $this->science->id)->where('user_id', $this->teacherB->id)->count());
        $this->save($this->teacherB, $this->science, [$this->cell($item, $this->ana, '9')])->assertOk();

        // Non-admins cannot manage assignments.
        $this->actingAs($this->teacherA)->post(route('admin.teaching-assignments.store'), ['user_id' => $this->teacherA->id, 'section_id' => $this->agila->id, 'subject_id' => $this->math->id, 'terms' => [2]])->assertForbidden();

        // A subject teacher account can be created by the Admin.
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'last_name' => 'Reyes', 'first_name' => 'Rosa', 'username' => 'rosa_reyes', 'role' => 'subject_teacher',
            'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('role', 'subject_teacher')->where('first_name', 'Rosa')->exists());
    }
}
