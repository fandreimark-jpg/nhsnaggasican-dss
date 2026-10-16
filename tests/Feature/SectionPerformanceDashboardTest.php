<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use App\Services\GradingEngine;
use App\Services\SectionPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Principal dashboard "Performance by Grade and Section" (2026-10-07):
 * learner measures count each learner once, Passed/Failed count verified
 * SUBJECT GRADES (current only — stale ones are re-verify, unverified ones
 * nothing), and the denominator is enrolled learners × subjects taught.
 */
class SectionPerformanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $teacher;
    private Section $agila;
    private Section $banaba;
    private Section $curie;
    private Subject $math;
    private Subject $english;
    /** @var array<string, Student> */
    private array $l = [];

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);

        $this->principal = User::factory()->create(['role' => 'principal']);
        $this->teacher = User::factory()->create(['role' => 'subject_teacher']);
        $this->agila = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        $this->banaba = Section::factory()->create(['name' => 'Banaba', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        $this->curie = Section::factory()->create(['name' => 'Curie', 'grade_level' => 12, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        $this->math = Subject::factory()->create(['name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->english = Subject::factory()->create(['name' => 'Effective Communication', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->math->syncTerms([1, 2, 3]);
        $this->english->syncTerms([1, 2, 3]);
        foreach (['ana' => $this->agila, 'ben' => $this->agila, 'cara' => $this->agila, 'dan' => $this->banaba, 'eva' => $this->banaba, 'fe' => $this->curie] as $name => $section) {
            $this->l[$name] = Student::factory()->create(['section_id' => $section->id, 'last_name' => strtoupper($name)]);
        }
    }

    private function item(Subject $subject, string $name, string $component, float $max, ?string $role = null): Assessment
    {
        return Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => $max]);
    }

    /** A verified grade that matches today's evidence (or not, when $computed is overridden). */
    private function verified(Student $s, Subject $subject, ?float $computed = null, ?float $grade = null, bool $verified = true): Grade
    {
        $g = (new GradingEngine())->computeGrade($s, $subject, $this->agila, 1, '2026-2027');

        return Grade::create(['student_id' => $s->id, 'subject_id' => $subject->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'grade' => $grade ?? $g['transmuted_grade'], 'computed_grade' => $computed ?? $g['computed_grade'],
            'is_verified' => $verified, 'verified_at' => $verified ? now() : null, 'is_provisional' => false, 'encoded_by' => $this->teacher->id]);
    }

    /** Agila: ana passes Math, ben fails Math, cara's Math grade is stale, ana's English grade is typed but unverified; ana + ben Missing in English. */
    private function seedAgila(): void
    {
        $q = $this->item($this->math, 'Quiz 1', 'written_work', 20);
        $pt = $this->item($this->math, 'PT 1', 'performance_task', 50);
        $te = $this->item($this->math, 'TE', 'examination', 40, 'term_exam');
        foreach (['ana' => [18, 45, 36], 'ben' => [2, 10, 5], 'cara' => [19, 46, 37]] as $who => [$a, $b, $c]) {
            foreach ([[$q, $a], [$pt, $b], [$te, $c]] as [$item, $v]) {
                AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->l[$who]->id, 'score' => $v]);
            }
        }
        $this->assertTrue(\App\Services\InTermStatusService::isFailing($this->verified($this->l['ben'], $this->math)), 'fixture: ben fails Math');
        $this->verified($this->l['ana'], $this->math);
        $this->assertFalse(\App\Services\InTermStatusService::isFailing($this->verified($this->l['cara'], $this->math)), 'fixture: cara passes Math');
        AssessmentScore::where('assessment_id', $q->id)->where('student_id', $this->l['cara']->id)->sole()->update(['score' => 3]); // after verification → stale
        $this->verified($this->l['ana'], $this->english, computed: 80, grade: 85, verified: false);

        $eq = $this->item($this->english, 'Essay', 'written_work', 20);
        (new AssessmentStatusService())->markMissing($eq, $this->l['ana']->id, $this->teacher->id);
        (new AssessmentStatusService())->markMissing($eq, $this->l['ben']->id, $this->teacher->id);
        (new AcademicMonitoringService())->evaluate('2026-2027', 1);
    }

    private function rows(?int $grade = null, ?int $subject = null): array
    {
        return collect((new SectionPerformanceService())->build('2026-2027', 1, $grade, $subject)['rows'])->keyBy(fn($r) => $r['section']->name)->all();
    }

    public function test_learners_are_counted_once_and_subject_grades_only_when_current_and_verified(): void
    {
        $this->seedAgila();
        $this->assertSame(3, \App\Models\AcademicConcern::open()->count(), 'fixture: ben has two concerns, ana one');
        $r = $this->rows();

        $this->assertSame(['learners' => 3, 'for_review' => 2, 'missing' => 2, 'passed' => 1, 'failed' => 1, 'verified' => 2, 'reverify' => 1, 'expected' => 6],
            array_intersect_key($r['Agila'], array_flip(['learners', 'for_review', 'missing', 'passed', 'failed', 'verified', 'reverify', 'expected'])));
        $this->assertSame('partial', SectionPerformanceService::coverageState($r['Agila']));
        $this->assertSame([2, 0, 0, 4, 'none'], [$r['Banaba']['learners'], $r['Banaba']['verified'], $r['Banaba']['for_review'], $r['Banaba']['expected'], SectionPerformanceService::coverageState($r['Banaba'])]);
        $this->assertSame([1, 0, 'nothing_expected'], [$r['Curie']['learners'], $r['Curie']['expected'], SectionPerformanceService::coverageState($r['Curie'])], 'no Grade 12 subject: nothing expected, not "0 verified"');

        $t = (new SectionPerformanceService())->build('2026-2027', 1)['totals'];
        $this->assertSame([6, 2, 2, 1, 1, 2, 10], [$t['learners'], $t['for_review'], $t['missing'], $t['passed'], $t['failed'], $t['verified'], $t['expected']]);
    }

    public function test_the_subject_and_grade_filters_narrow_every_figure_consistently(): void
    {
        $this->seedAgila();
        $math = $this->rows(subject: $this->math->id)['Agila'];
        $this->assertSame([1, 0, 1, 1, 3], [$math['for_review'], $math['missing'], $math['passed'], $math['failed'], $math['expected']]);
        $english = $this->rows(subject: $this->english->id)['Agila'];
        $this->assertSame([2, 2, 0, 3, 'none'], [$english['for_review'], $english['missing'], $english['verified'], $english['expected'], SectionPerformanceService::coverageState($english)]);
        $this->assertSame('not_offered', SectionPerformanceService::coverageState($this->rows(subject: $this->math->id)['Curie']), 'a Grade 11 subject is not taught to Grade 12');
        $this->assertSame(['Curie'], array_keys($this->rows(grade: 12)));
    }

    public function test_complete_coverage_and_the_denominator_comes_from_the_curriculum_not_from_grade_rows(): void
    {
        // A grade row for a subject the section does not take never counts.
        $stray = Subject::factory()->create(['name' => 'Not Taught', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        Grade::create(['student_id' => $this->l['dan']->id, 'subject_id' => $stray->id, 'section_id' => $this->banaba->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 90, 'computed_grade' => 90, 'is_verified' => true, 'is_provisional' => false]);
        $r = $this->rows()['Banaba'];
        $this->assertSame([0, 4], [$r['verified'], $r['expected']]);

        $complete = ['subject_applies' => true, 'expected' => 4, 'verified' => 4];
        $this->assertSame('complete', SectionPerformanceService::coverageState($complete));
    }

    public function test_the_dashboard_shows_the_table_states_and_drill_down_links_to_the_principal_only(): void
    {
        $this->seedAgila();
        $html = $this->actingAs($this->principal)->get(route('principal.dashboard', ['perf_term' => 1]))->assertOk()
            ->assertSee('Performance by Grade and Section')->assertSee('subject grades')->assertSee('Partial data')
            ->assertSee('No verified grades yet')->assertSee('No expected grades')->assertSee('evidence changed — re-verify')->getContent();
        $this->assertStringContainsString('data-perf-row="' . $this->agila->id . '"', $html);
        $this->assertStringContainsString(e(route('principal.academic-monitoring', ['school_year' => '2026-2027', 'term' => 1, 'grade_level' => 11, 'section_id' => $this->agila->id])), $html);
        $this->assertStringContainsString('2 / 10', $html, 'total coverage = summed counts');
        $this->assertStringNotContainsString('Passed learners', $html);

        $filtered = $this->actingAs($this->principal)->get(route('principal.dashboard', ['perf_term' => 1, 'perf_subject' => $this->math->id]))->getContent();
        $this->assertStringContainsString(e(route('principal.academic-monitoring', ['school_year' => '2026-2027', 'term' => 1, 'grade_level' => 11, 'section_id' => $this->agila->id, 'subject_id' => $this->math->id])), $filtered);
        // Unknown filter values fall back to "no filter", never to an error or another year.
        $this->actingAs($this->principal)->get(route('principal.dashboard', ['perf_term' => 9, 'perf_grade' => 7, 'perf_subject' => 999]))->assertOk()->assertSee('data-perf-row="' . $this->curie->id . '"', false);

        foreach (['adviser', 'subject_teacher', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('principal.dashboard'))->assertForbidden();
        }
    }

    public function test_component_summary_shows_a_genuine_zero_and_no_data_distinctly_with_its_scope(): void
    {
        // Written Work only: a recorded 0 and a 10/20 → 25% (the 0 counts); PT and Exam have nothing.
        $q = $this->item($this->math, 'Quiz 1', 'written_work', 20);
        AssessmentScore::create(['assessment_id' => $q->id, 'student_id' => $this->l['ana']->id, 'score' => 0]);
        AssessmentScore::create(['assessment_id' => $q->id, 'student_id' => $this->l['ben']->id, 'score' => 10]);

        $html = $this->actingAs($this->principal)->get(route('principal.dashboard', ['perf_term' => 1]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-component="written_work">.*?25\.0%#s', $html);
        foreach (['performance_task', 'examination'] as $none) {
            $this->assertMatchesRegularExpression('#data-component="' . $none . '">\s*<div[^>]*>\s*<span[^>]*>[^<]+</span>\s*<span class="text-muted" data-component-no-data>No data</span>#', $html);
        }
        $this->assertStringContainsString('Whole school year 2026-2027, all terms', $html);
        $this->assertStringContainsString('not a DepEd passing mark', $html);
        $this->assertStringNotContainsString('text-danger-text">25.0%', $html, 'below the reference is not shown as failing');

        // Layout regressions behind the double scrollbar and the hash landing.
        $this->assertSame(1, substr_count($html, 'id="performance-by-section"'));
        $this->assertStringContainsString('<main id="main" class="relative ', $html, 'main is the containing block of absolutely positioned (sr-only) text');
        $this->assertStringContainsString(e(route('principal.dashboard', ['school_year' => '2026-2027'])) . '#performance-by-section', $html, 'Reset returns to the section defaults');
    }

    public function test_queries_do_not_grow_with_the_number_of_learners(): void
    {
        $this->seedAgila();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            (new SectionPerformanceService())->build('2026-2027', 1);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $before = $count();
        foreach (range(1, 15) as $i) {
            Student::factory()->create(['section_id' => $this->banaba->id]);
        }
        $this->assertSame($before, $count(), 'fifteen more learners, same number of queries');
    }
}
