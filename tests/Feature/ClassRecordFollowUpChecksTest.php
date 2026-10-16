<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentEvidenceAudit;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\AssessmentStatusService;
use App\Services\ClassRecordService;
use App\Services\GradingEngine;
use App\Services\SubjectAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Class Record follow-up checks (2026-10-04):
 *  - zero / sub-cent HPS refused on Add and Edit Assessment; legacy zero-HPS
 *    rows never crash, never produce NaN/INF and never fabricate a figure;
 *  - automatic-Missing save scope (explicit all-blank save, unrelated
 *    assessments untouched, a learner outside the roster refused);
 *  - D1–D3 now follow the client workbook (implementation decision
 *    2026-10-04 — a project requirement, not a recorded school approval).
 */
class ClassRecordFollowUpChecksTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
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
        $this->section = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        // core_academic = WW 20 / PT 50 / EX 30.
        $this->subject = Subject::factory()->create(['name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1, 2, 3]);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'ABAD', 'first_name' => 'ANA', 'gender' => 'female']);
        $this->ben = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'BAUTISTA', 'first_name' => 'BEN', 'gender' => 'male']);
        $this->cara = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'CRUZ', 'first_name' => 'CARA', 'gender' => 'female']);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
        // Exam shares 30/30/40 (HELPER!AA:AC fallback), as production seeds them.
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);
    }

    private function item(string $name, string $component = 'written_work', float $max = 20, ?string $role = null): Assessment
    {
        return Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => $max, 'uploaded_by' => $this->teacher->id]);
    }

    private function score(Assessment $a, Student $s, float $v): void
    {
        AssessmentScore::create(['assessment_id' => $a->id, 'student_id' => $s->id, 'score' => $v]);
    }

    private function cell(Assessment $a, Student $s, ?string $score, ?string $base = null, bool $baseMissing = false): array
    {
        return ['assessment_id' => $a->id, 'student_id' => $s->id, 'score' => $score, 'base_score' => $base, 'base_missing' => $baseMissing];
    }

    private function save(array $cells)
    {
        return $this->actingAs($this->teacher)->postJson(route('teacher.class-record.save'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1, 'cells' => $cells,
        ]);
    }

    private function preview(array $cells, array $studentIds = [])
    {
        return $this->actingAs($this->teacher)->postJson(route('teacher.class-record.preview'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1, 'cells' => $cells, 'student_ids' => $studentIds,
        ]);
    }

    private function grade(Student $s): array
    {
        return (new GradingEngine())->computeGrade($s, $this->subject, $this->section, 1, '2026-2027');
    }

    /** Every float in a structure is finite — no NaN or INF anywhere. */
    private function assertAllFinite(mixed $value, string $path = 'result'): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->assertAllFinite($v, $path . '.' . $k);
            }

            return;
        }
        if (is_float($value)) {
            $this->assertTrue(is_finite($value), "{$path} is not finite");
        }
    }

    // --- HPS validation ----------------------------------------------------------

    public function test_add_assessment_refuses_an_hps_of_zero_negative_blank_or_below_one_hundredth(): void
    {
        foreach (['0', '0.00', '0.004', '-5', ''] as $bad) {
            $this->actingAs($this->teacher)
                ->from(route('teacher.assessments.create', ['section_id' => $this->section->id, 'period' => 1, 'subject_id' => $this->subject->id]))
                ->post(route('teacher.assessments.store'), ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'grading_period' => 1,
                    'item_name' => 'Quiz ' . $bad, 'component' => 'written_work', 'max_score' => $bad])
                ->assertSessionHasErrorsIn('addItem', 'max_score');
        }
        $this->assertSame(0, Assessment::count(), 'no item with an unusable HPS was created');

        $this->actingAs($this->teacher)->post(route('teacher.assessments.store'), ['section_id' => $this->section->id, 'subject_id' => $this->subject->id,
            'grading_period' => 1, 'item_name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => '0.01'])->assertSessionHasNoErrors();
        $this->assertSame('0.01', (string) Assessment::sole()->max_score);
    }

    public function test_edit_assessment_refuses_an_hps_of_zero_and_keeps_the_stored_one(): void
    {
        $quiz = $this->item('Quiz 1', 'written_work', 20);

        $this->actingAs($this->teacher)->from(route('teacher.assessments.edit', $quiz))
            ->put(route('teacher.assessments.update', $quiz), ['max_score' => '0', 'component' => 'written_work',
                'loaded_fingerprint' => ClassRecordService::definitionFingerprint($quiz)])
            ->assertSessionHasErrorsIn('editItem', 'max_score');

        $this->assertSame('20.00', (string) $quiz->fresh()->max_score);
    }

    // --- Legacy zero-HPS data --------------------------------------------------------

    public function test_legacy_zero_hps_items_produce_no_percentage_no_nan_and_no_fabricated_grade(): void
    {
        // Rows that predate validation (or were written outside the app).
        $legacyWw = $this->item('Legacy WW', 'written_work', 0);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $legacyTe = $this->item('Legacy TE', 'examination', 0, 'term_exam');
        $this->score($legacyWw, $this->ana, 0);
        $this->score($pt, $this->ana, 40);
        $this->score($legacyTe, $this->ana, 0);

        $g = $this->grade($this->ana);
        $this->assertAllFinite($g);
        $this->assertNull($g['components']['written_work'], 'x / 0 is no percentage, never 0% or 100%');
        $this->assertNull($g['components']['examination']);
        $this->assertSame(80.0, $g['components']['performance_task']);
        $this->assertFalse($g['complete']);
        $this->assertNull($g['computed_grade']);
        $this->assertNull($g['transmuted_grade']);
        $this->assertSame(40.0, $g['running_grade'], 'only the PT Weighted Score (80% × 50) — nothing invented for WW/EX');
        $this->assertNotFalse(json_encode($g, JSON_THROW_ON_ERROR));

        // The page, save response, preview, copy and export all stay usable.
        $page = $this->actingAs($this->teacher)->get(route('teacher.class-record', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Highest Possible Score of 0', $page, 'the reason is stated, not a blank or a 0%');
        $this->assertStringNotContainsString('NAN', strtoupper(strip_tags($page)));
        $this->assertStringNotContainsString('INF', preg_replace('/INFO|INFORMATION|INFLUENCE/', '', strtoupper(strip_tags($page))));

        $row = $this->preview([$this->cell($legacyWw, $this->ben, '0')])->assertOk()->json('rows.' . $this->ben->id);
        $this->assertSame(['0 / 0', '—', '—', '—'], [$row['written_work:total'], $row['written_work:ps'], $row['written_work:ws'], $row['grade:initial']]);
        $this->preview([$this->cell($legacyWw, $this->ben, '1')])->assertStatus(422)
            ->assertJsonPath('errors.' . $legacyWw->id . ':' . $this->ben->id, 'A score cannot be above the highest possible score (0).');

        $saved = $this->save([$this->cell($legacyWw, $this->ben, '0'), $this->cell($legacyWw, $this->cara, null)])->assertOk()->json('rows');
        $this->assertSame('—', $saved[$this->ben->id]['written_work:ps']);

        $scope = ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1];
        $this->actingAs($this->teacher)->get(route('teacher.class-record.copy', $scope))->assertOk();
        $this->actingAs($this->teacher)->get(route('teacher.class-record.export', $scope))->assertOk();
    }

    public function test_a_zero_hps_item_beside_a_real_one_does_not_change_the_percentage(): void
    {
        $quiz = $this->item('Quiz 1', 'written_work', 20);
        $legacy = $this->item('Legacy WW', 'written_work', 0);
        $this->score($quiz, $this->ana, 15);
        $this->score($legacy, $this->ana, 0);

        $this->assertSame(75.0, $this->grade($this->ana)['components']['written_work'], '(15 + 0) / (20 + 0)');
    }

    public function test_subject_analysis_never_reports_zero_percent_for_a_zero_hps_component(): void
    {
        $legacy = $this->item('Legacy WW', 'written_work', 0);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $this->score($legacy, $this->ana, 0);
        $this->score($pt, $this->ana, 40);

        $summary = collect((new SubjectAnalysisService())->getSubjectSummaries('2026-2027', $this->section->id, 1))
            ->firstWhere('subject.id', $this->subject->id);

        $this->assertNull($summary['components']['written_work'], 'no figure — not 0.00% "Needs Attention"');
        $this->assertSame(80.0, $summary['components']['performance_task']['avg_percentage']);
    }

    // --- Automatic-Missing save scope ---------------------------------------------------

    public function test_an_all_blank_assessment_can_be_saved_as_missing_without_typing_any_score(): void
    {
        $quiz = $this->item('Quiz 1');
        $other = $this->item('Quiz 2');

        // What the page sends after "Save this assessment's blanks as Missing":
        // every blank cell of that one assessment, no score anywhere.
        $this->save([$this->cell($quiz, $this->ana, null), $this->cell($quiz, $this->ben, null), $this->cell($quiz, $this->cara, null)])
            ->assertOk()->assertJsonPath('changed', 3);

        foreach ([$this->ana, $this->ben, $this->cara] as $s) {
            $this->assertSame(AssessmentStatusService::MISSING, (new AssessmentStatusService())->statusFor($quiz, $s->id));
            $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, (new AssessmentStatusService())->statusFor($other, $s->id));
        }
        $this->assertSame(0, AssessmentScore::count(), 'Missing is never stored as 0');
        $this->assertSame(3, AssessmentEvidenceAudit::where('source', ClassRecordService::AUTO_MISSING_SOURCE)->count());
        $this->assertSame(3, AcademicConcern::open()->count());
    }

    public function test_saving_one_assessment_leaves_another_assessments_scores_markers_and_history_untouched(): void
    {
        $quiz1 = $this->item('Quiz 1');
        $quiz2 = $this->item('Quiz 2');
        $this->score($quiz2, $this->ana, 11);
        (new AssessmentStatusService())->markMissing($quiz2, $this->ben->id, $this->teacher->id);
        $snapshot = fn() => [
            AssessmentScore::where('assessment_id', $quiz2->id)->orderBy('id')->get(['id', 'student_id', 'score', 'updated_at'])->toArray(),
            AssessmentLearnerStatus::where('assessment_id', $quiz2->id)->orderBy('id')->get(['id', 'student_id', 'status', 'updated_at'])->toArray(),
            AssessmentEvidenceAudit::where('assessment_id', $quiz2->id)->count(),
        ];
        $before = $snapshot();

        $this->save([$this->cell($quiz1, $this->ana, '15'), $this->cell($quiz1, $this->ben, null), $this->cell($quiz1, $this->cara, null)])->assertOk();

        $this->assertSame($before, $snapshot());
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, (new AssessmentStatusService())->statusFor($quiz2, $this->cara->id), 'a blank of an unsaved assessment stays blank');
    }

    public function test_a_cell_for_a_learner_outside_the_roster_refuses_the_whole_batch(): void
    {
        $quiz = $this->item('Quiz 1');
        $outsider = Student::factory()->create(['section_id' => Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027'])->id]);

        $this->save([$this->cell($quiz, $this->ana, '15'), $this->cell($quiz, $outsider, null)])->assertStatus(422);

        $this->assertSame([0, 0], [AssessmentScore::count(), AssessmentLearnerStatus::count()]);
    }

    // --- D1–D3: workbook parity (implementation decision 2026-10-04) ---------------------
    // These replaced the pins of the former divergences. Full cell-by-cell
    // comparison with the workbook's own formulas: WorkbookParityTest.

    /** D1 — N14 = every HPS; N17 = numeric entries only (Missing/blank earn 0). */
    public function test_d1_ww_denominator_keeps_the_hps_of_missing_and_blank_entries(): void
    {
        $q1 = $this->item('Quiz 1', 'written_work', 20);
        $q2 = $this->item('Quiz 2', 'written_work', 10);
        $this->score($q1, $this->ben, 15);
        (new AssessmentStatusService())->markMissing($q2, $this->ben->id, $this->teacher->id);
        $this->score($q1, $this->cara, 15); // Cara's Quiz 2 cell: blank, never saved

        foreach ([$this->ben, $this->cara] as $s) {
            $g = $this->grade($s);
            $this->assertSame(50.0, $g['components']['written_work'], 'workbook: 15 / (20 + 10) = 50.00 (was 75.00)');
            $this->assertSame(10.0, $g['running_grade'], 'WS 10.00 (was 15.00)');
        }
        $this->assertSame(1, AssessmentLearnerStatus::count(), 'Missing stays a marker');
        $this->assertSame(2, AssessmentScore::count(), 'nothing was stored as 0');
    }

    /** D2 — AJ17 divides by every configured share; nothing is rescaled. */
    public function test_d2_exam_denominator_is_the_sum_of_the_configured_shares(): void
    {
        $te = $this->item('TE', 'examination', 40, 'term_exam');
        $this->score($te, $this->ana, 30);

        $g = $this->grade($this->ana);
        $this->assertSame(30.0, $g['components']['examination'], 'workbook AJ17 = 30 / (30+30+40) * 100 (was 75.00)');
        $this->assertSame(9.0, $g['partial_contributions']['examination'], 'AK17 = 9.00 (was 22.50)');

        $st1 = $this->item('ST 1', 'examination', 30, 'st1');
        (new AssessmentStatusService())->markMissing($st1, $this->ana->id, $this->teacher->id);
        $g = $this->grade($this->ana);
        $this->assertSame(30.0, $g['components']['examination'], 'a Missing ST 1 adds nothing (was: no figure)');
        $this->assertSame(['st1'], $g['component_detail']['examination']['unscored_roles']);
        $this->assertNull($g['computed_grade'], 'verification still waits for the ST 1 score');
    }

    /** D3 — AG:AI rounded to 2 dp first, AJ17 unrounded, AK17 = ROUND(AJ17 × weight, 2). */
    public function test_d3_exam_rounding_follows_the_workbook_order(): void
    {
        $this->score($this->item('ST 1', 'examination', 7, 'st1'), $this->ana, 0);
        $this->score($this->item('ST 2', 'examination', 9, 'st2'), $this->ana, 1);
        $this->score($this->item('TE', 'examination', 11, 'term_exam'), $this->ana, 5);

        $g = $this->grade($this->ana);
        $this->assertSame([0.0, 3.33, 18.18], [$g['exam_roles']['st1']['weighted'], $g['exam_roles']['st2']['weighted'], $g['exam_roles']['term_exam']['weighted']]);
        $this->assertSame(21.51, $g['components']['examination'], 'was 21.52');
        $this->assertSame(6.45, $g['partial_contributions']['examination'], 'was 6.46');
    }

    // --- Verified grades after the grading-rule change -----------------------------------

    private function verifiedGrade(Student $s, float $computed, float $grade): \App\Models\Grade
    {
        return \App\Models\Grade::create(['student_id' => $s->id, 'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
            'grading_period' => 1, 'school_year' => '2026-2027', 'grade' => $grade, 'computed_grade' => $computed,
            'is_verified' => true, 'verified_at' => now(), 'is_provisional' => false, 'encoded_by' => $this->teacher->id]);
    }

    private function boardRow(Student $s): array
    {
        return (new \App\Services\GradeVerificationBoard())->build($this->section, $this->subject, 1, null)['performance']
            ->first(fn($r) => $r['student']->id === $s->id);
    }

    public function test_a_grade_verified_under_the_old_formula_reads_changed_and_is_never_rewritten(): void
    {
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $q1 = $this->item('Quiz 1', 'written_work', 20);
        $q2 = $this->item('Quiz 2', 'written_work', 10);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $te = $this->item('TE', 'examination', 40, 'term_exam');
        $this->score($q1, $this->ana, 15);
        (new AssessmentStatusService())->markMissing($q2, $this->ana->id, $this->teacher->id);
        $this->score($pt, $this->ana, 40);
        $this->score($te, $this->ana, 30);
        // Verified before 2026-10-04: WW 15/20 = 75 -> 15.00, PT 40.00, EX 75 -> 22.50 = 77.50 -> 81.
        $official = $this->verifiedGrade($this->ana, 77.50, 81);
        $before = $official->fresh()->toArray();

        // Now (workbook): WW 15/30 = 50 -> 10.00, PT 40.00, EX 30/100*100 = 30 -> 9.00 = 59.00.
        $this->assertSame(59.0, $this->grade($this->ana)['computed_grade']);
        $row = $this->boardRow($this->ana);
        $this->assertSame('verified_changed', $row['verify_state']);
        $this->assertSame('The assessment evidence changed after this grade was verified.', $row['verify_reason']);
        $display = (new ClassRecordService())->build($this->section, $this->subject, 1)['display'][$this->ana->id];
        $this->assertSame('81.00', $display['grade:official']);
        $this->assertStringContainsString('evidence changed — re-verify', $display['grade:official:note']);

        // Reading pages, a save elsewhere and the monitoring sync rewrite nothing official.
        $this->save([$this->cell($q1, $this->ben, '12')])->assertOk();
        $this->assertSame($before, $official->fresh()->toArray(), 'the verified grade is never bulk-rewritten');
        $this->assertSame(0, AcademicConcern::where('rule_key', \App\Monitoring\Rules\OfficialFailingSubjectRule::KEY)->count(),
            'a failing-grade concern reads the OFFICIAL grade (81), never the new computed 59');
    }

    public function test_a_verified_grade_whose_evidence_is_no_longer_complete_is_stale_not_verified(): void
    {
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $q1 = $this->item('Quiz 1', 'written_work', 20);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $te = $this->item('TE', 'examination', 40, 'term_exam');
        $this->score($q1, $this->ana, 16);
        $this->score($pt, $this->ana, 40);
        $this->score($te, $this->ana, 30);
        $g = $this->grade($this->ana);
        $this->verifiedGrade($this->ana, $g['computed_grade'], $g['transmuted_grade']);
        $this->assertSame('verified', $this->boardRow($this->ana)['verify_state']);

        // ST 1 is given afterwards; Ana has no score on it yet.
        $this->item('ST 1', 'examination', 30, 'st1');
        $row = $this->boardRow($this->ana);
        $this->assertSame('verified_stale', $row['verify_state'], 'never shown as a current verification');
        $this->assertStringStartsWith('Verified earlier, but the current evidence is not complete', $row['verify_reason']);
        $this->assertStringContainsString('ST1', $row['verify_reason']);
        $this->assertStringContainsString('evidence changed — re-verify',
            (new ClassRecordService())->build($this->section, $this->subject, 1)['display'][$this->ana->id]['grade:official:note']);
    }

    public function test_reports_and_learner_results_flag_a_stale_official_grade_without_rewriting_it(): void
    {
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $q1 = $this->item('Quiz 1', 'written_work', 20);
        $q2 = $this->item('Quiz 2', 'written_work', 10);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $te = $this->item('TE', 'examination', 40, 'term_exam');
        foreach ([[$q1, 15], [$q2, 10], [$pt, 40], [$te, 30]] as [$i, $v]) {
            $this->score($i, $this->ana, $v);
        }
        $g = $this->grade($this->ana);
        $official = $this->verifiedGrade($this->ana, $g['computed_grade'], $g['transmuted_grade']);
        $summary = fn() => \App\Services\SubjectResultSummary::fromGrades(\App\Models\Grade::with('subject')->where('student_id', $this->ana->id)->get());
        $this->assertSame(0, $summary()['stale']);
        $result = fn() => (new \App\Services\LearnerResultsService())->forSection($this->section, collect([$this->ana]), collect([$this->subject]), 1)[$this->ana->id][$this->subject->id];
        $this->assertFalse($result()['stale']);

        // A score changes after verification.
        AssessmentScore::where('assessment_id', $q2->id)->where('student_id', $this->ana->id)->sole()->update(['score' => 2]);
        $before = $official->fresh()->toArray();

        $this->assertSame(1, $summary()['stale']);
        $this->assertSame(['General Mathematics'], $summary()['stale_subjects']);
        $this->assertTrue($result()['stale']);
        $this->assertStringEndsWith('· evidence changed — re-verify', $result()['label']);
        $this->assertSame($before, $official->fresh()->toArray(), 'the official record is kept as verified');
    }

    // --- Export == the displayed saved figures ---------------------------------------------

    public function test_the_export_carries_the_displayed_figures_for_running_and_complete_rows(): void
    {
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $q1 = $this->item('Quiz 1', 'written_work', 20);
        $q2 = $this->item('Quiz 2', 'written_work', 10);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $st1 = $this->item('ST 1', 'examination', 7, 'st1');
        $st2 = $this->item('ST 2', 'examination', 9, 'st2');
        $te = $this->item('TE', 'examination', 11, 'term_exam');
        $this->save([
            $this->cell($q1, $this->ana, '15'), $this->cell($q2, $this->ana, null), $this->cell($pt, $this->ana, '41'),
            $this->cell($st1, $this->ana, '0'), $this->cell($st2, $this->ana, '1'), $this->cell($te, $this->ana, '5'),
            $this->cell($q1, $this->ben, '18'), $this->cell($q2, $this->ben, '9'),
        ])->assertOk();

        $record = (new ClassRecordService())->build($this->section, $this->subject, 1);
        $sheet = (new \App\Services\ClassRecordExport())->build($record)->getActiveSheet();
        $letters = collect(range(1, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn())))
            ->map(fn($c) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c));
        $headers = $letters->mapWithKeys(fn($l) => [$l => (string) $sheet->getCell($l . '13')->getValue()]);
        $fmt = fn($v) => $v === null ? '—' : number_format((float) $v, 2);

        foreach ([$this->ana, $this->ben] as $learner) {
            $r = collect(range(16, $sheet->getHighestRow()))->first(fn($n) => $sheet->getCell('C' . $n)->getValue() === $learner->lrn);
            $this->assertNotNull($r, 'learner row');
            $d = $record['display'][$learner->id];
            $at = fn(string $h, int $nth = 0) => $sheet->getCell($headers->filter(fn($v) => $v === $h)->keys()->values()[$nth] . $r)->getValue();
            $this->assertSame([$d['written_work:ps'], $d['written_work:ws']], [$fmt($at('PS', 0)), $fmt($at('WS', 0))], 'WW');
            $this->assertSame([$d['performance_task:ps'], $d['performance_task:ws']], [$fmt($at('PS', 1)), $fmt($at('WS', 1))], 'PT');
            $this->assertSame([$d['examination:ws_st1'], $d['examination:ws_st2'], $d['examination:ws_term_exam']],
                [$fmt($at('WS ST1')), $fmt($at('WS ST2')), $fmt($at('WS TE'))], 'WS ST');
            $this->assertSame([$d['examination:ps'], $d['examination:ws']], [$fmt($at('PS', 2)), $fmt($at('WS', 2))], 'EX');
            $this->assertSame([$d['grade:initial'], $d['grade:transmuted']], [$fmt($at('INITIAL GRADE')), $fmt($at('TERM GRADE'))], 'grades');
            $this->assertSame($d['grade:initial:note'], $at('Initial Grade status'));
        }
        // Ana complete (provisional), Ben running (WW only): the Term Grade only for Ana.
        $this->assertSame('provisional', $record['display'][$this->ana->id]['grade:initial:note']);
        $this->assertSame('running · partial', $record['display'][$this->ben->id]['grade:initial:note']);
        $this->assertSame('6.45', $record['display'][$this->ana->id]['examination:ws'], 'D3 figure, exported as shown');
    }
}
