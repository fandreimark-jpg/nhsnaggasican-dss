<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Class Record consolidation" (2026-09-28) — the standalone Adviser
 * "Assessments / Assessment Evidence" page is retired. Its capabilities live
 * on: assessments and scores in the Class Record, the cross-subject summary
 * in Advisory Overview, and official-grade verification on Encode & Verify
 * Grades — still ADVISER-only, still explicit, never automatic.
 */
class ClassRecordConsolidationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    private User $bong;      // adviser of Agila, teaches CESC
    private User $darien;    // adviser elsewhere, teaches General Science in Agila
    private User $carla;     // subject teacher, no assignment in Agila
    private Section $agila;
    private Subject $cesc;
    private Subject $science;
    private Student $ana;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        // The real DepEd tables, as production seeds them (verification needs a transmutation band).
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);

        $this->bong = User::factory()->create(['role' => 'adviser']);
        $this->darien = User::factory()->create(['role' => 'adviser']);
        $this->carla = User::factory()->create(['role' => 'subject_teacher']);
        $this->agila = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $this->bong->id]);
        Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $this->darien->id]);
        foreach (['cesc' => 'Community Engagement', 'science' => 'General Science'] as $prop => $name) {
            $this->{$prop} = Subject::factory()->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
            $this->{$prop}->syncTerms([1, 2, 3]);
        }
        $this->ana = Student::factory()->create(['section_id' => $this->agila->id, 'last_name' => 'ABAD', 'first_name' => 'ANA']);
        TeachingAssignment::create(['user_id' => $this->bong->id, 'section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
        TeachingAssignment::create(['user_id' => $this->darien->id, 'section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
    }

    /** Complete evidence for Ana in General Science, entered by its assigned teacher through the grid. */
    private function completeScienceEvidence(): void
    {
        foreach ([['Quiz 1', 'written_work', null], ['Project 1', 'performance_task', null], ['Exam 1', 'examination', 'term_exam']] as [$name, $component, $role]) {
            $this->addAssessment($this->darien, $this->agila, $this->science, array_filter(['item_name' => $name, 'component' => $component, 'max_score' => 20, 'exam_role' => $role]))->assertSessionHasNoErrors();
            $this->saveCells($this->darien, Assessment::where('name', $name)->sole(), [$this->ana->id => '16'])->assertOk();
        }
    }

    public function test_the_adviser_menu_has_no_assessments_page_but_keeps_the_teaching_and_grade_destinations(): void
    {
        $html = $this->actingAs($this->bong)->get(route('teacher.classes'))->assertOk()->getContent();
        $this->assertStringNotContainsString('href="' . route('adviser.assessments') . '"', $html);
        $this->assertDoesNotMatchRegularExpression('#>\s*Assessments\s*</#', $html);
        foreach (['My Classes', 'Class Record', 'Advisory Overview', 'Encode Grades', 'Verify Grades'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringNotContainsString('Encode &amp; Verify', $html, 'the combined destination is gone');
        $this->assertFalse(view()->exists('adviser.assessments'), 'the page view itself is gone');
    }

    public function test_old_bookmarks_redirect_to_a_revalidated_class_record_selection(): void
    {
        $base = ['school_year' => '2026-2027', 'section_id' => $this->agila->id];

        $this->actingAs($this->bong)->get('/adviser/assessments?period=1&subject_id=' . $this->science->id)
            ->assertRedirect(route('teacher.class-record', $base + ['subject_id' => $this->science->id, 'period' => 1]));

        // An unreadable subject (another grade level) and an unknown term are dropped, never trusted.
        $g12 = Subject::factory()->create(['type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        $this->actingAs($this->bong)->get('/adviser/assessments?period=9&subject_id=' . $g12->id . '&redirect=https://evil.test')
            ->assertRedirect(route('teacher.class-record', $base + ['period' => 1]));

        // The destination answers (no loop, no error) and is advisory read-only for a subject Bong does not teach.
        $this->actingAs($this->bong)->get(route('teacher.class-record', $base + ['subject_id' => $this->science->id, 'period' => 1]))
            ->assertOk()->assertViewHas('canEdit', false);

        // "Add assessment" through the old link: only the assigned teacher gets the Add page.
        $this->actingAs($this->bong)->get('/adviser/assessments?period=1&add_item=1&subject_id=' . $this->science->id)
            ->assertRedirect(route('teacher.class-record', $base + ['subject_id' => $this->science->id, 'period' => 1]));
        $this->actingAs($this->bong)->get('/adviser/assessments?period=1&add_item=1&subject_id=' . $this->cesc->id)
            ->assertRedirect(route('teacher.assessments.create', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1]));

        // An adviser without a section lands on My Classes; other roles are refused.
        $this->actingAs(User::factory()->create(['role' => 'adviser']))->get('/adviser/assessments')->assertRedirect(route('teacher.classes'));
        $this->actingAs($this->carla)->get('/adviser/assessments')->assertForbidden();
        $this->actingAs(User::factory()->principal()->create())->get('/adviser/assessments')->assertForbidden();
    }

    public function test_old_write_requests_are_not_redirected_into_a_write(): void
    {
        $before = [Assessment::count(), Grade::count()];
        foreach (['post', 'put', 'delete'] as $method) {
            $status = $this->actingAs($this->bong)->{$method}('/adviser/assessments', ['subject_id' => $this->cesc->id, 'item_name' => 'X', 'max_score' => 10])->getStatusCode();
            $this->assertSame(405, $status, strtoupper($method) . ' /adviser/assessments must not be served');
        }
        $this->assertSame($before, [Assessment::count(), Grade::count()]);
    }

    public function test_verification_lives_on_encode_and_verify_grades_for_the_adviser_only(): void
    {
        $this->completeScienceEvidence();
        $this->assertSame(0, Grade::count(), 'adding assessments and saving scores never creates or verifies a grade');

        // The adviser (who does NOT teach General Science) sees the Verify action there.
        $page = $this->actingAs($this->bong)->get(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->science->id]));
        $page->assertOk()->assertSee('data-grade-verification', false)->assertSee('data-grade-verify-form', false)->assertSee('verifyAllRemainingBtn', false);
        // Encode Grades carries no verification at all.
        $this->actingAs($this->bong)->get(route('adviser.grades', ['period' => 1]))->assertOk()
            ->assertDontSee('data-grade-verification', false)->assertDontSee('data-grade-verify-form', false)->assertDontSee('verifyAllRemainingBtn', false);

        // ...and verifying works exactly as before, returning to the same place.
        $this->actingAs($this->bong)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $this->ana->id, 'subject_id' => $this->science->id])
            ->assertRedirect(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->science->id]) . '#learner-' . $this->ana->id)
            ->assertSessionHas('success');
        $grade = Grade::sole();
        $this->assertTrue((bool) $grade->is_verified);
        $this->assertSame([$this->ana->id, $this->science->id], [$grade->student_id, $grade->subject_id]);

        // The subject's teacher (an adviser elsewhere) cannot verify Agila's grades; nor can a Subject Teacher, Principal or Admin.
        Grade::query()->update(['is_verified' => false]); // back to unverified: any accepted request below would flip it
        foreach ([$this->darien, $this->carla, User::factory()->principal()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $this->ana->id, 'subject_id' => $this->science->id])
                ->assertSessionMissing('success');
            $this->actingAs($user)->post(route('adviser.grades.verify-all'), ['grading_period' => 1, 'subject_id' => $this->science->id]);
            $this->assertFalse((bool) Grade::sole()->is_verified, "{$user->role} #{$user->id} must not verify Agila's grade");
        }
        Grade::query()->update(['is_verified' => true]);
        $this->actingAs($this->carla)->get(route('adviser.grades'))->assertForbidden();
        $this->actingAs($this->carla)->get(route('adviser.verify-grades'))->assertForbidden();
        $this->actingAs($this->carla)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $this->ana->id, 'subject_id' => $this->science->id])->assertForbidden();
        $this->actingAs($this->carla)->post(route('adviser.grades.verify-all'), ['grading_period' => 1, 'subject_id' => $this->science->id])->assertForbidden();
        $this->assertSame(1, Grade::count());
        $this->assertTrue((bool) Grade::sole()->is_verified);
        $this->assertNull(Grade::where('section_id', '!=', $this->agila->id)->first(), 'nothing verified into another section');
    }

    public function test_the_class_record_offers_no_verify_action_to_anyone(): void
    {
        $this->completeScienceEvidence();
        foreach ([$this->bong, $this->darien] as $user) {
            $this->actingAs($user)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'period' => 1]))
                ->assertOk()->assertDontSee(route('adviser.grades.verify'), false)->assertDontSee('Verify &amp; Use as Official', false);
        }
    }

    public function test_advisory_overview_links_every_subject_to_its_read_only_class_record(): void
    {
        $html = $this->actingAs($this->bong)->get(route('adviser.advisory-overview', ['period' => 1]))->assertOk()->getContent();
        foreach ([$this->cesc, $this->science] as $subject) {
            $this->assertStringContainsString(e(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $subject->id, 'period' => 1])), $html);
        }
        $this->assertStringContainsString('href="' . route('adviser.verify-grades', ['period' => 1]) . '"', $html);
        $this->assertStringNotContainsString('#verify', $html);

        // A subject without Bong's assignment stays read-only after the page's retirement.
        $item = Assessment::factory()->create(['subject_id' => $this->science->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->darien->id]);
        $this->saveCells($this->bong, $item, [$this->ana->id => '10'])->assertForbidden();
    }

    public function test_intervention_support_links_follow_the_teaching_assignment(): void
    {
        $iv = fn(Subject $s) => Intervention::factory()->decided('approved')->create([
            'student_id' => $this->ana->id, 'subject_id' => $s->id, 'grading_period' => 1, 'section_id' => $this->agila->id, 'school_year' => '2026-2027',
            'acknowledged_at' => now(), 'acknowledged_by' => $this->bong->id, 'delivered_at' => now(), 'delivered_by' => $this->bong->id,
        ]);
        $iv($this->cesc);
        $iv($this->science);

        $html = $this->actingAs($this->bong)->get(route('adviser.interventions'))->assertOk()->getContent();
        $this->assertStringContainsString(e(route('teacher.assessments.create', ['section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'period' => 1])), $html, 'Bong teaches CESC: Add Assessment');
        $this->assertStringContainsString(e(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'period' => 1])), $html, 'Bong only advises Science: read-only Class Record');
        $this->assertStringNotContainsString('/adviser/assessments', $html);
    }
}
