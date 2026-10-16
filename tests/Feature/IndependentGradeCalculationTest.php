<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\TransmutationRange;
use App\Models\User;
use App\Services\ClassRecordExport;
use App\Services\ClassRecordService;
use App\Services\GradeVerificationBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final audit (2026-09-28): the figures every screen shows must equal an
 * INDEPENDENT hand calculation, not merely agree with each other (they all
 * call GradingEngine, so agreement alone proves nothing).
 *
 * Fixture — a DO 015 Core subject, WW 20 / PT 50 / EX 30, Examination split
 * ST1 30 / ST2 30 / TE 40 (ExamRoleSharesSeeder):
 *   WW  Q1 17/20 + Q2 24/30            = 41/50   = 82.00%  x 0.20 = 16.40
 *   PT  P1 35/50 + P2 45/50 (additional support, counts in full)
 *                                       = 80/100  = 80.00%  x 0.50 = 40.00
 *   EX  ST1 28/40 = 70%, ST2 30/40 = 75%, TE 30/50 = 60%
 *       0.30*70 + 0.30*75 + 0.40*60      = 67.50%  x 0.30 = 20.25
 *   Initial grade = 16.40 + 40.00 + 20.25 = 76.65
 *   Transmuted    = the do015_2026 band containing 76.65 (read from the table)
 */
class IndependentGradeCalculationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    public function test_every_screen_shows_the_hand_calculated_figures(): void
    {
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);

        $teacher = User::factory()->create(['role' => 'adviser']);
        $section = Section::factory()->create(['grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $teacher->id]);
        $subject = Subject::factory()->create(['name' => 'Independent Check', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $subject->syncTerms([1]);
        $learner = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'CHECK', 'lrn' => '012300000001']);
        TeachingAssignment::create(['user_id' => $teacher->id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);

        $items = [
            ['Q1', 'written_work', null, 20, 17, false],
            ['Q2', 'written_work', null, 30, 24, false],
            ['P1', 'performance_task', null, 50, 35, false],
            ['P2', 'performance_task', null, 50, 45, true],
            ['ST1', 'examination', 'st1', 40, 28, false],
            ['ST2', 'examination', 'st2', 40, 30, false],
            ['TE', 'examination', 'term_exam', 50, 30, false],
        ];
        foreach ($items as [$name, $component, $role, $hps, $score, $support]) {
            $this->addAssessment($teacher, $section, $subject, array_filter([
                'item_name' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => $hps,
                'is_additional_support' => $support ? 1 : null,
            ], fn($v) => $v !== null))->assertSessionHasNoErrors();
            $this->saveCells($teacher, Assessment::where('name', $name)->sole(), [$learner->id => (string) $score])->assertOk();
        }
        $this->assertTrue((bool) Assessment::where('name', 'P2')->value('is_additional_support'));

        $band = TransmutationRange::where('scheme', 'do015_2026')->where('min_initial', '<=', 76.65)->where('max_initial', '>=', 76.65)->sole();
        $expectedTransmuted = number_format((float) $band->transmuted, 2);

        // Class Record (the text every cell shows).
        $row = (new ClassRecordService())->build($section, $subject, 1)['display'][$learner->id];
        $this->assertSame('41 / 50', $row['written_work:total']);
        $this->assertSame('82.00', $row['written_work:ps']);
        $this->assertSame('16.40', $row['written_work:ws']);
        $this->assertSame('80 / 100', $row['performance_task:total']);
        $this->assertSame('80.00', $row['performance_task:ps']);
        $this->assertSame('40.00', $row['performance_task:ws']);
        $this->assertSame('67.50', $row['examination:ps']);
        $this->assertSame('20.25', $row['examination:ws']);
        $this->assertSame('76.65', $row['grade:initial']);
        $this->assertSame($expectedTransmuted, $row['grade:transmuted']);

        // Verify Grades (the Student Performance table).
        $perf = (new GradeVerificationBoard())->build($section, $subject, 1, null)['performance']->sole();
        $this->assertEqualsWithDelta(82.00, $perf['components']['written_work']['percentage'], 0.001);
        $this->assertEqualsWithDelta(67.50, $perf['components']['examination']['percentage'], 0.001);
        $this->assertEqualsWithDelta(76.65, $perf['computed_grade'], 0.001);
        $this->assertSame($expectedTransmuted, number_format((float) $perf['transmuted_grade'], 2));
        $this->assertSame('eligible', $perf['verify_state']);

        // Excel export: the learner row carries the same figures as values.
        $sheet = (new ClassRecordExport())->build((new ClassRecordService())->build($section, $subject, 1))->getActiveSheet();
        $values = [];
        foreach ($sheet->getRowIterator() as $r) {
            $cells = [];
            foreach ($r->getCellIterator() as $c) {
                $cells[] = $c->getValue();
            }
            if (in_array('012300000001', $cells, true)) {
                $values = $cells;
            }
        }
        $this->assertNotEmpty($values, 'the learner row is in the export, LRN as text');
        $flat = array_map(fn($v) => is_numeric($v) ? number_format((float) $v, 2) : (string) $v, $values);
        foreach (['82.00', '16.40', '80.00', '40.00', '67.50', '20.25', '76.65', $expectedTransmuted] as $expected) {
            $this->assertContains($expected, $flat, "export shows {$expected}");
        }

        // Verification stores exactly these figures.
        $this->actingAs($teacher)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $learner->id, 'subject_id' => $subject->id])->assertSessionHas('success');
        $grade = \App\Models\Grade::sole();
        $this->assertSame('76.65', number_format((float) $grade->computed_grade, 2));
        $this->assertSame($expectedTransmuted, number_format((float) $grade->grade, 2));
    }
}
