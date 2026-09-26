<?php

namespace Tests\Feature;

use App\Exceptions\AssessmentStatusConflictException;
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
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsEcrFixture;
use Tests\TestCase;

/**
 * "Rule-based academic monitoring" Phase 2A — Assessment Status Foundation.
 *
 * A learner's state on one assessment item is DERIVED:
 *   a score row exists             -> Completed
 *   else an explicit Missing marker -> Missing
 *   else                           -> Not Yet Given / No Evidence Yet
 *
 * and neither Missing nor Not Yet Given ever reaches GradingEngine as a
 * zero. A blank (ECR cell or form field) is never turned into Missing on
 * its own.
 */
class AssessmentLearnerStatusTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEcrFixture;

    private User $adviser;
    private Section $section;
    private Subject $subject;
    private Student $ana;
    private Student $ben;
    private Student $cara;
    private AssessmentStatusService $statuses;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create([
            'name' => 'Shakespeare', 'grade_level' => 11, 'school_year' => '2026-2027',
            'curriculum' => 'sshs', 'adviser_id' => $this->adviser->id,
        ]);
        $this->subject = Subject::factory()->create([
            'name' => 'General Mathematics', 'type' => 'core',
            'grade_level' => 11, 'subject_group' => 'core_academic',
        ]);

        $this->ana = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000001',
            'last_name' => 'Ana', 'first_name' => 'Aa', 'gender' => 'female',
        ]);
        $this->ben = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000002',
            'last_name' => 'Ben', 'first_name' => 'Bb', 'gender' => 'female',
        ]);
        $this->cara = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000003',
            'last_name' => 'Cara', 'first_name' => 'Cc', 'gender' => 'female',
        ]);

        $this->statuses = new AssessmentStatusService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function item(string $name = 'Quiz 1', string $component = 'written_work', float $max = 20): Assessment
    {
        return Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => $name, 'assessment_type' => $name, 'component' => $component,
            'max_score' => $max, 'uploaded_by' => $this->adviser->id,
        ]);
    }

    private function score(Assessment $item, Student $student, float $score): AssessmentScore
    {
        return AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $student->id, 'score' => $score]);
    }

    private function grade(Student $student): array
    {
        return (new GradingEngine())->computeGrade($student, $this->subject, $this->section, 1, '2026-2027');
    }

    private function editPayload(Assessment $item, array $scores, array $missing): array
    {
        return ['max_score' => $item->max_score, 'grading_period' => 1, 'assessment_id' => $item->id,
            'scores' => $scores, 'missing' => $missing];
    }

    // ---------------------------------------------------------------
    // 1-3. Derivation
    // ---------------------------------------------------------------

    public function test_1_an_existing_score_resolves_to_completed(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertSame(0, AssessmentLearnerStatus::count(), 'Completed is never stored — the score row proves it');
    }

    public function test_2_an_explicit_missing_marker_resolves_to_missing(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertDatabaseHas('assessment_learner_statuses', [
            'assessment_id' => $item->id, 'student_id' => $this->ana->id,
            'status' => 'missing', 'recorded_by' => $this->adviser->id,
        ]);
    }

    public function test_3_neither_score_nor_marker_resolves_to_not_yet_given(): void
    {
        $item = $this->item();

        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertSame('Not Yet Given / No Evidence Yet', AssessmentStatusService::label(AssessmentStatusService::NOT_YET_GIVEN));
    }

    public function test_the_bulk_resolver_agrees_with_the_single_resolver(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);
        $this->statuses->markMissing($item, $this->ben->id, $this->adviser->id);

        $all = $this->statuses->statusesFor(collect([$item]), [$this->ana->id, $this->ben->id, $this->cara->id]);

        $this->assertSame(AssessmentStatusService::COMPLETED, $all[$item->id][$this->ana->id]);
        $this->assertSame(AssessmentStatusService::MISSING, $all[$item->id][$this->ben->id]);
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $all[$item->id][$this->cara->id]);
    }

    // ---------------------------------------------------------------
    // 4-5. Never a zero
    // ---------------------------------------------------------------

    public function test_4_missing_is_never_interpreted_as_a_zero(): void
    {
        $quiz1 = $this->item('Quiz 1');
        $quiz2 = $this->item('Quiz 2');
        $this->score($quiz1, $this->ana, 18);
        $this->statuses->markMissing($quiz2, $this->ana->id, $this->adviser->id);

        // 18/20 = 90%. Were Missing a zero it would be 18/40 = 45%.
        $this->assertEqualsWithDelta(90.0, $this->grade($this->ana)['components']['written_work'], 0.001);
        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $quiz2->id, 'student_id' => $this->ana->id]);
    }

    public function test_5_not_yet_given_is_never_interpreted_as_a_zero(): void
    {
        $quiz1 = $this->item('Quiz 1');
        $this->item('Quiz 2'); // nobody has a score or a marker on it
        $this->score($quiz1, $this->ana, 18);

        $this->assertEqualsWithDelta(90.0, $this->grade($this->ana)['components']['written_work'], 0.001);
    }

    // ---------------------------------------------------------------
    // 6-7. The invariant
    // ---------------------------------------------------------------

    public function test_6_entering_a_score_removes_an_existing_missing_marker(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->statuses->recordScore($item, $this->ana->id, 15);

        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_6b_a_score_entered_on_edit_removes_the_missing_marker(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, $this->editPayload($item, [$this->ana->id => 15], [$this->ana->id => '0']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 15]);
        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_7_marking_missing_is_refused_while_a_score_exists(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        try {
            $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
            $this->fail('Marking Missing over a recorded score must be refused.');
        } catch (AssessmentStatusConflictException) {
        }

        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 18]);
    }

    public function test_7b_a_form_with_both_a_score_and_missing_for_one_learner_is_refused_and_writes_nothing(): void
    {
        $item = $this->item();

        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, $this->editPayload($item, [$this->ana->id => 12], [$this->ana->id => '1']))
            ->assertSessionHasErrors('scores', null, 'editItem');

        $this->assertSame(0, AssessmentScore::count());
        $this->assertSame(0, AssessmentLearnerStatus::count());

        $this->actingAs($this->adviser)->post('/adviser/assessments/item', [
            'subject_id' => $this->subject->id, 'grading_period' => 1, 'item_name' => 'Quiz 9',
            'component' => 'written_work', 'max_score' => 20,
            'scores' => [$this->ana->id => 12], 'missing' => [$this->ana->id => '1'],
        ])->assertSessionHasErrors('scores', null, 'addItem');

        $this->assertDatabaseMissing('assessments', ['name' => 'Quiz 9']);
    }

    public function test_7c_marking_missing_on_edit_without_clearing_the_score_is_refused(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        // A crafted request: Missing ticked, the score field not sent at all.
        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, ['max_score' => 20, 'missing' => [$this->ana->id => '1']])
            ->assertSessionHasErrors('scores', null, 'editItem');

        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 18]);
        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_7d_clearing_the_score_and_marking_missing_in_one_save_leaves_only_the_marker(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, $this->editPayload($item, [$this->ana->id => ''], [$this->ana->id => '1']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id]);
        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ana->id));
    }

    public function test_the_model_itself_refuses_a_marker_over_a_score(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        $this->expectException(AssessmentStatusConflictException::class);
        AssessmentLearnerStatus::create([
            'assessment_id' => $item->id, 'student_id' => $this->ana->id,
            'status' => 'missing', 'recorded_by' => $this->adviser->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Manual entry — add and un-mark
    // ---------------------------------------------------------------

    public function test_add_item_records_scores_and_explicit_missing_markers_and_leaves_blanks_not_yet_given(): void
    {
        $this->actingAs($this->adviser)->post('/adviser/assessments/item', [
            'subject_id' => $this->subject->id, 'grading_period' => 1, 'item_name' => 'Quiz 5',
            'component' => 'written_work', 'max_score' => 20,
            'scores' => [$this->ana->id => 18, $this->ben->id => '', $this->cara->id => ''],
            'missing' => [$this->ben->id => '1'],
        ])->assertSessionHas('success', 'Added "Quiz 5" with 1 score(s), 1 marked Missing.');

        $item = Assessment::where('name', 'Quiz 5')->firstOrFail();
        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ben->id));
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $this->cara->id));
        $this->assertSame(1, AssessmentScore::where('assessment_id', $item->id)->count(), 'Missing wrote no score row, not even a zero');
    }

    public function test_unticking_missing_on_edit_returns_the_learner_to_not_yet_given(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, $this->editPayload($item, [$this->ana->id => ''], [$this->ana->id => '0']))
            ->assertSessionHasNoErrors();

        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $this->ana->id));
    }

    public function test_an_edit_request_without_missing_keys_leaves_markers_untouched(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        // The pre-Phase-2A request shape: only max score and scores.
        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, ['max_score' => 25, 'scores' => [$this->ben->id => 20]])
            ->assertSessionHasNoErrors();

        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ana->id));
    }

    public function test_the_assessments_page_explains_the_three_states_and_counts_missing(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->actingAs($this->adviser)
            ->get('/adviser/assessments?subject_id=' . $this->subject->id . '&period=1')
            ->assertOk()
            ->assertSee('Not Yet Given / No Evidence Yet')
            ->assertSee('1 marked Missing')
            ->assertSee('name="missing[' . $this->ana->id . ']"', false);
    }

    // ---------------------------------------------------------------
    // 8-9. E-Class Record blanks
    // ---------------------------------------------------------------

    /** @param array<int, ?float> $cells female-row index => score (null = blank) */
    private function uploadEcr(array $cells): void
    {
        $scores = [];
        foreach ($cells as $index => $value) {
            $scores[$this->femaleTermRow($index)] = $value;
        }

        $path = $this->buildFilledEcrCopy(
            gradeLevel: '11',
            sectionName: 'Shakespeare',
            courseTitle: 'General Mathematics',
            roster: [
                ['lrn' => '110000000001', 'name' => 'Ana, Aa', 'gender' => 'female'],
                ['lrn' => '110000000002', 'name' => 'Ben, Bb', 'gender' => 'female'],
                ['lrn' => '110000000003', 'name' => 'Cara, Cc', 'gender' => 'female'],
            ],
            scores: ['D' => $scores],
            maxScores: ['D' => 20],
            schoolYearStart: '2026',
        );
        $this->tempFiles[] = $path;

        $detect = $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $this->subject->id, 'grading_period' => 1,
            'file' => new UploadedFile($path, 'ecr.xlsx', null, null, true),
        ]);
        $detect->assertOk();

        $columns = collect($detect->viewData('columns'))
            ->map(fn($c) => ['name' => $c['name'], 'component' => 'written_work', 'max_score' => $c['max_score'] ?? 20])
            ->values()->all();

        $this->actingAs($this->adviser)->post('/adviser/assessments/import', [
            'subject_id' => $this->subject->id, 'grading_period' => 1,
            'stored_filename' => $detect->viewData('storedFilename'), 'columns' => $columns,
        ])->assertRedirect()->assertSessionHas('success');
    }

    public function test_8_a_blank_ecr_cell_creates_no_score_and_no_missing_marker(): void
    {
        $this->uploadEcr([0 => 18, 1 => null, 2 => 0]);

        $item = Assessment::firstOrFail();
        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ben->id]);
        $this->assertSame(0, AssessmentLearnerStatus::count(), 'a blank ECR cell never becomes Missing on its own');
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $this->ben->id));
        // A written 0 is a real, Completed score.
        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($item, $this->cara->id));
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->cara->id, 'score' => 0]);
    }

    public function test_9_a_repeat_ecr_upload_with_a_blank_keeps_the_existing_score(): void
    {
        $this->uploadEcr([0 => 18, 1 => 12, 2 => 10]);
        $this->uploadEcr([0 => 19, 1 => null, 2 => 10]);

        $item = Assessment::firstOrFail();
        $this->assertSame(1, Assessment::count(), 'a repeat upload updates the item, never duplicates it');
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 19]);
        // Unchanged historical behaviour: the blank kept Ben's earlier score.
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ben->id, 'score' => 12]);
        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_an_ecr_score_for_a_learner_marked_missing_clears_the_marker(): void
    {
        $this->uploadEcr([0 => 18, 1 => null, 2 => 10]);
        $item = Assessment::firstOrFail();
        $this->statuses->markMissing($item, $this->ben->id, $this->adviser->id);

        $this->uploadEcr([0 => 18, 1 => 14, 2 => 10]);

        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ben->id, 'score' => 14]);
        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_a_repeat_ecr_blank_keeps_a_missing_marker_as_it_was(): void
    {
        $this->uploadEcr([0 => 18, 1 => null, 2 => 10]);
        $item = Assessment::firstOrFail();
        $this->statuses->markMissing($item, $this->ben->id, $this->adviser->id);

        $this->uploadEcr([0 => 18, 1 => null, 2 => 10]);

        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ben->id));
    }

    // ---------------------------------------------------------------
    // 10. Manual-edit blank — historical behaviour, pinned
    // ---------------------------------------------------------------

    public function test_10_a_blank_on_manual_edit_still_deletes_the_score_and_does_not_mark_missing(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, ['max_score' => 20, 'scores' => [$this->ana->id => '']])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id]);
        $this->assertSame(0, AssessmentLearnerStatus::count(), 'clearing a score is not the same as marking Missing');
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $this->ana->id));
    }

    // ---------------------------------------------------------------
    // 11-12. GradingEngine untouched
    // ---------------------------------------------------------------

    public function test_11_grades_from_completed_assessments_are_identical_with_and_without_markers(): void
    {
        $ww = $this->item('Quiz 1', 'written_work', 20);
        $ww2 = $this->item('Quiz 2', 'written_work', 20);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $ex = $this->item('Exam', 'examination', 40);
        $this->score($ww, $this->ana, 18);
        $this->score($pt, $this->ana, 40);
        $this->score($ex, $this->ana, 30);
        $this->score($ww, $this->ben, 10);

        $before = $this->grade($this->ana);
        $this->assertTrue($before['complete']);

        $this->statuses->markMissing($ww2, $this->ana->id, $this->adviser->id);
        $this->statuses->markMissing($pt, $this->ben->id, $this->adviser->id);
        $this->statuses->markMissing($ex, $this->cara->id, $this->adviser->id);

        $this->assertSame($before, $this->grade($this->ana));
    }

    public function test_12_a_required_component_with_only_a_missing_marker_stays_incomplete(): void
    {
        $ww = $this->item('Quiz 1', 'written_work', 20);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $ex = $this->item('Exam', 'examination', 40);
        $this->score($ww, $this->ana, 18);
        $this->score($ex, $this->ana, 30);
        $this->statuses->markMissing($pt, $this->ana->id, $this->adviser->id);

        $grade = $this->grade($this->ana);

        $this->assertFalse($grade['complete']);
        $this->assertNull($grade['components']['performance_task'], 'Missing is no evidence, never a 0%');
        $this->assertNull($grade['computed_grade']);
        $this->assertNull($grade['transmuted_grade']);
    }

    // ---------------------------------------------------------------
    // 13-15. Authorization and term guard
    // ---------------------------------------------------------------

    public function test_13_an_adviser_cannot_change_statuses_on_another_advisers_item(): void
    {
        $otherAdviser = User::factory()->create(['role' => 'adviser']);
        Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $otherAdviser->id]);
        $item = $this->item();

        $this->actingAs($otherAdviser)
            ->put('/adviser/assessments/item/' . $item->id, $this->editPayload($item, [$this->ana->id => ''], [$this->ana->id => '1']))
            ->assertForbidden();

        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_13b_a_learner_outside_the_section_is_ignored(): void
    {
        $outsider = Student::factory()->create(['section_id' => Section::factory()->create(['school_year' => '2026-2027'])->id]);
        $item = $this->item();

        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, $this->editPayload($item, [], [$outsider->id => '1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_13c_add_item_refuses_a_subject_not_applicable_to_the_section(): void
    {
        $grade12Subject = Subject::factory()->create(['name' => 'Other Subject', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);

        $this->actingAs($this->adviser)->post('/adviser/assessments/item', [
            'subject_id' => $grade12Subject->id, 'grading_period' => 1, 'item_name' => 'Quiz X',
            'component' => 'written_work', 'max_score' => 20, 'missing' => [$this->ana->id => '1'],
        ])->assertSessionHasErrors('item_name', null, 'addItem');

        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_14_principal_and_admin_cannot_use_the_adviser_status_write_endpoints(): void
    {
        $item = $this->item();

        foreach ([User::factory()->principal()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)
                ->put('/adviser/assessments/item/' . $item->id, $this->editPayload($item, [$this->ana->id => ''], [$this->ana->id => '1']))
                ->assertForbidden();

            $this->actingAs($user)->post('/adviser/assessments/item', [
                'subject_id' => $this->subject->id, 'grading_period' => 1, 'item_name' => 'Quiz P',
                'component' => 'written_work', 'max_score' => 20, 'missing' => [$this->ana->id => '1'],
            ])->assertForbidden();
        }

        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_15_a_closed_term_rejects_status_changes(): void
    {
        $item = $this->item();
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        $this->actingAs($this->adviser)
            ->put('/adviser/assessments/item/' . $item->id, $this->editPayload($item, [$this->ana->id => ''], [$this->ana->id => '1']))
            ->assertSessionHas('error');

        $this->actingAs($this->adviser)->post('/adviser/assessments/item', [
            'subject_id' => $this->subject->id, 'grading_period' => 1, 'item_name' => 'Quiz C',
            'component' => 'written_work', 'max_score' => 20, 'missing' => [$this->ana->id => '1'],
        ])->assertSessionHasErrors('item_name', null, 'addItem');

        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    // ---------------------------------------------------------------
    // Integrity command and history protection
    // ---------------------------------------------------------------

    public function test_the_integrity_command_reports_a_raw_score_and_marker_pair(): void
    {
        $item = $this->item();
        $this->artisan('dss:check-integrity')->assertExitCode(0);

        // Only a raw write can produce this — both models refuse it.
        \DB::table('assessment_learner_statuses')->insert([
            'assessment_id' => $item->id, 'student_id' => $this->ana->id, 'status' => 'missing',
            'recorded_by' => $this->adviser->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('assessment_scores')->insert([
            'assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('dss:check-integrity')
            ->expectsOutputToContain('both a score and a Missing marker')
            ->assertExitCode(1);
        // The resolver still says Completed: a score always wins.
        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($item, $this->ana->id));
    }

    public function test_a_learner_with_only_a_missing_marker_is_protected_from_deletion(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->cara->id, $this->adviser->id);

        $this->assertTrue($this->cara->hasAcademicReferences());
    }
}
