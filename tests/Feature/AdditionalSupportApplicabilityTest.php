<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ClassRecordExport;
use App\Services\ClassRecordService;
use App\Services\GradeVerificationBoard;
use App\Services\GradingEngine;
use App\Services\InTermStatusService;
use App\Services\PerformanceAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Additional-support APPLICABILITY (established rule: HANDOFF.md "within-term
 * additional support is uncapped and additive"; docs/claude-archive/04 "the
 * system implements (1) additive"; Hard Constraint 2: the flag itself never
 * changes how a SCORED item counts).
 *
 * A support item applies only to the learners given it — those with a numeric
 * score on it. For anyone else it is absent from earned AND HPS, so adding one
 * can never alter their figures: saved computation, live preview, Verify
 * Grades, in-term status and the export. Its blanks are "not given": a save
 * never turns them into Missing (so no Missing concern opens).
 */
class AdditionalSupportApplicabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
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
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);

        $this->teacher = User::factory()->create(['role' => 'subject_teacher']);
        $this->section = Section::factory()->create(['grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        $this->subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1, 2, 3]);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id, 'gender' => 'female']);
        $this->ben = Student::factory()->create(['section_id' => $this->section->id, 'gender' => 'male']);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id,
            'school_year' => '2026-2027', 'grading_period' => 1]);

        // A complete class: WW 20, PT 50, ST1/ST2/TE 30/30/40 — both learners scored.
        foreach ([['Quiz 1', 'written_work', 20, null, 15, 10], ['PT 1', 'performance_task', 50, null, 40, 30],
                  ['ST 1', 'examination', 30, 'st1', 24, 15], ['ST 2', 'examination', 30, 'st2', 21, 18],
                  ['TE', 'examination', 40, 'term_exam', 30, 20]] as [$name, $component, $hps, $role, $a, $b]) {
            $item = $this->item($name, $component, $hps, $role);
            AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => $a]);
            AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->ben->id, 'score' => $b]);
        }
    }

    private function item(string $name, string $component, float $hps, ?string $role = null, bool $support = false): Assessment
    {
        return Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => $hps,
            'is_additional_support' => $support, 'uploaded_by' => $this->teacher->id]);
    }

    /** Every figure a page or export shows for one learner. */
    private function snapshot(Student $s): array
    {
        $g = (new GradingEngine())->computeGrade($s, $this->subject, $this->section, 1, '2026-2027');
        $record = (new ClassRecordService())->build($this->section, $this->subject, 1);
        $analysis = (new PerformanceAnalysisService())->analyzeStudent($s, $this->subject, $this->section, 1, '2026-2027');
        $status = (new InTermStatusService())->fromAnalysis($analysis, $s, $this->subject, $this->section, 1, '2026-2027');
        $board = (new GradeVerificationBoard())->build($this->section, $this->subject, 1, null)['performance']->first(fn($r) => $r['student']->id === $s->id);

        $sheet = (new ClassRecordExport())->build($record)->getActiveSheet();
        $row = collect(range(16, $sheet->getHighestRow()))->first(fn($n) => $sheet->getCell('C' . $n)->getValue() === $s->lrn);
        $exportRow = collect($sheet->rangeToArray('D' . $row . ':' . $sheet->getHighestColumn() . $row, null, true, false)[0])
            ->filter(fn($v) => is_float($v) || is_int($v))->values()->all(); // the numeric cells: scores, TOTAL/PS/WS, grades

        return [
            'grade'  => [$g['components'], $g['partial_contributions'], $g['computed_grade'], $g['transmuted_grade'], $g['complete']],
            'status' => [$status['status'], $status['components_below_target']],
            'board'  => [$board['verify_state'], $board['computed_grade'] ?? null],
            'display' => collect($record['display'][$s->id])->only(['written_work:ps', 'written_work:ws', 'examination:ps', 'examination:ws',
                'grade:initial', 'grade:transmuted'])->all(),
            'export' => $exportRow, // Ana has no value in a support column, so it adds no numeric cell
        ];
    }

    public function test_adding_support_items_never_changes_the_grade_of_a_learner_not_given_them(): void
    {
        $g = (new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027');
        Grade::create(['student_id' => $this->ana->id, 'subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'grade' => $g['transmuted_grade'], 'computed_grade' => $g['computed_grade'],
            'is_verified' => true, 'verified_at' => now(), 'is_provisional' => false, 'encoded_by' => $this->teacher->id]);
        $before = $this->snapshot($this->ana);
        $this->assertSame('verified', $before['board'][0]);
        $benBefore = (new GradingEngine())->computeGrade($this->ben, $this->subject, $this->section, 1, '2026-2027');

        // Ben is given a WW and an ST1 support item; Ana is given neither.
        $wwSupport = $this->item('Remedial Quiz', 'written_work', 20, null, true);
        $stSupport = $this->item('ST 1 retake', 'examination', 30, 'st1', true);
        AssessmentScore::create(['assessment_id' => $wwSupport->id, 'student_id' => $this->ben->id, 'score' => 15]);
        AssessmentScore::create(['assessment_id' => $stSupport->id, 'student_id' => $this->ben->id, 'score' => 12]);

        $this->assertSame($before, $this->snapshot($this->ana), 'grade, display, in-term status, Verify Grades and export are unchanged for Ana');

        // Ben: additive — points AND HPS. WW (10 + 15) / (20 + 20) = 62.50; ST1 (15 + 12) / (30 + 30) × 30 = 13.50.
        $ben = (new GradingEngine())->computeGrade($this->ben, $this->subject, $this->section, 1, '2026-2027');
        $this->assertSame(50.0, $benBefore['components']['written_work']);
        $this->assertSame(62.5, $ben['components']['written_work']);
        $this->assertSame(15.0, $benBefore['exam_roles']['st1']['weighted']);
        $this->assertSame(13.5, $ben['exam_roles']['st1']['weighted']);
    }

    public function test_the_live_preview_of_a_support_score_leaves_the_other_learner_unchanged(): void
    {
        $support = $this->item('Remedial Quiz', 'written_work', 20, null, true);
        $before = (new ClassRecordService())->build($this->section, $this->subject, 1)['display'][$this->ana->id];

        $response = $this->actingAs($this->teacher)->postJson(route('teacher.class-record.preview'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $support->id, 'student_id' => $this->ben->id, 'score' => '15'],
                        ['assessment_id' => $support->id, 'student_id' => $this->ana->id, 'score' => null]],
            'student_ids' => [$this->ana->id, $this->ben->id],
        ])->assertOk();

        $preview = $response->json('rows.' . $this->ana->id);
        $this->assertNotNull($preview, 'preview returns Ana\'s row');
        foreach (['written_work:ps', 'written_work:ws', 'grade:initial', 'grade:transmuted'] as $key) {
            $this->assertSame($before[$key], $preview[$key], $key);
        }
        $this->assertSame(0, AssessmentScore::where('assessment_id', $support->id)->count(), 'preview writes nothing');
    }

    public function test_saving_a_support_item_never_marks_the_learners_not_given_it_missing(): void
    {
        $support = $this->item('Remedial Quiz', 'written_work', 20, null, true);
        $before = $this->snapshot($this->ana);

        // The page sends the changed cell AND the column's other blank (as an older page would).
        $this->actingAs($this->teacher)->postJson(route('teacher.class-record.save'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $support->id, 'student_id' => $this->ben->id, 'score' => '15', 'base_score' => null, 'base_missing' => false],
                        ['assessment_id' => $support->id, 'student_id' => $this->ana->id, 'score' => null, 'base_score' => null, 'base_missing' => false]],
        ])->assertOk();

        $this->assertSame(1, AssessmentScore::where('assessment_id', $support->id)->count());
        $this->assertSame(0, AssessmentLearnerStatus::where('assessment_id', $support->id)->count(), 'a support blank is "not given", never Missing');
        $this->assertSame(0, AcademicConcern::count(), 'so no Missing concern opens');
        $this->assertSame($before, $this->snapshot($this->ana));

        // Clearing Ben's support score returns him to "not given" — no Missing marker either.
        $this->actingAs($this->teacher)->postJson(route('teacher.class-record.save'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $support->id, 'student_id' => $this->ben->id, 'score' => null, 'base_score' => '15', 'base_missing' => false]],
        ])->assertOk();
        $this->assertSame(0, AssessmentScore::where('assessment_id', $support->id)->count());
        $this->assertSame(0, AssessmentLearnerStatus::where('assessment_id', $support->id)->count());
    }

    public function test_the_grid_marks_support_cells_so_the_page_never_sends_their_blanks(): void
    {
        $support = $this->item('Remedial Quiz', 'written_work', 20, null, true);
        $html = $this->actingAs($this->teacher)->get(route('teacher.class-record', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1]))
            ->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-a="' . $support->id . '"[^>]*data-support="1"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/data-a="' . $support->id . '"[^>]*data-support="0"/s', $html);
    }

    public function test_a_regular_item_blank_still_becomes_missing(): void
    {
        $quiz = $this->item('Quiz 2', 'written_work', 10);
        $this->actingAs($this->teacher)->postJson(route('teacher.class-record.save'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $quiz->id, 'student_id' => $this->ben->id, 'score' => '8', 'base_score' => null, 'base_missing' => false],
                        ['assessment_id' => $quiz->id, 'student_id' => $this->ana->id, 'score' => null, 'base_score' => null, 'base_missing' => false]],
        ])->assertOk();
        $this->assertSame(1, AssessmentLearnerStatus::where('assessment_id', $quiz->id)->where('student_id', $this->ana->id)->count());
    }
}
