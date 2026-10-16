<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\DepedSubjectCatalog;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AssessmentStatusService;
use App\Services\ClassRecordService;
use App\Services\GradingEngine;
use App\Services\TransmutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkbookOracle;
use Tests\TestCase;

/**
 * Workbook parity (D1–D3, implementation decision 2026-10-04): every figure
 * the engine produces is compared with the client workbook's OWN formulas
 * evaluated by a spreadsheet engine (Tests\Support\WorkbookOracle) — never
 * with numbers produced by the code under test. A few literal values from
 * the work order are asserted as well (D1 50.00/10.00, D2 30.00/9.00, D3 6.45).
 */
class WorkbookParityTest extends TestCase
{
    use RefreshDatabase;

    private const CORE = ['ww' => 20.0, 'pt' => 50.0, 'ex' => 30.0];
    private const SHARES = ['st1' => 30.0, 'st2' => 30.0, 'term_exam' => 40.0];

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $this->teacher = User::factory()->create(['role' => 'subject_teacher']);
    }

    /**
     * One learner in a fresh class with the given evidence. Entries are
     * [HPS, score] with score a number, 'M' (a saved Missing marker) or null
     * (blank, never saved).
     *
     * @return array{grade: array, display: array<string,string>}
     */
    private function engine(array $ww, array $pt, array $exam = [], string $group = 'core_academic', ?int $catalogId = null): array
    {
        $section = Section::factory()->create(['grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['type' => $group === 'core_academic' ? 'core' : 'elective', 'grade_level' => 11, 'subject_group' => $group, 'catalog_id' => $catalogId]);
        $learner = Student::factory()->create(['section_id' => $section->id]);
        $statuses = new AssessmentStatusService();
        $make = function (string $component, float $hps, mixed $score, ?string $role = null, int $n = 0) use ($section, $subject, $learner, $statuses) {
            $item = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component . ' ' . ($role ?? $n), 'component' => $component, 'exam_role' => $role, 'max_score' => $hps, 'uploaded_by' => $this->teacher->id]);
            if ($score === 'M') {
                $statuses->markMissing($item, $learner->id, $this->teacher->id);
            } elseif ($score !== null) {
                AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $learner->id, 'score' => $score]);
            }
        };
        foreach ($ww as $i => [$hps, $score]) {
            $make('written_work', $hps, $score, null, $i + 1);
        }
        foreach ($pt as $i => [$hps, $score]) {
            $make('performance_task', $hps, $score, null, $i + 1);
        }
        foreach ($exam as $role => [$hps, $score]) {
            $make('examination', $hps, $score, $role);
        }

        return [
            'grade'   => (new GradingEngine())->computeGrade($learner, $subject, $section, 1, '2026-2027'),
            'display' => (new ClassRecordService())->build($section, $subject, 1)['display'][$learner->id],
        ];
    }

    /** "" -> null; numbers rounded to the 2 dp the cell shows. */
    private static function cell(mixed $v): ?float
    {
        return $v === '' || $v === null ? null : round((float) $v, 2);
    }

    /** Engine == workbook for every cell of the row. */
    private function assertParity(array $wb, array $grade, string $case): void
    {
        $roles = $grade['exam_roles'];
        $pairs = [
            'O17 WW PS'   => [$wb['O17'], $grade['components']['written_work']],
            'P17 WW WS'   => [$wb['P17'], $grade['partial_contributions']['written_work'] ?? null],
            'AB17 PT PS'  => [$wb['AB17'], $grade['components']['performance_task']],
            'AC17 PT WS'  => [$wb['AC17'], $grade['partial_contributions']['performance_task'] ?? null],
            'AG17 WS ST1' => [$wb['AG17'], $roles['st1']['weighted'] ?? null],
            'AH17 WS ST2' => [$wb['AH17'], $roles['st2']['weighted'] ?? null],
            'AI17 WS TE'  => [$wb['AI17'], $roles['term_exam']['weighted'] ?? null],
            'AJ17 EX PS'  => [$wb['AJ17'], $grade['components']['examination']],
            'AK17 EX WS'  => [$wb['AK17'], $grade['partial_contributions']['examination'] ?? null],
        ];
        foreach ($pairs as $label => [$expected, $actual]) {
            $this->assertSame(self::cell($expected), $actual === null ? null : round($actual, 2), "{$case}: {$label}");
        }
        // AL17 is filled exactly when every required Weighted Score exists.
        $this->assertSame($wb['AL17'] !== '', $grade['workbook_complete'], "{$case}: AL17 filled");
        if ($wb['AL17'] !== '') {
            $this->assertSame(self::cell($wb['AL17']), $grade['running_grade'], "{$case}: AL17 Initial Grade");
        }
    }

    // --- D1: WW / PT denominator ------------------------------------------------------

    public function test_d1_missing_keeps_its_hps_in_the_denominator_as_the_work_order_example(): void
    {
        $ww = [[20, 15], [10, 'M']];
        $wb = WorkbookOracle::row(self::CORE, self::SHARES, $ww, []);
        $e = $this->engine($ww, []);

        $this->assertSame([50.0, 10.0], [$wb['O17'], $wb['P17']], 'workbook: 15 / (20 + 10) = 50.00, WS 10.00');
        $this->assertParity($wb, $e['grade'], 'D1 Missing');
        $this->assertSame(['15 / 30', '50.00', '10.00'], [$e['display']['written_work:total'], $e['display']['written_work:ps'], $e['display']['written_work:ws']]);
    }

    public function test_d1_unsaved_blank_recorded_zero_all_missing_unused_slots_and_zero_hps(): void
    {
        $cases = [
            'unsaved blank'      => [[20, 15], [10, null]],
            'recorded zero'      => [[20, 15], [10, 0]],
            'all Missing'        => [[20, 'M'], [10, 'M']],
            'all blank'          => [[20, null], [10, null]],
            'zero only'          => [[20, 0], [10, 0]],
            'legacy zero HPS'    => [[0, 0]],
            'zero HPS + real'    => [[0, 0], [20, 15]],
        ];
        foreach ($cases as $case => $ww) {
            $wb = WorkbookOracle::row(self::CORE, self::SHARES, $ww, []);
            $this->assertParity($wb, $this->engine($ww, [])['grade'], $case);
        }
        // Unused template slots: two assessments fill slots 1–2; slots 3–10 add nothing.
        $wb = WorkbookOracle::row(self::CORE, self::SHARES, [[20, 15], [10, 'M']], []);
        $this->assertSame(30.0, (float) $wb['N14']);
        $this->assertSame(30.0, $this->engine([[20, 15], [10, 'M']], [])['grade']['component_detail']['written_work']['possible']);
        // No numeric score at all: no PS (workbook COUNT guard), never 0.
        $this->assertNull($this->engine([[20, 'M'], [10, null]], [])['grade']['components']['written_work']);
    }

    // --- D2: Examination denominator ----------------------------------------------------

    public function test_d2_term_exam_only_divides_by_every_configured_share(): void
    {
        $exam = ['term_exam' => [40, 30]];
        $wb = WorkbookOracle::row(self::CORE, self::SHARES, [], [], $exam);
        $e = $this->engine([], [], $exam);

        $this->assertSame([30.0, 30.0, 9.0], [(float) $wb['AI17'], (float) $wb['AJ17'], (float) $wb['AK17']], 'workbook: AI 30.00, AJ 30.00, AK 9.00');
        $this->assertParity($wb, $e['grade'], 'D2 TE only');
        $this->assertSame(['30.00', '9.00'], [$e['display']['examination:ps'], $e['display']['examination:ws']]);
        $this->assertNotSame(75.0, $e['grade']['components']['examination'], 'never rescaled to 75.00');
    }

    public function test_d2_partially_scored_examinations_and_guards(): void
    {
        $cases = [
            'ST1 Missing, TE scored'     => ['st1' => [30, 'M'], 'term_exam' => [40, 30]],
            'ST1 blank, ST2 + TE scored' => ['st1' => [30, null], 'st2' => [30, 21], 'term_exam' => [40, 30]],
            'all three scored'           => ['st1' => [30, 27], 'st2' => [30, 21], 'term_exam' => [40, 30]],
            'every type Missing'         => ['st1' => [30, 'M'], 'st2' => [30, 'M'], 'term_exam' => [40, 'M']],
            'zero HPS exam (legacy)'     => ['term_exam' => [0, 0]],
            'zero scores'                => ['st1' => [30, 0], 'term_exam' => [40, 0]],
        ];
        foreach ($cases as $case => $exam) {
            $wb = WorkbookOracle::row(self::CORE, self::SHARES, [[20, 18]], [[50, 40]], $exam);
            $this->assertParity($wb, $this->engine([[20, 18]], [[50, 40]], $exam)['grade'], $case);
        }
        // A type given but not scored: the workbook already fills AL17; the
        // system shows that figure as RUNNING and keeps it out of verification.
        $e = $this->engine([[20, 18]], [[50, 40]], ['st1' => [30, 'M'], 'term_exam' => [40, 30]]);
        $this->assertTrue($e['grade']['workbook_complete']);
        $this->assertFalse($e['grade']['complete']);
        $this->assertNull($e['grade']['computed_grade']);
        $this->assertSame('running · partial', $e['display']['grade:initial:note']);
        $this->assertSame('—', $e['display']['grade:transmuted']);
    }

    // --- D3: rounding order ----------------------------------------------------------------

    public function test_d3_each_type_is_rounded_before_the_sum(): void
    {
        $exam = ['st1' => [7, 0], 'st2' => [9, 1], 'term_exam' => [11, 5]];
        $wb = WorkbookOracle::row(self::CORE, self::SHARES, [], [], $exam);
        $e = $this->engine([], [], $exam);

        $this->assertSame([0.0, 3.33, 18.18, 6.45], [(float) $wb['AG17'], (float) $wb['AH17'], (float) $wb['AI17'], (float) $wb['AK17']]);
        $this->assertParity($wb, $e['grade'], 'D3');
        $this->assertSame('6.45', $e['display']['examination:ws'], 'not 6.46 (rounding once at the end)');
    }

    public function test_d3_a_sweep_of_exam_scores_matches_the_workbook_cell_by_cell(): void
    {
        foreach ([[0, 1, 5], [0, 2, 4], [3, 7, 6], [7, 9, 11], [1, 1, 1], [6, 8, 10]] as [$a, $b, $c]) {
            $exam = ['st1' => [7, $a], 'st2' => [9, $b], 'term_exam' => [11, $c]];
            $wb = WorkbookOracle::row(self::CORE, self::SHARES, [[30, 23]], [[45, 37]], $exam);
            $this->assertParity($wb, $this->engine([[30, 23]], [[45, 37]], $exam)['grade'], "sweep {$a}/{$b}/{$c}");
        }
    }

    // --- Profiles ------------------------------------------------------------------------

    public function test_configured_profiles_no_exam_group_and_term_exam_only_catalog_row(): void
    {
        // research_innovation: WW 40 / PT 60, no Examination ($AD$12 = 0).
        $wb = WorkbookOracle::row(['ww' => 40.0, 'pt' => 60.0, 'ex' => null], self::SHARES, [[25, 20]], [[60, 45]]);
        $e = $this->engine([[25, 20]], [[60, 45]], [], 'research_innovation');
        $this->assertParity($wb, $e['grade'], 'no-exam group');
        $this->assertTrue($e['grade']['complete']);

        // A catalog row with TE only (st1/st2 "" in HELPER!AA:AB, TE 100).
        $catalog = DepedSubjectCatalog::where('course_title', 'Arts Apprenticeship – Music')->sole();
        $this->assertSame([15.0, 70.0, 15.0, null, null, 100.0], [(float) $catalog->ww_weight, (float) $catalog->pt_weight, (float) $catalog->ex_weight,
            $catalog->st1_share, $catalog->st2_share, (float) $catalog->te_share]);
        $exam = ['term_exam' => [50, 40]];
        $wb = WorkbookOracle::row(['ww' => 15.0, 'pt' => 70.0, 'ex' => 15.0], ['st1' => null, 'st2' => null, 'term_exam' => 100.0], [[20, 17]], [[40, 31]], $exam);
        $this->assertParity($wb, $this->engine([[20, 17]], [[40, 31]], $exam, 'field_exposure', $catalog->id)['grade'], 'TE-only catalog');
    }

    public function test_a_running_grade_with_only_written_work(): void
    {
        $wb = WorkbookOracle::row(self::CORE, self::SHARES, [[20, 16]], []);
        $e = $this->engine([[20, 16]], []);
        $this->assertParity($wb, $e['grade'], 'WW only');
        $this->assertSame('', $wb['AL17'], 'the workbook shows no Initial Grade yet');
        $this->assertSame(16.0, $e['grade']['running_grade'], 'the system shows a running 16.00 (WW WS), not rescaled');
        $this->assertSame('running · partial', $e['display']['grade:initial:note']);
    }

    // --- Transmutation ----------------------------------------------------------------------
    // AM17 = XLOOKUP(AL17, HELPER!$C$7:$C$47, HELPER!$D$7:$D$47, , 1) — PhpSpreadsheet
    // 1.30 does not evaluate XLOOKUP (test_the_evaluator_does_not_implement_xlookup),
    // so the oracle reproduces match mode 1 over the STORED maxima of the file.

    public function test_the_evaluator_does_not_implement_xlookup_so_the_oracle_reproduces_it(): void
    {
        $xlookup = \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance()->getFunctions()['XLOOKUP'] ?? null;
        $this->assertSame([\PhpOffice\PhpSpreadsheet\Calculation\Functions::class, 'DUMMY'], $xlookup['functionCall'] ?? null);
        // Match mode 1 = exact, else the next LARGER value — over a descending column.
        $this->assertSame(75.0, WorkbookOracle::termGrade(70.0));
        $this->assertSame(100.0, WorkbookOracle::termGrade(100.0));
        $this->assertSame('', WorkbookOracle::termGrade(''));
    }

    /**
     * Independent check of the XLOOKUP transcription with a lookup the
     * evaluator implements natively (INDEX/MATCH -1 over the same stored
     * maxima): every band edge ±0.01, the work order's nine boundary values,
     * and the noisy sum 0 + 46 + 28.71. Both are EVALUATOR results (IEEE
     * doubles), not Excel results.
     */
    public function test_the_evaluators_native_match_agrees_with_the_xlookup_transcription(): void
    {
        $values = [69.98, 69.99, 70.00, 71.16, 71.17, 71.18, 74.70, 74.71, 74.72, 0 + 46 + 28.71];
        foreach (WorkbookOracle::transmutationTable() as [, $max]) {
            $edge = round($max, 2);
            array_push($values, $edge, max(0, round($edge - 0.01, 2)), min(100, round($edge + 0.01, 2)));
        }
        foreach (array_unique($values, SORT_REGULAR) as $g) {
            $this->assertSame(WorkbookOracle::termGrade($g), (float) WorkbookOracle::termGradeByMatch($g), 'g = ' . var_export($g, true));
        }
        // The two stored-precision maxima and the noisy sum, through the native function.
        $this->assertSame(75.0, (float) WorkbookOracle::termGradeByMatch(69.99));
        $this->assertSame(76.0, (float) WorkbookOracle::termGradeByMatch(71.17));
        $this->assertSame(79.0, (float) WorkbookOracle::termGradeByMatch(0 + 46 + 28.71), 'evaluator; Excel also gives 79 (results-2026-10-06)');
        $this->assertSame(78.0, (float) WorkbookOracle::termGradeByMatch(74.71));
    }

    /**
     * DIRECT numeric inputs: for EVERY 2-decimal Initial Grade 0.00–100.00,
     * the system's Term Grade equals the workbook's XLOOKUP over the stored
     * maxima — including 69.99 -> 75 and 71.17 -> 76, where C33 / C32 are
     * stored as 69.9899999999999 / 71.1699999999999.
     */
    public function test_every_two_decimal_initial_grade_transmutes_exactly_as_the_workbook_lookup(): void
    {
        $table = WorkbookOracle::transmutationTable();
        $lookup = function (float $g) use ($table) {
            $best = null;
            foreach ($table as [, $max, $t]) {
                if ($max >= $g && ($best === null || $max < $best[0])) {
                    $best = [$max, $t];
                }
            }

            return $best[1];
        };
        $service = new TransmutationService();
        $mismatches = [];
        for ($c = 0; $c <= 10000; $c++) {
            $g = $c / 100;
            if ($service->transmute($g, 'do015_2026') !== $lookup($g)) {
                $mismatches[] = sprintf('%.2f', $g);
            }
        }
        $this->assertSame([], $mismatches);

        $direct = ['69.98' => 74.0, '69.99' => 75.0, '70.00' => 75.0, '71.16' => 75.0, '71.17' => 76.0, '71.18' => 76.0, '74.70' => 78.0, '74.71' => 78.0, '74.72' => 79.0];
        foreach ($direct as $g => $t) {
            $this->assertSame($t, WorkbookOracle::termGrade((float) $g), "workbook {$g}");
            $this->assertSame($t, $service->transmute((float) $g, 'do015_2026'), "system {$g}");
        }
    }

    /**
     * Initial Grades PRODUCED BY COMPONENT SUMS. Inputs [WW/100, PT/100, ST1, ST2,
     * TE (each /100)], core 20/50/30, shares 30/30/40. The system sums the
     * 2-dp Weighted Scores in exact hundredths; the workbook's AL17 =
     * SUM(P17,AC17,AK17) is a binary double that can land a hair above or below
     * the 2-dp value ("noisy" rows). Every case agrees EXCEPT a noisy sum
     * landing just ABOVE an exactly-stored band maximum: 0 + 46 + 28.71 is the
     * double 74.71000000000001, which the evaluator's strict lookup sends to 79
     * (the system: 74.71 -> 78). Microsoft Excel gives the same 79 (confirmed
     * 2026-10-06, see test_component_sums_match_excel_except_the_confirmed_binary_sum_case);
     * whether the system should reproduce it is the one open question.
     */
    public function test_component_sums_at_band_edges_match_the_workbook_except_the_noisy_sum(): void
    {
        $cases = [
            '69.98' => [[0, 82, 90, 100, 99], [2, 80, 98, 100, 98]],
            '69.99' => [[0, 81, 97, 100, 98], [1, 80, 99, 100, 99]],
            '70.00' => [[0, 80, 100, 100, 100], null],
            '71.16' => [[0, 84, 92, 100, 99], [77, 100, 0, 0, 48]],
            '71.17' => [[0, 83, 99, 100, 98], [73, 100, 0, 1, 54]],
            '71.18' => [[0, 85, 88, 100, 98], [4, 81, 100, 100, 99]],
            '74.70' => [[0, 90, 98, 100, 99], [4, 89, 96, 100, 98]],
            '74.71' => [[2, 90, 95, 100, 98], [0, 92, 87, 100, 99]],
            '74.72' => [[0, 91, 94, 100, 98], null],
        ];
        foreach ($cases as $initial => $combos) {
            foreach (array_filter($combos) as $kind => [$w, $p, $a, $b, $c]) {
                $label = "{$initial} " . ($kind === 0 ? 'exact' : 'noisy');
                $exam = ['st1' => [100, $a], 'st2' => [100, $b], 'term_exam' => [100, $c]];
                $wb = WorkbookOracle::row(self::CORE, self::SHARES, [[100, $w]], [[100, $p]], $exam);
                $e = $this->engine([[100, $w]], [[100, $p]], $exam);
                $this->assertParity($wb, $e['grade'], $label);
                $this->assertSame((float) $initial, $e['grade']['computed_grade'], "{$label}: initial grade");
                $this->assertSame($kind === 0, (float) $wb['AL17'] === (float) $initial, "{$label}: the workbook sum is " . ($kind === 0 ? 'exact' : 'noisy'));
                if ($label === '74.71 noisy') {
                    $this->assertSame(79.0, (float) $wb['AM17'], 'evaluator: 74.71000000000001 -> 79 (Excel: also 79)');
                    $this->assertSame(78.0, $e['grade']['transmuted_grade'], 'system: exact 74.71 -> 78');
                    continue;
                }
                $this->assertSame((float) $wb['AM17'], $e['grade']['transmuted_grade'], "{$label}: Term Grade");
            }
        }
    }

    /**
     * EVERY distinct catalog profile (weights + shares, HELPER!W:AC) × 30 seeded
     * evidence sets (HPS, numeric / Missing / blank entries, exam types per the
     * profile's shares), engine vs the workbook formulas (evaluator), cell by
     * cell and Term Grade. The only allowed Term Grade difference is the
     * binary-sum class (AL17 a hair above an exact band maximum) — detected,
     * proven to be that, and counted, never skipped silently.
     */
    public function test_every_catalog_profile_matches_the_workbook_formulas_across_a_seeded_sweep(): void
    {
        $profiles = DepedSubjectCatalog::where('teacher_supplied', false)->get()
            ->groupBy(fn($c) => implode('|', [$c->ww_weight, $c->pt_weight, $c->ex_weight, $c->st1_share, $c->st2_share, $c->te_share]))
            ->map->first()->values();
        $this->assertCount(9, $profiles, 'distinct HELPER profiles');
        $service = new TransmutationService();
        mt_srand(20261006);
        $cases = $binarySum = 0;
        foreach ($profiles as $p) {
            $num = fn($v) => $v === null ? null : (float) $v;
            $shares = ['st1' => $num($p->st1_share), 'st2' => $num($p->st2_share), 'term_exam' => $num($p->te_share)];
            for ($i = 0; $i < 30; $i++) {
                $entry = function () {
                    $hps = (float) mt_rand(5, 60);
                    $r = mt_rand(1, 10);
                    return [$hps, $r === 1 ? 'M' : ($r === 2 ? null : (float) mt_rand(0, (int) $hps))];
                };
                $ww = array_map(fn() => $entry(), range(1, mt_rand(1, 4)));
                $pt = array_map(fn() => $entry(), range(1, mt_rand(1, 3)));
                $exam = [];
                if ($p->ex_weight !== null) {
                    foreach ($shares as $role => $share) {
                        if ($share !== null && mt_rand(1, 6) > 1) {
                            $exam[$role] = $entry();
                        }
                    }
                }
                $wb = WorkbookOracle::row(['ww' => (float) $p->ww_weight, 'pt' => (float) $p->pt_weight, 'ex' => $num($p->ex_weight)], $shares, $ww, $pt, $exam);
                $g = $this->engine($ww, $pt, $exam, 'core_academic', $p->id)['grade'];
                $label = "profile {$p->ww_weight}/{$p->pt_weight}/" . ($p->ex_weight ?? '-') . " case {$i}";
                $this->assertParity($wb, $g, $label);
                $cases++;
                if ($g['computed_grade'] === null || $wb['AL17'] === '') {
                    continue;
                }
                if ((float) $wb['AM17'] !== $g['transmuted_grade']) {
                    // Only the binary-sum class may differ: AL17 above its exact 2-dp value,
                    // that value a band maximum, and the system = the lookup of the exact value.
                    $this->assertGreaterThan($g['computed_grade'], (float) $wb['AL17'], "{$label}: AL17 noise");
                    $this->assertLessThan(1e-9, (float) $wb['AL17'] - $g['computed_grade'], "{$label}: AL17 noise");
                    $this->assertSame($service->transmute($g['computed_grade'], 'do015_2026'), $g['transmuted_grade'], $label);
                    $this->assertSame($g['transmuted_grade'] + 1, (float) $wb['AM17'], "{$label}: one band up");
                    $binarySum++;
                }
            }
        }
        $this->assertSame(270, $cases);
        fwrite(STDERR, "\n[profile sweep] {$cases} cases, {$binarySum} binary-sum Term Grade difference(s)\n");
    }

    // --- Results VERIFIED IN MICROSOFT EXCEL -----------------------------------------------------
    // docs/excel-validation/run-in-excel.ps1 evaluated the client workbook's own formulas in
    // Microsoft Excel 16.0 (build 20430) on 2026-10-06 and wrote these CSVs. Unlike the oracle
    // above (PhpSpreadsheet + a transcribed XLOOKUP), these are Excel's own outputs.

    /** @return list<array<string, string>> */
    private static function excelCsv(string $file): array
    {
        $lines = file(base_path('docs/excel-validation/results-2026-10-06/' . $file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $head = str_getcsv(ltrim(array_shift($lines), "\u{FEFF}"));

        return array_map(fn($l) => array_combine($head, str_getcsv($l)), $lines);
    }

    public function test_every_two_decimal_initial_grade_matches_excels_own_xlookup(): void
    {
        $rows = self::excelCsv('direct-lookup-0-100.csv');
        $this->assertCount(10001, $rows);
        $service = new TransmutationService();
        $mismatches = [];
        foreach ($rows as $row) {
            if ($service->transmute((float) $row['initial_grade'], 'do015_2026') !== (float) $row['term_grade']) {
                $mismatches[] = $row['initial_grade'];
            }
        }
        $this->assertSame([], $mismatches, 'system = Excel for every direct 2-dp Initial Grade, 69.99 -> 75 and 71.17 -> 76 included');
    }

    /**
     * Initial Grades produced by component sums, in Excel. Every Weighted Score
     * and every Term Grade matches EXCEPT the one confirmed discrepancy: Excel's
     * SUM(0, 46, 28.71) is 74.710000000000008, which its XLOOKUP sends to 79;
     * the system adds exact hundredths (74.71 -> 78). Not changed: a school /
     * project decision (WORKBOOK_PARITY_FOLLOWUP_REPORT_2026-10-06.md).
     */
    public function test_component_sums_match_excel_except_the_confirmed_binary_sum_case(): void
    {
        $rows = self::excelCsv('row17-component-sums.csv');
        $this->assertCount(16, $rows);
        foreach ($rows as $r) {
            $exam = ['st1' => [100, (float) $r['st1']], 'st2' => [100, (float) $r['st2']], 'term_exam' => [100, (float) $r['te']]];
            $g = $this->engine([[100, (float) $r['ww']]], [[100, (float) $r['pt']]], $exam)['grade'];
            $this->assertSame([(float) $r['P17'], (float) $r['AC17'], (float) $r['AK17']],
                [$g['partial_contributions']['written_work'], $g['partial_contributions']['performance_task'], $g['partial_contributions']['examination']], "{$r['label']}: WS");
            $this->assertSame(round((float) $r['AL17'], 2), $g['computed_grade'], "{$r['label']}: Initial Grade");
            if ($r['label'] === '74.71 noisy') {
                $this->assertSame('74.710000000000008', $r['AL17']);
                $this->assertSame([79.0, 78.0], [(float) $r['AM17'], $g['transmuted_grade']], 'Excel 79, system 78 — the open discrepancy');
                continue;
            }
            $this->assertSame((float) $r['AM17'], $g['transmuted_grade'], "{$r['label']}: Term Grade");
        }
    }

    // --- HELPER weights and shares = the system's catalog -------------------------------------

    public function test_helper_catalog_weights_and_shares_equal_the_seeded_catalog(): void
    {
        $helper = WorkbookOracle::helperCatalog();
        $this->assertCount(DepedSubjectCatalog::count(), $helper);
        $num = fn($v) => $v === null || $v === '' ? null : (float) $v;
        foreach ($helper as $row) {
            if (str_starts_with($row['title'], '=')) {
                // "OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM": the workbook takes
                // the teacher's own weights from INPUT DATA!F43:F48; the system
                // never takes a teacher-typed weight (teacher_supplied, nulls).
                $c = DepedSubjectCatalog::where('cluster', $row['cluster'])->where('track', $row['track'])->sole();
                $this->assertTrue((bool) $c->teacher_supplied);
                $this->assertSame([null, null, null], [$c->ww_weight, $c->pt_weight, $c->ex_weight]);
                continue;
            }
            $c = DepedSubjectCatalog::where('course_title', $row['title'])->where('cluster', $row['cluster'])->where('track', $row['track'])->first();
            $this->assertNotNull($c, "catalog row for {$row['title']} ({$row['cluster']})");
            $this->assertSame(
                [$num($row['ww']), $num($row['pt']), $num($row['ex']), $num($row['st1']), $num($row['st2']), $num($row['te'])],
                [$num($c->ww_weight), $num($c->pt_weight), $num($c->ex_weight), $num($c->st1_share), $num($c->st2_share), $num($c->te_share)],
                "{$row['title']} weights/shares"
            );
        }
    }
}
