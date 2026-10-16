<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ClassRecordService;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The E-Class-Record-style Class Record ("Class Record refinements",
 * 2026-09-28): identification header, component summaries beside their own
 * group, the subject's CONFIGURED weights, no fixed item limit, read-only
 * computed cells formatted by the server, and drafts scoped per account and
 * class.
 */
class ClassRecordLayoutTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    private User $teacher;
    private Section $section;
    private Subject $subject;
    private Student $ana;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->teacher = User::factory()->create(['role' => 'subject_teacher', 'last_name' => 'Bosco', 'first_name' => 'Darien']);
        $this->section = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        // An Arts/Sports/Wellness-group subject: DO 015 weights 20/60/20, not 25/50/25.
        $this->subject = Subject::factory()->create(['name' => 'Physical Education', 'type' => 'elective', 'grade_level' => 11, 'subject_group' => 'arts_sports_wellness', 'track_id' => $this->section->track_id]);
        $this->subject->syncTerms([1, 2, 3]);
        \App\Models\SectionSubject::create(['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'academic_term_id' => AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->value('id'), 'school_year' => '2026-2027']);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id, 'lrn' => '012345678901', 'last_name' => 'ABAD', 'first_name' => 'ANA']);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
    }

    private function item(string $name, string $component, float $max): Assessment
    {
        return Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => $name, 'component' => $component, 'exam_role' => null, 'max_score' => $max, 'uploaded_by' => $this->teacher->id]);
    }

    private function page()
    {
        return $this->actingAs($this->teacher)->get(route('teacher.class-record', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1]));
    }

    public function test_the_header_names_the_class_teacher_and_configured_profile(): void
    {
        $profile = (new GradingEngine())->resolveWeightProfile($this->section, $this->subject);
        $this->assertSame([20.0, 60.0, 20.0], [$profile['ww_weight'], $profile['pt_weight'], $profile['ex_weight']], 'fixture sanity: configured weights');

        $this->page()->assertOk()
            ->assertSee('2026-2027 · Term 1')->assertSee('Grade 11 — Agila')->assertSee('Physical Education')
            ->assertSee('Bosco, Darien')->assertSee('WW 20% · PT 60% · EX 20%')
            ->assertSee('Add Assessment')->assertSee('Save Scores')->assertSee('Download Excel Class Record')->assertSee('Copy Scores');
    }

    public function test_summaries_sit_beside_their_own_group_and_any_number_of_items_is_shown(): void
    {
        $ww = collect(range(1, 12))->map(fn($i) => $this->item("Quiz {$i}", 'written_work', 10));
        $pt = $this->item('Project 1', 'performance_task', 50);
        $this->item('Term Exam', 'examination', 40);

        $html = $this->page()->assertOk()->getContent();
        foreach ($ww as $item) {
            $this->assertStringContainsString('data-item-head="' . $item->id . '"', $html, '12 WW items — no ten-item cap');
        }
        // Workbook order: WW items -> WW TOTAL/PS/WS -> PT items -> PT TOTAL/PS/WS -> EX ...
        $posLastWw = strpos($html, 'data-item-head="' . $ww->last()->id . '"');
        $posWwTotal = strpos($html, 'data-sum-head="written_work:total"');
        $posPt = strpos($html, 'data-item-head="' . $pt->id . '"');
        $posPtTotal = strpos($html, 'data-sum-head="performance_task:total"');
        $posEx = strpos($html, 'data-sum-head="examination:ps"');
        $this->assertTrue($posLastWw < $posWwTotal && $posWwTotal < $posPt && $posPt < $posPtTotal && $posPtTotal < $posEx);

        // Computed cells are text, never inputs.
        $this->assertSame(12 + 1 + 1, preg_match_all('/<input[^>]*class="cr-cell[ "]/', $html), 'one input per learner per assessment, nothing else');
        $this->assertStringContainsString('data-cr-out="written_work:ps"', $html);
    }

    public function test_after_a_save_the_computed_cells_are_the_servers_figures(): void
    {
        $ww = $this->item('Quiz 1', 'written_work', 20);
        $pt = $this->item('Project 1', 'performance_task', 50);
        $ex = $this->item('Term Exam', 'examination', 40);
        $this->saveCells($this->teacher, $ww, [$this->ana->id => '18'])->assertOk();
        $this->saveCells($this->teacher, $pt, [$this->ana->id => '40'])->assertOk();
        $response = $this->saveCells($this->teacher, $ex, [$this->ana->id => '30'])->assertOk();

        $row = $response->json('rows.' . $this->ana->id);
        $grade = (new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027');
        $this->assertSame('18 / 20', $row['written_work:total']);
        $this->assertSame(number_format($grade['components']['performance_task'], 2), $row['performance_task:ps']);
        $this->assertSame(number_format($grade['computed_grade'], 2), $row['grade:initial']);
        $this->assertSame('provisional', $row['grade:initial:note']);
        $this->assertSame('All assessments scored', $row['grade:evidence']);

        // The page renders the very same strings.
        $record = (new ClassRecordService())->build($this->section, $this->subject, 1);
        $this->assertSame($row, $record['display'][$this->ana->id]);
    }

    public function test_incomplete_evidence_is_labelled_and_never_zero(): void
    {
        $ww = $this->item('Quiz 1', 'written_work', 20);
        $this->item('Project 1', 'performance_task', 50);
        $this->saveCells($this->teacher, $ww, [$this->ana->id => '0'])->assertOk();

        $row = (new ClassRecordService())->build($this->section, $this->subject, 1)['display'][$this->ana->id];
        $this->assertSame('0 / 20', $row['written_work:total'], 'a recorded 0 counts');
        $this->assertSame('—', $row['performance_task:ps'], 'no PT evidence is not 0%');
        // Running Initial Grade (2026-10-04): WW's WS is added on its own —
        // a recorded 0 is evidence, so the running grade is 0.00, labelled
        // partial; no Term Grade until every component has a score.
        $this->assertSame('0.00', $row['written_work:ws']);
        $this->assertSame('0.00', $row['grade:initial']);
        $this->assertSame('running · partial', $row['grade:initial:note']);
        $this->assertSame('—', $row['grade:transmuted']);
        $this->assertSame('Partial Evidence', $row['grade:evidence']);
        $this->assertSame('1 blank', $row['grade:evidence:note']);
    }

    public function test_the_draft_key_is_scoped_to_account_year_section_subject_and_term_and_a_read_only_view_is_marked(): void
    {
        $this->page()->assertSee('data-draft-key="cr-draft:' . $this->teacher->id . ':2026-2027:' . $this->section->id . ':' . $this->subject->id . ':1"', false)
            ->assertSee('data-can-edit="1"', false)->assertSee('id="crLeaveModal"', false);

        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);
        $this->page()->assertSee('data-can-edit="0"', false)->assertDontSee('Save Scores')->assertDontSee('id="crMarkMissing"', false);
    }
}
