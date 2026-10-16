<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\DepedSubjectCatalog;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ClassRecordExport;
use App\Services\ClassRecordService;
use App\Services\GradeVerificationBoard;
use App\Services\SubjectResultSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final demo audit (2026-09-30): hand-calculated grading fixtures across the
 * configured weight profiles, compared on every screen that shows a grade.
 *
 * Every expected figure below is worked out by hand in the comment beside it
 * and every transmuted grade is HARDCODED from the published tables (DO 015
 * HELPER!B7:D47 bands; DO 8, s. 2015 table) — nothing is read back from
 * GradingEngine or from transmutation_ranges, so a wrong engine or a wrong
 * seeded band fails here instead of agreeing with itself.
 *
 * Rounding (DepEd ECR): PS = round(earned / HPS x 100, 2); WS = round(PS x
 * weight, 2); Initial = sum of WS.
 */
class IndependentGradingFixturesTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);
        $this->teacher = User::factory()->create(['role' => 'adviser']);
    }

    /**
     * DO 8, s. 2015 (explicit k12_2013 section, no track -> scheme 'all'
     * row, WW 25 / PT 50 / EX 25; one flat Examination, no roles) — the
     * CLAUDE.md worked example:
     *   WW 76/90   = 84.44% x .25 = 21.11
     *   PT 85/100  = 85.00% x .50 = 42.50
     *   EX 70/100  = 70.00% x .25 = 17.50
     *   Initial 81.11 -> DO 8 band 80.80-82.39 = 88
     */
    public function test_do8_worked_example(): void
    {
        [$section, $subject, $learner] = $this->class(12, 'k12_2013', 'Do8 Core', 'core', 'core_academic');
        $this->item($section, $subject, 'Q1', 'written_work', null, 90, [$learner->id => 76]);
        $this->item($section, $subject, 'PT1', 'performance_task', null, 100, [$learner->id => 85]);
        $this->item($section, $subject, 'Exam', 'examination', null, 100, [$learner->id => 70]);

        $this->assertEverywhere($section, $subject, $learner, [
            'written_work:ps' => '84.44', 'written_work:ws' => '21.11',
            'performance_task:ps' => '85.00', 'performance_task:ws' => '42.50',
            'examination:ps' => '70.00', 'examination:ws' => '17.50',
        ], '81.11', 88);
    }

    /**
     * DO 015 Arts/Sports/Health/Wellness (20 / 60 / 20), exam split ST1 30 /
     * ST2 30 / TE 40 — the passing anchor and the rounding of a repeating PS:
     *   WW 2/3     = 66.67% x .20 = 13.33   (66.666.. rounds to 66.67)
     *   PT 37/50   = 74.00% x .60 = 44.40
     *   EX ST1 6/10=60, ST2 6/10=60, TE 7/10=70
     *      .3*60 + .3*60 + .4*70 = 64.00% x .20 = 12.80
     *   Initial 70.53 -> DO 015 band 69.99-71.16 = 75 (passing)
     */
    public function test_do015_arts_group_rounding_and_passing_anchor(): void
    {
        [$section, $subject, $learner] = $this->class(11, 'sshs', 'Arts Elective Check', 'elective', 'arts_sports_wellness');
        $this->item($section, $subject, 'Q1', 'written_work', null, 3, [$learner->id => 2]);
        $this->item($section, $subject, 'PT1', 'performance_task', null, 50, [$learner->id => 37]);
        $this->item($section, $subject, 'ST1', 'examination', 'st1', 10, [$learner->id => 6]);
        $this->item($section, $subject, 'ST2', 'examination', 'st2', 10, [$learner->id => 6]);
        $this->item($section, $subject, 'TE', 'examination', 'term_exam', 10, [$learner->id => 7]);

        $grade = $this->assertEverywhere($section, $subject, $learner, [
            'written_work:ps' => '66.67', 'written_work:ws' => '13.33',
            'performance_task:ps' => '74.00', 'performance_task:ws' => '44.40',
            'examination:ps' => '64.00', 'examination:ws' => '12.80',
        ], '70.53', 75);

        $summary = SubjectResultSummary::fromGrades([$grade->load('subject')], 1);
        $this->assertSame([1, 0], [$summary['passed'], $summary['failed']], '75 is passing');
        $this->assertSame(0, AcademicConcern::whereNull('resolved_at')->count(), 'a passing official grade opens no concern');
    }

    /**
     * DO 015 Core (20 / 50 / 30) just below the passing anchor:
     *   WW 13.98/20 = 69.90% x .20 = 13.98
     *   PT 35/50    = 70.00% x .50 = 35.00
     *   EX ST1 7/10, ST2 7/10, TE 7/10 = 70.00% x .30 = 21.00
     *   Initial 69.98 -> DO 015 band 65.34-69.98 = 74 (Failing)
     * (69.98 is the highest failing Initial Grade under the workbook's own
     * lookup: AM17 = XLOOKUP(AL17, HELPER!C7:C47, D7:D47, , 1) with C33
     * stored as 69.9899999999999 sends 69.99 to 75 — WorkbookParityTest.)
     * The official 74 is Failing, and verification opens exactly one Subject
     * Performance Concern for this learner and subject.
     */
    public function test_do015_core_just_below_anchor_is_failing_and_opens_one_concern(): void
    {
        [$section, $subject, $learner] = $this->class(11, 'sshs', 'Core Boundary', 'core', 'core_academic');
        $this->item($section, $subject, 'Q1', 'written_work', null, 20, [$learner->id => '13.98']);
        $this->item($section, $subject, 'PT1', 'performance_task', null, 50, [$learner->id => 35]);
        $this->item($section, $subject, 'ST1', 'examination', 'st1', 10, [$learner->id => 7]);
        $this->item($section, $subject, 'ST2', 'examination', 'st2', 10, [$learner->id => 7]);
        $this->item($section, $subject, 'TE', 'examination', 'term_exam', 10, [$learner->id => 7]);

        $grade = $this->assertEverywhere($section, $subject, $learner, [
            'written_work:ps' => '69.90', 'written_work:ws' => '13.98',
            'performance_task:ps' => '70.00', 'performance_task:ws' => '35.00',
            'examination:ps' => '70.00', 'examination:ws' => '21.00',
        ], '69.98', 74);

        $summary = SubjectResultSummary::fromGrades([$grade->load('subject')], 1);
        $this->assertSame([0, 1], [$summary['passed'], $summary['failed']], '74 is failing');
        $open = AcademicConcern::whereNull('resolved_at')->get();
        $this->assertCount(1, $open);
        $this->assertSame([$learner->id, $subject->id], [(int) $open[0]->student_id, (int) $open[0]->subject_id]);
    }

    /**
     * DO 015 Research/Design/Innovation (40 / 60 / no Examination) with a
     * valid zero:
     *   WW 0/20   =   0.00% x .40 =  0.00   (a recorded 0 is evidence)
     *   PT 50/50  = 100.00% x .60 = 60.00
     *   Initial 60.00 -> DO 015 band 56.01-60.66 = 72
     * The grade is complete with no Examination item at all, and the Add
     * Assessment form refuses an Examination item for this subject.
     */
    public function test_do015_no_exam_group_with_valid_zero(): void
    {
        [$section, $subject, $learner] = $this->class(11, 'sshs', 'Research Check', 'elective', 'research_innovation');
        $this->item($section, $subject, 'Q1', 'written_work', null, 20, [$learner->id => '0']);
        $this->item($section, $subject, 'PT1', 'performance_task', null, 50, [$learner->id => 50]);

        $this->addAssessment($this->teacher, $section, $subject, ['item_name' => 'Exam', 'component' => 'examination', 'exam_role' => 'term_exam', 'max_score' => 10])
            ->assertSessionHasErrors();
        $this->assertSame(0, Assessment::where('component', 'examination')->count());

        $this->assertEverywhere($section, $subject, $learner, [
            'written_work:ps' => '0.00', 'written_work:ws' => '0.00',
            'performance_task:ps' => '100.00', 'performance_task:ws' => '60.00',
        ], '60.00', 72);
    }

    /**
     * A catalog-linked TE-only subject (Basic Calculus: STEM 20 / 50 / 30,
     * Term Exam 100%, no summative tests):
     *   WW 18/20 = 90.00% x .20 = 18.00
     *   PT 40/50 = 80.00% x .50 = 40.00
     *   EX TE 45/60 = 75.00% x .30 = 22.50
     *   Initial 80.50 -> DO 015 band 79.44-80.61 = 83
     */
    public function test_catalog_linked_term_exam_only_subject(): void
    {
        $catalog = DepedSubjectCatalog::whereRaw('LOWER(course_title) = ?', ['basic calculus'])->first();
        $this->assertNotNull($catalog, 'Basic Calculus is in the seeded DepEd catalog');
        [$section, $subject, $learner] = $this->class(11, 'sshs', 'Basic Calculus', 'elective', 'academic_other', $catalog->id);

        $this->addAssessment($this->teacher, $section, $subject, ['item_name' => 'ST1', 'component' => 'examination', 'exam_role' => 'st1', 'max_score' => 10])
            ->assertSessionHasErrors();
        $this->item($section, $subject, 'Q1', 'written_work', null, 20, [$learner->id => 18]);
        $this->item($section, $subject, 'PT1', 'performance_task', null, 50, [$learner->id => 40]);
        $this->item($section, $subject, 'TE', 'examination', 'term_exam', 60, [$learner->id => 45]);

        $this->assertEverywhere($section, $subject, $learner, [
            'written_work:ps' => '90.00', 'written_work:ws' => '18.00',
            'performance_task:ps' => '80.00', 'performance_task:ws' => '40.00',
            'examination:ps' => '75.00', 'examination:ws' => '22.50',
        ], '80.50', 83);
    }

    /**
     * Incomplete evidence: a required Examination Type with a BLANK cell, and
     * then an explicit Missing mark on it, both leave the grade uncomputed —
     * never a 0 — and verification refuses it. Recording a 0 completes it:
     *   WW 20/20 = 100 x .20 = 20.00; PT 50/50 = 100 x .50 = 50.00
     *   EX ST1 10/10, ST2 10/10, TE 0/10: .3*100 + .3*100 + .4*0 = 60.00 x .30 = 18.00
     *   Initial 88.00 -> DO 015 band 87.70-88.87 = 90
     */
    public function test_blank_and_missing_are_incomplete_and_zero_completes(): void
    {
        [$section, $subject, $learner] = $this->class(11, 'sshs', 'Incomplete Check', 'core', 'core_academic');
        $this->item($section, $subject, 'Q1', 'written_work', null, 20, [$learner->id => 20]);
        $this->item($section, $subject, 'PT1', 'performance_task', null, 50, [$learner->id => 50]);
        $this->item($section, $subject, 'ST1', 'examination', 'st1', 10, [$learner->id => 10]);
        $this->item($section, $subject, 'ST2', 'examination', 'st2', 10, [$learner->id => 10]);
        $te = $this->item($section, $subject, 'TE', 'examination', 'term_exam', 10, [$learner->id => '']);

        $verify = fn() => $this->actingAs($this->teacher)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $learner->id, 'subject_id' => $subject->id]);

        foreach (['' => 'blank', 'M' => 'Missing'] as $value => $label) {
            $this->saveCells($this->teacher, $te, [$learner->id => $value])->assertOk();
            $row = (new ClassRecordService())->build($section, $subject, 1)['display'][$learner->id];
            // Workbook (D2, 2026-10-04): a blank or "M" TE adds nothing to AJ17,
            // so AJ17 = (30 + 30) / 100 * 100 = 60, AK17 = 18 and the running
            // grade is 20 + 50 + 18 = 88.00 — the figure the workbook shows.
            // It stays a RUNNING figure: no Term Grade, no verification.
            $this->assertSame('88.00', $row['grade:initial'], "{$label} TE: the running grade is the workbook sum");
            $this->assertSame('running · partial', $row['grade:initial:note']);
            $this->assertSame('—', $row['grade:transmuted'], "{$label} TE leaves the Term Grade uncomputed");
            $perf = (new GradeVerificationBoard())->build($section, $subject, 1, null)['performance']->sole();
            $this->assertNull($perf['computed_grade'], "{$label} is not a 0");
            $this->assertSame('incomplete', $perf['verify_state']);
            $verify();
            $this->assertSame(0, Grade::count(), "{$label}: nothing becomes official");
        }

        $this->saveCells($this->teacher, $te, [$learner->id => '0'])->assertOk();
        $this->assertEverywhere($section, $subject, $learner, [
            'examination:ps' => '60.00', 'examination:ws' => '18.00',
        ], '88.00', 90);
    }

    // ------------------------------------------------------------------

    /** @return array{0: Section, 1: Subject, 2: Student} */
    private function class(int $gradeLevel, string $curriculum, string $name, string $type, string $group, ?int $catalogId = null): array
    {
        $section = Section::factory()->create(['grade_level' => $gradeLevel, 'curriculum' => $curriculum, 'school_year' => '2026-2027', 'adviser_id' => $this->teacher->id]);
        $subject = Subject::factory()->create(['name' => $name, 'type' => $type, 'grade_level' => $gradeLevel, 'subject_group' => $group, 'catalog_id' => $catalogId]);
        $subject->syncTerms([1]);
        if ($type === 'elective') {
            \App\Models\SectionSubject::create(['section_id' => $section->id, 'subject_id' => $subject->id, 'academic_term_id' => AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->value('id'), 'school_year' => '2026-2027']);
        }
        $learner = Student::factory()->create(['section_id' => $section->id, 'lrn' => '0123' . str_pad((string) $section->id, 8, '0', STR_PAD_LEFT)]);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);

        return [$section, $subject, $learner];
    }

    private function item(Section $section, Subject $subject, string $name, string $component, ?string $role, float $hps, array $values): Assessment
    {
        $this->addAssessment($this->teacher, $section, $subject, array_filter([
            'item_name' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => $hps,
        ], fn($v) => $v !== null))->assertSessionHasNoErrors();
        $item = Assessment::where('subject_id', $subject->id)->where('name', $name)->sole();
        $this->saveCells($this->teacher, $item, $values)->assertOk();

        return $item;
    }

    /**
     * Class Record text, Verify Grades, the Excel export, the stored grade
     * after verification and the learner report must all show the SAME
     * hand-calculated figures.
     */
    private function assertEverywhere(Section $section, Subject $subject, Student $learner, array $cells, string $initial, int $transmuted): Grade
    {
        $expectedTransmuted = number_format($transmuted, 2);
        $record = (new ClassRecordService())->build($section, $subject, 1);
        $row = $record['display'][$learner->id];
        foreach ($cells as $key => $value) {
            $this->assertSame($value, $row[$key], "Class Record {$key}");
        }
        $this->assertSame($initial, $row['grade:initial'], 'Class Record initial');
        $this->assertSame($expectedTransmuted, $row['grade:transmuted'], 'Class Record transmuted');

        $perf = (new GradeVerificationBoard())->build($section, $subject, 1, null)['performance']->sole();
        $this->assertSame($initial, number_format((float) $perf['computed_grade'], 2), 'Verify Grades computed');
        $this->assertSame($expectedTransmuted, number_format((float) $perf['transmuted_grade'], 2), 'Verify Grades transmuted');

        $sheet = (new ClassRecordExport())->build($record)->getActiveSheet();
        $values = [];
        foreach ($sheet->getRowIterator() as $r) {
            $line = [];
            foreach ($r->getCellIterator() as $c) {
                $line[] = $c->getValue();
            }
            if (in_array($learner->lrn, $line, true)) {
                $values = array_map(fn($v) => is_numeric($v) ? number_format((float) $v, 2) : (string) $v, $line);
            }
        }
        $this->assertNotEmpty($values, 'learner row exported with the LRN as text');
        foreach (array_merge(array_values($cells), [$initial, $expectedTransmuted]) as $expected) {
            $this->assertContains($expected, $values, "export shows {$expected}");
        }

        $this->actingAs($this->teacher)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $learner->id, 'subject_id' => $subject->id])
            ->assertSessionHas('success');
        $grade = Grade::where('student_id', $learner->id)->where('subject_id', $subject->id)->sole();
        $this->assertTrue((bool) $grade->is_verified);
        $this->assertFalse((bool) $grade->is_provisional);
        $this->assertSame($initial, number_format((float) $grade->computed_grade, 2), 'stored computed');
        $this->assertSame($expectedTransmuted, number_format((float) $grade->grade, 2), 'stored official');

        $this->actingAs($this->teacher)->get(route('teacher.learners.report', ['student' => $learner->id, 'school_year' => '2026-2027', 'period' => 1]))
            ->assertOk()->assertSee($subject->name)->assertSee($expectedTransmuted);

        return $grade;
    }
}
