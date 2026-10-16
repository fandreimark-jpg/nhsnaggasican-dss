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
    use \Tests\Concerns\UsesClassRecord;
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;
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

    public function test_4_missing_is_never_stored_as_a_zero_and_keeps_its_hps_in_the_denominator(): void
    {
        $quiz1 = $this->item('Quiz 1');
        $quiz2 = $this->item('Quiz 2');
        $this->score($quiz1, $this->ana, 18);
        $this->statuses->markMissing($quiz2, $this->ana->id, $this->adviser->id);

        // Workbook Term 1!O17 (D1, 2026-10-04): N17 = numeric entries = 18,
        // N14 = every HPS = 40 -> 45%. Missing earns nothing; it stays a marker.
        $this->assertEqualsWithDelta(45.0, $this->grade($this->ana)['components']['written_work'], 0.001);
        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $quiz2->id, 'student_id' => $this->ana->id]);
    }

    public function test_5_a_never_saved_blank_is_not_stored_and_keeps_its_hps_in_the_denominator(): void
    {
        $quiz1 = $this->item('Quiz 1');
        $this->item('Quiz 2'); // nobody has a score or a marker on it
        $this->score($quiz1, $this->ana, 18);

        // Same workbook rule as a Missing entry: 18 / (20 + 20) = 45%.
        $this->assertEqualsWithDelta(45.0, $this->grade($this->ana)['components']['written_work'], 0.001);
        $this->assertSame(1, AssessmentScore::count(), 'the blank is not stored as anything');
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

    public function test_6b_a_score_entered_in_the_class_record_removes_the_missing_marker(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->saveCells($this->adviser, $item, [$this->ana->id => '15'])->assertOk();

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

    public function test_7b_a_cell_with_both_a_score_and_missing_is_refused_and_writes_nothing(): void
    {
        $item = $this->item();

        $this->actingAs($this->adviser)->postJson(route('teacher.class-record.save'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => '12', 'missing' => true, 'base_score' => null, 'base_missing' => false]],
        ])->assertStatus(422);

        $this->assertSame(0, AssessmentScore::count());
        $this->assertSame(0, AssessmentLearnerStatus::count());

        // The Add Assessment form never takes scores or Missing marks at all.
        $this->addAssessment($this->adviser, $this->section, $this->subject, [
            'item_name' => 'Quiz 9', 'scores' => [$this->ana->id => 12], 'missing' => [$this->ana->id => '1'],
        ])->assertSessionHasErrors('scores', null, 'addItem');
        $this->assertDatabaseMissing('assessments', ['name' => 'Quiz 9']);
    }

    public function test_7c_the_edit_assessment_form_cannot_mark_missing_or_touch_a_score(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        $this->editAssessment($this->adviser, $item, ['missing' => [$this->ana->id => '1']])
            ->assertSessionHasErrors('scores', null, 'editItem');

        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 18]);
        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_7d_typing_m_over_a_score_in_the_grid_leaves_only_the_marker(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        $this->saveCells($this->adviser, $item, [$this->ana->id => 'M'])->assertOk();

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

    public function test_a_new_assessment_starts_with_every_learner_not_yet_given_and_the_grid_records_each_state(): void
    {
        $this->addAssessment($this->adviser, $this->section, $this->subject, ['item_name' => 'Quiz 5'])
            ->assertSessionHas('success', 'Assessment added. Enter scores in the Class Record.');

        $item = Assessment::where('name', 'Quiz 5')->firstOrFail();
        foreach ([$this->ana, $this->ben, $this->cara] as $learner) {
            $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $learner->id), 'creating a column marks nobody Missing');
        }

        $this->saveCells($this->adviser, $item, [$this->ana->id => '18', $this->ben->id => 'M', $this->cara->id => ''])->assertOk();

        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ben->id));
        // Automatic Missing (2026-10-04): a blank saved with the assessment is Missing.
        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->cara->id));
        $this->assertSame(1, AssessmentScore::where('assessment_id', $item->id)->count(), 'Missing wrote no score row, not even a zero');
    }

    public function test_emptying_a_missing_cell_keeps_the_learner_missing(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->saveCells($this->adviser, $item, [$this->ana->id => ''])->assertOk()->assertJson(['changed' => 0]);

        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertSame(1, AssessmentLearnerStatus::count());
    }

    public function test_the_class_record_explains_the_three_states_and_shows_missing(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->actingAs($this->adviser)
            ->get(route('teacher.class-record', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1]))
            ->assertOk()
            ->assertSee('Completed')->assertSee('No Evidence Yet')->assertSee('Missing')
            ->assertSee('data-base-missing="1"', false)
            ->assertSee('1 Missing')
            ->assertSee('Blank entries in the assessments being saved will be marked Missing.')
            ->assertDontSee('id="crMarkMissing"', false);
    }

    public function test_10_clearing_a_scored_cell_deletes_the_score_and_saves_the_blank_as_missing(): void
    {
        $item = $this->item();
        $this->score($item, $this->ana, 18);

        $this->saveCells($this->adviser, $item, [$this->ana->id => ''])->assertOk();

        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id]);
        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ana->id), 'Clear Entry follows blank -> Missing');
        $this->assertSame(0, AssessmentScore::where('assessment_id', $item->id)->count(), 'never replaced by a zero');
    }

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

    public function test_13_an_adviser_cannot_change_statuses_on_another_advisers_item(): void
    {
        $otherAdviser = User::factory()->create(['role' => 'adviser']);
        Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $otherAdviser->id]);
        $item = $this->item();

        $this->saveCells($otherAdviser, $item, [$this->ana->id => 'M'])->assertForbidden();

        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_13b_a_learner_outside_the_section_is_refused_and_nothing_is_written(): void
    {
        $outsider = Student::factory()->create(['section_id' => Section::factory()->create(['school_year' => '2026-2027'])->id]);
        $item = $this->item();

        $this->saveCells($this->adviser, $item, [$this->ana->id => 'M', $outsider->id => 'M'])->assertStatus(422);

        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    public function test_13c_add_assessment_refuses_a_subject_not_applicable_to_the_section(): void
    {
        $grade12Subject = Subject::factory()->create(['name' => 'Other Subject', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);

        $this->addAssessment($this->adviser, $this->section, $grade12Subject, ['item_name' => 'Quiz X'])
            ->assertSessionHasErrors('item_name', null, 'addItem');

        $this->assertDatabaseMissing('assessments', ['name' => 'Quiz X']);
    }

    public function test_14_principal_and_admin_cannot_use_the_status_write_endpoints(): void
    {
        $item = $this->item();

        foreach ([User::factory()->principal()->create(), User::factory()->admin()->create()] as $user) {
            $this->saveCells($user, $item, [$this->ana->id => 'M'])->assertForbidden();
            $this->addAssessment($user, $this->section, $this->subject, ['item_name' => 'Quiz P'])->assertForbidden();
            $this->editAssessment($user, $item, ['max_score' => 25])->assertForbidden();
        }

        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertDatabaseMissing('assessments', ['name' => 'Quiz P']);
    }

    public function test_15_a_closed_term_rejects_status_changes(): void
    {
        $item = $this->item();
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        $this->saveCells($this->adviser, $item, [$this->ana->id => 'M'])->assertStatus(423);
        $this->addAssessment($this->adviser, $this->section, $this->subject, ['item_name' => 'Quiz C'])
            ->assertSessionHasErrors('item_name', null, 'addItem');

        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertDatabaseMissing('assessments', ['name' => 'Quiz C']);
    }

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
