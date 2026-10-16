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
use App\Models\User;
use App\Monitoring\Rules\MissingRequirementRule;
use App\Monitoring\Rules\OfficialFailingSubjectRule;
use App\Services\AssessmentStatusService;
use App\Services\ClassRecordService;
use App\Services\GradingEngine;
use App\Services\TransmutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Class Record, 2026-10-04: SSHS E-Class Record template layout, automatic
 * Missing on save, and the read-only live preview (running Initial Grade).
 *
 * Workbook reference: SSHS-E-Class-Record-SY-2026-2027.xlsx, sheet "Term 1"
 * (row 13 headers, row 14 Highest Possible Score, row 17 learner formulas)
 * and HELPER (W:Y component weights, AA:AC ST1/ST2/TE shares, B:D table).
 */
class ClassRecordAutoMissingAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $adviser;
    private Section $section;
    private Subject $subject;
    private Student $ana;
    private Student $ben;
    private Student $cara;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->teacher = User::factory()->create(['role' => 'subject_teacher']);
        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        // core_academic = 20 / 50 / 30 (HELPER!W7:Y7 for the core subjects).
        $this->subject = Subject::factory()->create(['name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1, 2, 3]);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'ABAD', 'first_name' => 'ANA', 'gender' => 'female']);
        $this->ben = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'BAUTISTA', 'first_name' => 'BEN', 'gender' => 'male']);
        $this->cara = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'CRUZ', 'first_name' => 'CARA', 'gender' => 'female']);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
    }

    private function item(string $name, string $component = 'written_work', float $max = 20, ?string $role = null): Assessment
    {
        return Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => $max, 'uploaded_by' => $this->teacher->id]);
    }

    /** One grid cell as the page sends it: the value now, and the value it LOADED. */
    private function cell(Assessment $a, Student $s, ?string $score, ?string $base = null, bool $baseMissing = false): array
    {
        return ['assessment_id' => $a->id, 'student_id' => $s->id, 'score' => $score, 'base_score' => $base, 'base_missing' => $baseMissing];
    }

    private function save(array $cells, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher)->postJson(route('teacher.class-record.save'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1, 'cells' => $cells,
        ]);
    }

    private function preview(array $cells, array $studentIds = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher)->postJson(route('teacher.class-record.preview'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1, 'cells' => $cells, 'student_ids' => $studentIds,
        ]);
    }

    private function evidenceState(Assessment $a, Student $s): string
    {
        return (new AssessmentStatusService())->statusFor($a, $s->id);
    }

    /** Every table a save can write — a preview must leave all of them unchanged. */
    private function academicWrites(): array
    {
        return [
            AssessmentScore::count(), (float) AssessmentScore::sum('score'), AssessmentLearnerStatus::count(),
            AssessmentEvidenceAudit::count(), AcademicConcern::count(), Grade::count(), DB::table('activity_logs')->count(),
        ];
    }

    // --- Automatic Missing ------------------------------------------------------

    public function test_1_2_a_blank_in_the_saved_assessment_becomes_missing_and_a_zero_stays_a_score(): void
    {
        $quiz = $this->item('Quiz 1');

        $this->save([$this->cell($quiz, $this->ana, '18'), $this->cell($quiz, $this->ben, '0'), $this->cell($quiz, $this->cara, null)])
            ->assertOk()->assertJsonPath('changed', 3);

        $this->assertSame(AssessmentStatusService::COMPLETED, $this->evidenceState($quiz, $this->ana));
        $this->assertSame(AssessmentStatusService::COMPLETED, $this->evidenceState($quiz, $this->ben), 'a 0 is a score');
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $quiz->id, 'student_id' => $this->ben->id, 'score' => 0]);
        $this->assertSame(AssessmentStatusService::MISSING, $this->evidenceState($quiz, $this->cara), 'the blank was saved as Missing');
        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $quiz->id, 'student_id' => $this->cara->id]);

        // Audited: actor, assessment, learner, previous and new state, time, automatic source.
        $audit = AssessmentEvidenceAudit::where('student_id', $this->cara->id)->sole();
        $this->assertSame([$this->teacher->id, $quiz->id, 'not_yet_given', 'missing', ClassRecordService::AUTO_MISSING_SOURCE],
            [$audit->changed_by, $audit->assessment_id, $audit->old_status, $audit->new_status, $audit->source]);
        $this->assertNotNull($audit->changed_at);
    }

    public function test_3_4_unused_template_columns_and_unrelated_assessments_get_no_missing(): void
    {
        $quiz1 = $this->item('Quiz 1');
        $quiz2 = $this->item('Quiz 2');
        $this->item('PT 1', 'performance_task', 50); // a PT exists but is not being saved

        $this->save([$this->cell($quiz1, $this->ana, '15'), $this->cell($quiz1, $this->ben, null)])->assertOk();

        // Only Quiz 1 (the assessment being saved) got a Missing marker.
        $this->assertSame([$quiz1->id], AssessmentLearnerStatus::distinct()->pluck('assessment_id')->all());
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->evidenceState($quiz2, $this->ben), 'an untouched assessment keeps its blanks');

        // The eight empty numbered WW slots (and ten PT, no ST/TE) are placeholders, not assessments.
        $columns = (new ClassRecordService())->build($this->section, $this->subject, 1)['columns'];
        $this->assertCount(10, $columns['written_work']);
        $this->assertSame(8, count(array_filter($columns['written_work'], fn($i) => $i === null)));
        $this->assertSame([null, null, null], array_values($columns['exam_roles']));
        $this->assertSame(3, Assessment::count(), 'no assessment was created to fill the template');
    }

    public function test_5_an_omitted_cell_never_overwrites_a_stored_score(): void
    {
        $quiz = $this->item('Quiz 1');
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->ben->id, 'score' => 17]);

        // Ben's cell is not in the request (e.g. not loaded on the page).
        $this->save([$this->cell($quiz, $this->ana, '12')])->assertOk();

        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $quiz->id, 'student_id' => $this->ben->id, 'score' => 17]);
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->evidenceState($quiz, $this->cara), 'a learner not sent is not read as blank');
    }

    public function test_6_one_invalid_cell_prevents_every_write_including_automatic_missing(): void
    {
        $quiz = $this->item('Quiz 1');
        $before = $this->academicWrites();

        $this->save([$this->cell($quiz, $this->ana, '15'), $this->cell($quiz, $this->ben, null), $this->cell($quiz, $this->cara, '21')])
            ->assertStatus(422)->assertJsonStructure(['errors' => [$quiz->id . ':' . $this->cara->id]]);

        $this->assertSame($before, $this->academicWrites());
    }

    public function test_7_a_valid_score_replaces_missing_and_keeps_the_history(): void
    {
        $quiz = $this->item('Quiz 1');
        $this->save([$this->cell($quiz, $this->ana, null)])->assertOk();
        $concern = AcademicConcern::open()->forStudent($this->ana->id)->sole();

        $this->save([$this->cell($quiz, $this->ana, '19', null, true)])->assertOk()->assertJsonPath('changed', 1);

        $this->assertSame(AssessmentStatusService::COMPLETED, $this->evidenceState($quiz, $this->ana));
        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertSame(
            [['not_yet_given', 'missing'], ['missing', 'not_yet_given'], ['not_yet_given', 'completed']],
            AssessmentEvidenceAudit::where('student_id', $this->ana->id)->orderBy('id')->get()->map(fn($r) => [$r->old_status, $r->new_status])->all()
        );
        $this->assertSame(AcademicConcern::STATUS_RESOLVED, $concern->fresh()->status, 'the concern is re-evaluated from evidence');
    }

    public function test_9_a_retry_after_a_lost_response_changes_nothing_twice(): void
    {
        $quiz = $this->item('Quiz 1');
        $payload = [$this->cell($quiz, $this->ana, '14'), $this->cell($quiz, $this->ben, null)];

        $this->save($payload)->assertOk()->assertJsonPath('changed', 2);
        $after = [$this->academicWrites(), AcademicConcern::orderBy('id')->get()->map(fn($c) => [$c->id, $c->status, (string) $c->first_detected_at, (string) $c->last_evaluated_at])->all()];

        // The same request again (the browser never heard the first answer).
        $this->save($payload)->assertOk()->assertJsonPath('changed', 0);

        $this->assertSame($after, [$this->academicWrites(), AcademicConcern::orderBy('id')->get()->map(fn($c) => [$c->id, $c->status, (string) $c->first_detected_at, (string) $c->last_evaluated_at])->all()]);
    }

    public function test_10_a_blank_whose_cell_was_scored_elsewhere_meanwhile_is_a_conflict_not_an_overwrite(): void
    {
        $quiz = $this->item('Quiz 1');
        // The page loaded Ben blank; another tab then saved 16.
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->ben->id, 'score' => 16]);

        $this->save([$this->cell($quiz, $this->ana, '12'), $this->cell($quiz, $this->ben, null)])
            ->assertStatus(409)->assertJsonPath('conflicts.' . $quiz->id . ':' . $this->ben->id . '.current_score', '16');

        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $quiz->id, 'student_id' => $this->ben->id, 'score' => 16]);
        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $quiz->id, 'student_id' => $this->ana->id]);
    }

    // --- Live preview / running Initial Grade ------------------------------------

    public function test_11_12_one_quiz_gives_ps_ws_and_a_running_initial_grade_without_pt_or_exams(): void
    {
        $quiz = $this->item('Quiz 1', 'written_work', 20);

        $row = $this->preview([$this->cell($quiz, $this->ana, '16')])->assertOk()->assertJsonPath('preview', true)
            ->json('rows.' . $this->ana->id);

        // Term 1!O17 = ROUND(16/20*100, 2) = 80; P17 = ROUND(80*0.20, 2) = 16.
        $this->assertSame('16 / 20', $row['written_work:total']);
        $this->assertSame('80.00', $row['written_work:ps']);
        $this->assertSame('16.00', $row['written_work:ws']);
        // Running = the WS present, added; weights are not scaled to 100.
        $this->assertSame('16.00', $row['grade:initial']);
        $this->assertSame('running · partial', $row['grade:initial:note']);
        $this->assertSame('—', $row['performance_task:ps'], 'no PT is not 0%');
        $this->assertSame('—', $row['grade:transmuted'], 'no Term Grade from a partial term');
    }

    public function test_13_14_editing_a_value_changes_the_preview_and_the_preview_writes_nothing(): void
    {
        $quiz = $this->item('Quiz 1', 'written_work', 20);
        $before = $this->academicWrites();

        $first = $this->preview([$this->cell($quiz, $this->ana, '16')])->json('rows.' . $this->ana->id . '.grade:initial');
        $second = $this->preview([$this->cell($quiz, $this->ana, '10')])->json('rows.' . $this->ana->id . '.grade:initial');
        $blank = $this->preview([$this->cell($quiz, $this->ana, null)])->json('rows.' . $this->ana->id);

        $this->assertSame(['16.00', '10.00'], [$first, $second]);
        $this->assertSame('—', $blank['grade:initial'], 'a blank is no evidence, never 0');
        $this->assertSame('1 Missing', $blank['grade:evidence:note'], 'the blank reads as it will after Save');
        $this->assertSame($before, $this->academicWrites(), 'no score, marker, audit, concern, grade or log');
    }

    public function test_16_preview_and_saved_figures_agree(): void
    {
        $ww1 = $this->item('Quiz 1', 'written_work', 20);
        $ww2 = $this->item('Quiz 2', 'written_work', 15);
        $pt = $this->item('PT 1', 'performance_task', 50);
        AssessmentScore::create(['assessment_id' => $ww1->id, 'student_id' => $this->ana->id, 'score' => 13]);
        $draft = [$this->cell($ww1, $this->ana, '17', '13'), $this->cell($ww2, $this->ana, '0'), $this->cell($pt, $this->ana, '41'),
            $this->cell($ww1, $this->ben, null), $this->cell($ww2, $this->ben, '12')];

        $previewed = $this->preview($draft)->assertOk()->json('rows');
        $saved = $this->save($draft)->assertOk()->json('rows');

        foreach ([$this->ana->id, $this->ben->id] as $sid) {
            $this->assertSame($saved[$sid], $previewed[$sid]);
        }
    }

    public function test_17_missing_blank_and_zero_follow_the_documented_formula(): void
    {
        $q1 = $this->item('Quiz 1', 'written_work', 20);
        $q2 = $this->item('Quiz 2', 'written_work', 10);
        AssessmentScore::create(['assessment_id' => $q1->id, 'student_id' => $this->ana->id, 'score' => 15]);
        (new AssessmentStatusService())->markMissing($q2, $this->ben->id, $this->teacher->id);
        AssessmentScore::create(['assessment_id' => $q1->id, 'student_id' => $this->ben->id, 'score' => 15]);

        $rows = $this->preview([$this->cell($q2, $this->ana, '0'), $this->cell($q2, $this->cara, null)], [$this->ben->id])->json('rows');

        // Zero counts: (15 + 0) / (20 + 10) = 50.00% -> WS 10.00.
        $this->assertSame(['15 / 30', '50.00', '10.00'], [$rows[$this->ana->id]['written_work:total'], $rows[$this->ana->id]['written_work:ps'], $rows[$this->ana->id]['written_work:ws']]);
        // Missing (workbook D1, 2026-10-04): earns nothing, its HPS stays in
        // N14 — 15 / (20 + 10) = 50.00% -> WS 10.00. It is still a marker,
        // never a stored 0 (the same figure as Ana's recorded 0, by design).
        $this->assertSame(['15 / 30', '50.00', '10.00'], [$rows[$this->ben->id]['written_work:total'], $rows[$this->ben->id]['written_work:ps'], $rows[$this->ben->id]['written_work:ws']]);
        $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $q2->id, 'student_id' => $this->ben->id]);
        // A learner with only blanks: no evidence, no figure.
        $this->assertSame('—', $rows[$this->cara->id]['written_work:ps']);
    }

    /**
     * Complete term vs the workbook's own formulas (Term 1 row 17), every
     * item scored so the workbook's HPS-sum denominators equal the engine's:
     *   N = 16+18 = 34, N14 = 40      O = ROUND(34/40*100,2) = 85     P = ROUND(85*.2,2) = 17
     *   AA = 40, AA14 = 50            AB = 80                         AC = 40
     *   AG = ROUND(27/30*30,2) = 27   AH = ROUND(21/30*30,2) = 21     AI = ROUND(30/40*40,2) = 30
     *   AJ = (27+21+30)/(30+30+40)*100 = 78                           AK = ROUND(78*.3,2) = 23.4
     *   AL = 17 + 40 + 23.4 = 80.40   AM = XLOOKUP(AL, HELPER!C7:C47, D, , 1) -> the first band whose max >= AL
     */
    public function test_18_a_complete_term_matches_the_workbook_formulas_shares_and_transmutation(): void
    {
        // The 30/30/40 shares (ExamRoleSharesSeeder; not seeded by migration).
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);
        // The DO 015 table (HELPER!B7:D47) is seeded, not migrated.
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $items = [
            [$this->item('Quiz 1', 'written_work', 20), '16'], [$this->item('Quiz 2', 'written_work', 20), '18'],
            [$this->item('PT 1', 'performance_task', 50), '40'],
            [$this->item('ST 1', 'examination', 30, 'st1'), '27'], [$this->item('ST 2', 'examination', 30, 'st2'), '21'],
            [$this->item('TE', 'examination', 40, 'term_exam'), '30'],
        ];
        $row = $this->save(array_map(fn($p) => $this->cell($p[0], $this->ana, $p[1]), $items))->assertOk()->json('rows.' . $this->ana->id);

        $record = (new ClassRecordService())->build($this->section, $this->subject, 1);
        $this->assertSame(['st1' => 30.0, 'st2' => 30.0, 'term_exam' => 40.0], $record['exam_shares'], 'HELPER!AA:AC shares');

        $round = fn($v) => round($v, 2);
        $p = $round($round(34 / 40 * 100) * 0.20);
        $ac = $round($round(40 / 50 * 100) * 0.50);
        [$ag, $ah, $ai] = [$round(27 / 30 * 30), $round(21 / 30 * 30), $round(30 / 40 * 40)];
        $ak = $round((($ag + $ah + $ai) / 100 * 100) * 0.30);
        $al = $p + $ac + $ak;

        $this->assertSame(number_format($p, 2), $row['written_work:ws']);
        $this->assertSame(number_format($ac, 2), $row['performance_task:ws']);
        $this->assertSame([number_format($ag, 2), number_format($ah, 2), number_format($ai, 2)],
            [$row['examination:ws_st1'], $row['examination:ws_st2'], $row['examination:ws_term_exam']]);
        $this->assertSame(number_format($ak, 2), $row['examination:ws']);
        $this->assertSame(number_format($al, 2), $row['grade:initial']);
        $this->assertSame('provisional', $row['grade:initial:note']);
        $this->assertSame(number_format((new TransmutationService())->transmute($al, 'do015_2026'), 2), $row['grade:transmuted']);
        // HELPER!B25:D25 = 78.26 .. 79.43 -> 82; B24:D24 = 79.44 .. 80.61 -> 83.
        $this->assertSame('83.00', $row['grade:transmuted']);
    }

    public function test_23_a_low_running_grade_is_never_official_and_opens_no_failing_concern(): void
    {
        $quiz = $this->item('Quiz 1', 'written_work', 20);
        $this->item('PT 1', 'performance_task', 50);

        $row = $this->save([$this->cell($quiz, $this->ana, '2')])->assertOk()->json('rows.' . $this->ana->id);

        $this->assertSame('2.00', $row['grade:initial']);
        $this->assertNull((new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027')['computed_grade']);
        $this->assertSame(0, Grade::count());
        $this->assertSame(0, AcademicConcern::where('rule_key', OfficialFailingSubjectRule::KEY)->count());
    }

    public function test_22_24_saved_missing_opens_a_concern_and_resolving_one_leaves_the_other(): void
    {
        $q1 = $this->item('Quiz 1');
        $q2 = $this->item('Quiz 2');
        $this->save([$this->cell($q1, $this->ana, null), $this->cell($q2, $this->ana, null)])->assertOk();
        $this->assertSame(2, AcademicConcern::open()->forStudent($this->ana->id)->where('rule_key', MissingRequirementRule::KEY)->count());

        $this->save([$this->cell($q1, $this->ana, '11', null, true)])->assertOk();

        $open = AcademicConcern::open()->forStudent($this->ana->id)->get();
        $this->assertCount(1, $open, 'the other Missing concern stays open');
        $this->assertSame(MissingRequirementRule::contextKeyFor($q2->id), $open->sole()->context_key);
    }

    // --- Permissions ---------------------------------------------------------------

    public function test_19_21_preview_is_refused_exactly_like_a_save(): void
    {
        $quiz = $this->item('Quiz 1');
        $cells = [$this->cell($quiz, $this->ana, '10')];

        // The adviser can VIEW this class (advisory) but is not assigned to teach it.
        $this->preview($cells, [], $this->adviser)->assertForbidden();
        $this->save($cells, $this->adviser)->assertForbidden();
        $this->preview($cells, [], User::factory()->create(['role' => 'subject_teacher']))->assertForbidden();
        $this->preview($cells, [], User::factory()->principal()->create())->assertForbidden();
        $this->preview($cells, [], User::factory()->admin()->create())->assertForbidden();

        // A learner of another section, or an item of another subject, is refused.
        $outsider = Student::factory()->create(['section_id' => Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027'])->id]);
        $this->preview([$this->cell($quiz, $outsider, '10')])->assertStatus(422);
        $this->preview([['assessment_id' => $quiz->id, 'student_id' => $this->ana->id, 'score' => '25']])->assertStatus(422);
        $this->assertSame(0, AssessmentScore::count());
    }

    public function test_20_a_closed_term_refuses_preview_and_save(): void
    {
        $quiz = $this->item('Quiz 1');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        $this->preview([$this->cell($quiz, $this->ana, '10')])->assertStatus(423);
        $this->save([$this->cell($quiz, $this->ana, null)])->assertStatus(423);
        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    // --- Template layout -------------------------------------------------------------

    public function test_25_26_the_grid_follows_the_template_and_keeps_every_assessment(): void
    {
        $ww = collect(range(1, 11))->map(fn($i) => $this->item("Quiz {$i}", 'written_work', 10));
        $te = $this->item('Final Test', 'examination', 40, 'term_exam');

        $html = $this->actingAs($this->teacher)->get(route('teacher.class-record', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1]))
            ->assertOk()
            ->assertSee('Written / Oral Works (WWs)')->assertSee('Product / Performance Tasks (PTs)')->assertSee('Examinations (EXs)')
            ->assertSee('Highest Possible Score')->assertSee('Initial<br>Grade', false)->assertSee('Term<br>Grade', false)
            ->getContent();

        // Every WW item has a column (11 > the template's 10 — slot 11 is added, nothing hidden).
        foreach ($ww as $item) {
            $this->assertStringContainsString('data-item-head="' . $item->id . '"', $html);
        }
        $this->assertStringContainsString('<span class="cr-slot-label">11</span>', $html);
        // The Term Exam sits in the TE column; ST 1 / ST 2 are placeholders.
        $this->assertMatchesRegularExpression('/data-item-head="' . $te->id . '">\s*<span class="cr-slot-label">TE<\/span>/', $html);
        // Column order: WW … TOTAL PS WS | PT … | ST1 ST2 TE | WS ST1 WS ST2 WS TE PS WS.
        $order = ['written_work:total', 'written_work:ws', 'performance_task:total', 'performance_task:ws',
            'examination:ws_st1', 'examination:ws_st2', 'examination:ws_term_exam', 'examination:ps', 'examination:ws'];
        $positions = array_map(fn($k) => strpos($html, 'data-sum-head="' . $k . '"'), $order);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        // Learners grouped by recorded gender, MALE first, as in the workbook.
        $this->assertTrue(strpos($html, 'data-gender-group="male"') < strpos($html, 'data-gender-group="female"'));
        $this->assertTrue(strpos($html, 'data-student-row="' . $this->ben->id . '"') < strpos($html, 'data-student-row="' . $this->ana->id . '"'));
        // Placeholders carry no input; one input per learner per real item.
        $this->assertSame(3 * 12, preg_match_all('/<input[^>]*class="cr-cell[ "]/', $html));
    }
}
