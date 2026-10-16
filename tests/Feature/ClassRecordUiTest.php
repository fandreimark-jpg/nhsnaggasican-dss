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
 * The Class Record's page contract after the UI refinement pass
 * (2026-09-28): who sees the save bar and the selected-cell actions, what
 * each access state says, the empty states, and the reasons shown for a
 * "—" computed cell. The browser behaviour itself (sticky save bar, cell
 * actions, discard) is exercised by tests/Browser/run.mjs.
 */
class ClassRecordUiTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    private User $teacher;
    private User $adviser;
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
        $this->adviser = User::factory()->create(['role' => 'adviser', 'last_name' => 'Cruz', 'first_name' => 'Bong']);
        $this->section = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        $this->subject = Subject::factory()->create(['name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1, 2, 3]);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id, 'lrn' => '012345678901', 'last_name' => 'ABAD', 'first_name' => 'ANA']);
    }

    private function assign(): void
    {
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
    }

    private function item(string $name, string $component, float $max, ?string $role = null): Assessment
    {
        return Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => $max, 'uploaded_by' => $this->teacher->id]);
    }

    private function page(User $user)
    {
        return $this->actingAs($user)->get(route('teacher.class-record', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1]));
    }

    public function test_the_assigned_teacher_gets_the_save_bar_and_cell_actions_with_nothing_enabled_yet(): void
    {
        $this->assign();
        $this->item('Quiz 1', 'written_work', 20);

        $html = $this->page($this->teacher)->assertOk()
            ->assertSee('data-access-state="editable"', false)->assertSee('Editable — you teach this class')
            ->assertSee('id="crSaveBar"', false)->assertSee('id="crSelection"', false)
            ->assertSee('No cell selected — click or tab into a score cell to clear it or undo it.')
            ->assertSee('Blank entries in the assessments being saved will be marked Missing.')
            ->assertSee('No unsaved changes')
            ->assertSee('aria-label="Quiz 1 (HPS 20) for ABAD, ANA"', false)
            ->getContent();

        // Save, Discard and the two cell actions start disabled; no autosave hook exists.
        // There is no per-learner Mark Missing: blanks become Missing on save.
        $this->assertStringNotContainsString('id="crMarkMissing"', $html);
        foreach (['crSave', 'crDiscard', 'crClearEntry', 'crUndoCell'] as $id) {
            $this->assertMatchesRegularExpression('/id="' . $id . '"[^>]*\bdisabled\b/', $html, $id . ' starts disabled');
        }
        // Exactly one Save Scores control on the page.
        $this->assertSame(1, substr_count($html, 'id="crSave"'));
        $this->assertSame(1, substr_count($html, '> Save Scores'));
        // The status line is a polite live region.
        $this->assertMatchesRegularExpression('/id="crStatus"[^>]*role="status"[^>]*aria-live="polite"|id="crStatus"[^>]*aria-live="polite"/', $html);
    }

    public function test_a_closed_term_is_read_only_with_its_reason_and_no_editing_controls(): void
    {
        $this->assign();
        $this->item('Quiz 1', 'written_work', 20);
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        $html = $this->page($this->teacher)->assertOk()
            ->assertSee('data-access-state="closed"', false)->assertSee('Read-only — term closed')
            ->assertSee('data-cr-readonly', false)
            ->assertDontSee('id="crSaveBar"', false)->assertDontSee('id="crSelection"', false)->assertDontSee('data-cr-add', false)
            ->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*class="cr-cell[^"]*"[^>]*\breadonly\b/', $html);
    }

    public function test_the_adviser_sees_an_unassigned_subject_as_view_only_naming_the_teacher(): void
    {
        $this->assign();
        $this->item('Quiz 1', 'written_work', 20);

        $this->page($this->adviser)->assertOk()
            ->assertSee('data-access-state="advisory"', false)->assertSee('View only')
            ->assertSee('Bosco, Darien')
            ->assertSee('View only — you are not assigned to teach General Mathematics in Agila for Term 1.')
            ->assertDontSee('id="crSaveBar"', false)->assertDontSee('data-cr-add', false);
    }

    public function test_with_no_teacher_assigned_the_adviser_is_told_the_admin_assigns_one(): void
    {
        $this->item('Quiz 1', 'written_work', 20);

        $this->page($this->adviser)->assertOk()
            ->assertSee('No teacher is assigned to General Mathematics in Agila for Term 1 yet — the Admin assigns one in Teaching Assignments.');
    }

    public function test_empty_states_offer_add_assessment_only_to_the_teacher_who_can_edit(): void
    {
        $this->assign();
        $this->page($this->teacher)->assertOk()->assertSee('data-cr-empty', false)->assertSee('data-cr-add', false)->assertSee('class="hidden" id="classRecord"', false);
        $this->page($this->adviser)->assertOk()->assertSee('data-cr-empty', false)->assertDontSee('data-cr-add', false);
    }

    public function test_no_learners_explains_that_enrollment_is_admin_managed(): void
    {
        $empty = Section::factory()->create(['name' => 'Molave', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $empty->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);

        $this->actingAs($this->teacher)->get(route('teacher.class-record', ['section_id' => $empty->id, 'subject_id' => $this->subject->id, 'period' => 1]))->assertOk()
            ->assertSee('No learners are enrolled in Molave for 2026-2027.')
            ->assertSee('Learner records and section enrollment are managed by the Admin.');
    }

    public function test_a_dash_carries_its_real_reason_and_no_figure_changes(): void
    {
        $this->assign();
        $ww = $this->item('Quiz 1', 'written_work', 20);
        $this->item('Project 1', 'performance_task', 50);
        $st1 = $this->item('Summative Test 1', 'examination', 40, 'st1');
        $st2 = $this->item('Summative Test 2', 'examination', 40, 'st2');
        $this->item('Term Examination', 'examination', 40, 'term_exam');
        $this->saveCells($this->teacher, $ww, [$this->ana->id => '18'])->assertOk();
        $this->saveCells($this->teacher, $st1, [$this->ana->id => '30'])->assertOk();
        $this->saveCells($this->teacher, $st2, [$this->ana->id => '32'])->assertOk();

        $row = (new ClassRecordService())->build($this->section, $this->subject, 1)['display'][$this->ana->id];
        $grade = (new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027');
        $this->assertSame('equal_split', $grade['component_detail']['examination']['method'], 'without configured shares: the pre-existing equal split');

        // As production seeds them: ST1 30 / ST2 30 / TE 40 (HELPER!AA:AC fallback).
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);
        $row = (new ClassRecordService())->build($this->section, $this->subject, 1)['display'][$this->ana->id];
        $grade = (new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027');

        // The figures are the engine's, by the workbook (D2/D3, 2026-10-04):
        // AG17 = ROUND(30/40*30,2) = 22.5, AH17 = ROUND(32/40*30,2) = 24, AI17 = "" ->
        // AJ17 = 46.5 / (30+30+40) * 100 = 46.5, AK17 = ROUND(46.5*0.30,2) = 13.95.
        $this->assertSame(46.5, $grade['components']['examination']);
        $this->assertSame(['46.50', '13.95'], [$row['examination:ps'], $row['examination:ws']]);
        $this->assertStringContainsString('Not yet scored: Term Exam', $row['examination:ps:why']);
        $this->assertSame('No numeric score in this component yet.', $row['performance_task:ps:why']);
        $this->assertSame('No Percentage Score yet.', $row['performance_task:ws:why']);
        $this->assertSame('Percentage Score = TOTAL ÷ total HPS × 100.', $row['written_work:ps:why']);
        $this->assertStringContainsString('its HPS still counts', $row['written_work:total:why']);
        // Running Initial Grade: the WS present (WW 18.00 + EX 13.95), added —
        // not rescaled; no Term Grade yet (no PT, and the Term Exam unscored).
        $this->assertNull($grade['computed_grade']);
        $this->assertSame(number_format($grade['running_grade'], 2), $row['grade:initial']);
        $this->assertSame('31.95', $row['grade:initial']);
        $this->assertSame('running · partial', $row['grade:initial:note']);
        $this->assertStringStartsWith('Running Initial Grade:', $row['grade:initial:why']);
        $this->assertSame('—', $row['grade:transmuted']);
        // WS ST1 / WS ST2 read the engine's per-type figures; TE has none yet.
        $this->assertSame(number_format($grade['exam_roles']['st1']['weighted'], 2), $row['examination:ws_st1']);
        $this->assertSame(number_format($grade['exam_roles']['st2']['weighted'], 2), $row['examination:ws_st2']);
        $this->assertSame('—', $row['examination:ws_term_exam']);

        // The page exposes each reason on its cell.
        $this->page($this->teacher)->assertSee('data-cr-why="examination:ps:why"', false)
            ->assertSee('Not yet scored: Term Exam', false);
    }

    public function test_encode_grades_is_manual_entry_only_and_points_to_verify_grades(): void
    {
        $this->actingAs($this->adviser)->get(route('adviser.grades', ['period' => 1]))->assertOk()
            ->assertSee('data-manual-grade-entry', false)->assertSee('Manual grade entry — Term 1')
            ->assertSee('Not yet official')
            ->assertSee('data-go-verify', false)
            ->assertDontSee('data-grade-verification', false)->assertDontSee('On this page', false);
    }

    public function test_computed_column_headers_are_spelled_out(): void
    {
        $this->assign();
        $this->item('Quiz 1', 'written_work', 20);
        $this->item('Quiz 2', 'written_work', 15);

        $this->page($this->teacher)->assertOk()
            ->assertSee('<abbr title="Percentage Score">PS</abbr>', false)
            ->assertSee('<abbr title="Weighted Score">WS</abbr>', false)
            ->assertSee('Highest Possible Score')
            ->assertSee("Highest Possible Score of all 2 item(s). A learner's TOTAL counts only the items they have a score for.\">35</td>", false);
    }
}
