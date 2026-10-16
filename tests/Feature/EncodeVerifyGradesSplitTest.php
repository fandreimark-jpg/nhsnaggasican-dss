<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Monitoring\Rules\OfficialFailingSubjectRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Encode / Verify split" (2026-09-28): Encode Grades (adviser.grades) and
 * Verify Grades (adviser.verify-grades) are two pages with two sidebar
 * entries. Verification rules, authority and endpoints are unchanged; the
 * page only presents them. Also pins the two safety additions: a repeated
 * individual verify writes nothing new, and a bulk verify whose eligible
 * set changed after the confirmation verifies nothing.
 */
class EncodeVerifyGradesSplitTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    private User $bong;     // adviser of Agila; teaches Community Engagement only
    private User $darien;   // adviser of Curie; teaches General Science in Agila
    private User $carla;    // subject teacher
    private Section $agila;
    private Subject $cesc;
    private Subject $science;
    private Student $ana;   // complete, passing evidence
    private Student $ben;   // Written Work only
    private Student $cy;    // complete, failing evidence

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
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
        $this->ben = Student::factory()->create(['section_id' => $this->agila->id, 'last_name' => 'BAUTISTA', 'first_name' => 'BEN']);
        $this->cy = Student::factory()->create(['section_id' => $this->agila->id, 'last_name' => 'CRUZ', 'first_name' => 'CY']);
        TeachingAssignment::create(['user_id' => $this->bong->id, 'section_id' => $this->agila->id, 'subject_id' => $this->cesc->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
        TeachingAssignment::create(['user_id' => $this->darien->id, 'section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'school_year' => '2026-2027', 'grading_period' => 1]);

        // Science evidence, entered by its assigned teacher in the Class Record.
        foreach ([['Quiz 1', 'written_work', null], ['Project 1', 'performance_task', null], ['Exam 1', 'examination', 'term_exam']] as [$name, $component, $role]) {
            $this->addAssessment($this->darien, $this->agila, $this->science, array_filter(['item_name' => $name, 'component' => $component, 'max_score' => 20, 'exam_role' => $role]))->assertSessionHasNoErrors();
            $item = Assessment::where('name', $name)->sole();
            $values = [$this->ana->id => '16', $this->cy->id => '4'];
            if ($component === 'written_work') {
                $values[$this->ben->id] = '15';
            }
            $this->saveCells($this->darien, $item, $values)->assertOk();
        }
    }

    private function verifyPage(User $user, array $query = [])
    {
        return $this->actingAs($user)->get(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->science->id] + $query));
    }

    private function verifyOne(Student $student, array $extra = [])
    {
        return $this->actingAs($this->bong)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $student->id, 'subject_id' => $this->science->id] + $extra);
    }

    public function test_two_pages_with_two_sidebar_entries_and_exclusive_active_states(): void
    {
        $encode = $this->actingAs($this->bong)->get(route('adviser.grades', ['period' => 1]))->assertOk()->getContent();
        $verify = $this->verifyPage($this->bong)->assertOk()->getContent();

        foreach ([$encode, $verify] as $html) {
            $this->assertStringContainsString('href="' . route('adviser.grades') . '"', $html);
            $this->assertStringContainsString('href="' . route('adviser.verify-grades') . '"', $html);
        }
        $active = fn(string $html) => preg_match_all('#<a href="([^"]+)"[^>]*class="nav-link nav-link-active#', $html, $m) ? $m[1] : [];
        $this->assertSame([route('adviser.grades')], $active($encode));
        $this->assertSame([route('adviser.verify-grades')], $active($verify));
        $this->assertStringContainsString('<title>', $encode);
        $this->assertMatchesRegularExpression('#page-title">\s*Encode Grades#', $encode);
        $this->assertMatchesRegularExpression('#page-title">\s*Verify Grades#', $verify);
    }

    public function test_encode_grades_has_entry_and_import_but_no_verification(): void
    {
        $this->actingAs($this->bong)->get(route('adviser.grades', ['period' => 1]))->assertOk()
            ->assertSee('id="gradeEntryForm"', false)->assertSee(route('adviser.grades.store'), false)
            ->assertSee(route('adviser.grades.import'), false)->assertSee('Save Grades')
            ->assertDontSee('data-grade-verification', false)->assertDontSee('data-grade-verify-form', false)
            ->assertDontSee('verifyAllRemainingBtn', false)->assertDontSee('action="' . route('adviser.grades.verify') . '"', false)
            ->assertDontSee('On this page')->assertDontSee('below, which uses');
    }

    public function test_verify_grades_is_one_workspace_without_manual_entry(): void
    {
        $html = $this->verifyPage($this->bong)->assertOk()
            ->assertSee('Student Performance — General Science, Term 1')
            ->assertSee('id="verifyFilterForm"', false)->assertSee('data-open-class-record', false)
            ->assertSee('data-verify-counts', false)->assertSee('verifyAllRemainingBtn', false)
            ->assertSee('How to read this table')
            ->assertDontSee('id="gradeEntryForm"', false)->assertDontSee('action="' . route('adviser.grades.store') . '"', false)
            ->getContent();
        // Filters, counts, bulk action and table all sit inside ONE workspace section.
        $start = strpos($html, 'data-verify-workspace');
        $end = strpos($html, '</section>', $start);
        foreach (['id="verifyFilterForm"', 'data-open-class-record', 'data-verify-counts', 'verifyAllRemainingBtn', 'data-performance-row'] as $marker) {
            $pos = strpos($html, $marker);
            $this->assertTrue($pos > $start && $pos < $end, "{$marker} is inside the workspace");
        }
        // Open Class Record goes to that exact class; it stays read-only for Bong (not Science's teacher).
        $this->assertStringContainsString(e(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'period' => 1])), $html);
        $this->actingAs($this->bong)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'period' => 1]))
            ->assertOk()->assertSee('data-can-edit="0"', false);
    }

    public function test_rows_show_eligible_incomplete_and_verified_states_and_a_transmuted_value_is_not_official(): void
    {
        $rows = $this->verifyPage($this->bong)->viewData('performance')->keyBy(fn($r) => $r['student']->last_name);
        $this->assertSame('eligible', $rows['ABAD']['verify_state']);
        $this->assertSame('incomplete', $rows['BAUTISTA']['verify_state']);
        $this->assertStringContainsString('Performance Task, Examination', $rows['BAUTISTA']['verify_reason']);

        $html = $this->verifyPage($this->bong)->getContent();
        $row = fn(string $name) => substr($html, strpos($html, $name), strpos($html, '</tr>', strpos($html, $name)) - strpos($html, $name));
        $this->assertStringContainsString('data-grade-verify-form', $row('ABAD, ANA'));
        $this->assertStringContainsString('not official', $row('ABAD, ANA'), 'a transmuted result is labelled not official');
        $this->assertStringContainsString('No official grade', $row('ABAD, ANA'));
        $this->assertStringNotContainsString('data-grade-verify-form', $row('BAUTISTA, BEN'));
        $this->assertStringContainsString('no computable result yet for Performance Task, Examination', $row('BAUTISTA, BEN'));

        // Incomplete evidence stays unverifiable server-side.
        $this->verifyOne($this->ben)->assertSessionHas('error');
        $this->assertNull(Grade::where('student_id', $this->ben->id)->first());

        $this->verifyOne($this->ana)->assertSessionHas('success');
        $this->assertStringContainsString('Official · verified', (fn() => substr($h = $this->verifyPage($this->bong)->getContent(), strpos($h, 'ABAD, ANA'), 4000))());
    }

    public function test_verifying_writes_one_official_grade_one_log_entry_and_reevaluates_concerns_and_a_repeat_changes_nothing(): void
    {
        $this->verifyOne($this->cy, ['status_filter' => 'At Risk'])
            ->assertRedirect(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->science->id, 'status_filter' => 'At Risk']) . '#learner-' . $this->cy->id)
            ->assertSessionHas('success');
        $grade = Grade::where('student_id', $this->cy->id)->sole();
        $this->assertTrue((bool) $grade->is_verified);
        $this->assertLessThanOrEqual(74, (float) $grade->grade, 'fixture sanity: an official failing grade');
        $this->assertSame(1, ActivityLog::where('action', 'verify_computed_grade')->count());
        $this->assertSame(1, AcademicConcern::where('student_id', $this->cy->id)->where('rule_key', OfficialFailingSubjectRule::KEY)->whereNull('resolved_at')->count(),
            'the official failing grade is re-evaluated into a Subject Performance Concern');

        // Repeated / double-submitted request: reported unchanged, nothing written or logged again.
        $before = $grade->fresh()->updated_at;
        $this->verifyOne($this->cy)->assertSessionHas('success', fn($m) => str_contains($m, 'Nothing changed'));
        $this->actingAs($this->bong)->postJson(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $this->cy->id, 'subject_id' => $this->science->id])
            ->assertOk()->assertJsonPath('unchanged', true);
        $this->assertSame(1, Grade::where('student_id', $this->cy->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'verify_computed_grade')->count(), 'no duplicate verification history');
        $this->assertEquals($before, $grade->fresh()->updated_at);
        $this->assertSame(1, AcademicConcern::where('student_id', $this->cy->id)->where('rule_key', OfficialFailingSubjectRule::KEY)->count());
    }

    public function test_only_the_adviser_reaches_verification_pages_and_actions(): void
    {
        foreach ([$this->carla, User::factory()->principal()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('adviser.verify-grades'))->assertForbidden();
            $this->actingAs($user)->get(route('adviser.grades'))->assertForbidden();
            $this->actingAs($user)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $this->ana->id, 'subject_id' => $this->science->id])->assertForbidden();
            $this->actingAs($user)->post(route('adviser.grades.verify-all'), ['grading_period' => 1, 'subject_id' => $this->science->id])->assertForbidden();
            $this->actingAs($user)->get(route('adviser.grades.verify-all.preview', ['grading_period' => 1, 'subject_id' => $this->science->id]))->assertForbidden();
        }
        // Science's teacher (an adviser of another section) cannot verify Agila.
        $this->actingAs($this->darien)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $this->ana->id, 'subject_id' => $this->science->id]);
        $this->actingAs($this->darien)->postJson(route('adviser.grades.verify-all'), ['grading_period' => 1, 'subject_id' => $this->science->id]);
        $this->assertSame(0, Grade::where('section_id', $this->agila->id)->count());
        // Darien's own Verify Grades shows only Curie.
        $sections = $this->actingAs($this->darien)->get(route('adviser.verify-grades'))->assertOk()->viewData('sections');
        $this->assertSame(['Curie'], $sections->pluck('name')->all());
        // A crafted section_id falls back to the adviser's own section, never another's.
        $this->assertSame('Curie', $this->actingAs($this->darien)->get(route('adviser.verify-grades', ['section_id' => $this->agila->id]))->viewData('section')->name);
    }

    public function test_manual_entry_stays_assignment_scoped(): void
    {
        $post = fn(Subject $subject) => $this->actingAs($this->bong)->post(route('adviser.grades.store'), [
            'grading_period' => 1, 'grades' => [['student_id' => $this->ana->id, 'subject_id' => $subject->id, 'grade' => 85]],
        ]);
        $post($this->science)->assertRedirect(route('adviser.grades', ['period' => 1]));
        $this->assertNull(Grade::where('subject_id', $this->science->id)->first(), 'advising Agila does not grant typing Science grades');
        $post($this->cesc)->assertRedirect(route('adviser.grades', ['period' => 1]))->assertSessionHas('success');
        $typed = Grade::where('subject_id', $this->cesc->id)->sole();
        $this->assertFalse((bool) $typed->is_verified, 'a typed grade is not official');
    }

    public function test_a_closed_term_or_historical_year_is_read_only_everywhere(): void
    {
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);
        $html = $this->verifyPage($this->bong)->assertOk()->assertSee('data-verify-lock-reason', false)->getContent();
        $this->assertStringNotContainsString('data-grade-verify-form', $html);
        $this->assertStringNotContainsString('id="verifyAllRemainingBtn"', $html);
        $this->verifyOne($this->ana)->assertSessionHas('error');
        $this->actingAs($this->bong)->postJson(route('adviser.grades.verify-all'), ['grading_period' => 1, 'subject_id' => $this->science->id])->assertStatus(422);
        $this->assertSame(0, Grade::count());

        // A past year's section is viewable, never writable.
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        AcademicYear::create(['school_year' => '2025-2026', 'is_active' => false]);
        $old = Section::factory()->create(['name' => 'Agila Old', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2025-2026', 'adviser_id' => $this->bong->id]);
        $page = $this->actingAs($this->bong)->get(route('adviser.verify-grades', ['school_year' => '2025-2026', 'section_id' => $old->id, 'period' => 1]))->assertOk();
        $this->assertSame($old->id, $page->viewData('section')->id);
        $this->assertFalse($page->viewData('isTermOpen'));
        $this->assertSame(['2026-2027', '2025-2026'], $page->viewData('schoolYears')->all());
    }

    public function test_bulk_scope_is_the_whole_class_and_a_changed_set_verifies_nothing(): void
    {
        $preview = $this->actingAs($this->bong)->getJson(route('adviser.grades.verify-all.preview', ['grading_period' => 1, 'subject_id' => $this->science->id]))->assertOk();
        $this->assertSame(2, $preview->json('eligible_count'));
        $this->assertEqualsCanonicalizing([$this->ana->id, $this->cy->id], $preview->json('eligible_student_ids'));
        $this->assertSame('2026-2027', $preview->json('school_year'));

        // The Status filter narrows the visible rows, not the bulk scope (counts are for the whole class).
        $page = $this->verifyPage($this->bong, ['status_filter' => 'On Track']);
        $this->assertSame(2, $page->viewData('counts')['eligible']);
        $page->assertSee('not limited by the Status filter');

        // An unauthorised or stale target set is refused whole.
        $foreign = Student::factory()->create(['section_id' => Section::where('name', 'Curie')->value('id')]);
        foreach ([[$this->ana->id, $this->cy->id, $foreign->id], [$this->ana->id]] as $expected) {
            $this->actingAs($this->bong)->postJson(route('adviser.grades.verify-all'), ['grading_period' => 1, 'subject_id' => $this->science->id, 'expected_student_ids' => $expected])
                ->assertStatus(409)->assertJsonPath('changed', true);
        }
        $this->assertSame(0, Grade::count(), 'nothing verified when the set differs');

        // The set changes after confirmation: Ana is verified individually in between.
        $confirmed = $preview->json('eligible_student_ids');
        $this->verifyOne($this->ana)->assertSessionHas('success');
        $this->actingAs($this->bong)->postJson(route('adviser.grades.verify-all'), ['grading_period' => 1, 'subject_id' => $this->science->id, 'expected_student_ids' => $confirmed])
            ->assertStatus(409);
        $this->assertSame(1, Grade::count());

        // With the current set it verifies exactly those learners, one log entry each.
        $fresh = $this->actingAs($this->bong)->getJson(route('adviser.grades.verify-all.preview', ['grading_period' => 1, 'subject_id' => $this->science->id]))->json('eligible_student_ids');
        $this->assertSame([$this->cy->id], $fresh);
        $this->actingAs($this->bong)->postJson(route('adviser.grades.verify-all'), ['grading_period' => 1, 'subject_id' => $this->science->id, 'expected_student_ids' => $fresh])
            ->assertOk()->assertJsonPath('verified_count', 1);
        $this->assertSame(2, Grade::where('is_verified', true)->count());
        $this->assertSame(2, ActivityLog::where('action', 'verify_computed_grade')->count());
        $this->assertNull(Grade::where('student_id', $this->ben->id)->first(), 'incomplete evidence is never included');
    }

    /**
     * Existing correction behaviour, pinned as it is (policy not changed):
     * the adviser of the section who is ALSO the subject's assigned teacher
     * may type over an official grade on Encode Grades; no reason is asked;
     * the row stays official (is_verified, verified_at and computed_grade
     * are untouched); concerns re-evaluate from the new value. Added in this
     * pass: the change is audited with both values, and Verify Grades says
     * the VALUE changed, not the evidence.
     */
    public function test_correcting_an_official_grade_keeps_it_official_is_audited_and_labelled_accurately(): void
    {
        // Bong advises Agila and teaches Community Engagement there.
        $item = fn(string $name, string $component, ?string $role) => $this->addAssessment($this->bong, $this->agila, $this->cesc,
            array_filter(['item_name' => $name, 'component' => $component, 'max_score' => 20, 'exam_role' => $role]))->assertSessionHasNoErrors();
        foreach ([['CE Quiz', 'written_work', null], ['CE Project', 'performance_task', null], ['CE Exam', 'examination', 'term_exam']] as [$n, $c, $r]) {
            $item($n, $c, $r);
            $this->saveCells($this->bong, Assessment::where('name', $n)->sole(), [$this->ana->id => '4'])->assertOk();
        }
        $this->actingAs($this->bong)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $this->ana->id, 'subject_id' => $this->cesc->id])
            ->assertSessionHas('success');
        $official = Grade::where('subject_id', $this->cesc->id)->sole();
        $this->assertTrue((bool) $official->is_verified);
        $this->assertLessThanOrEqual(74, (float) $official->grade);
        $concern = fn() => AcademicConcern::where('student_id', $this->ana->id)->where('subject_id', $this->cesc->id)
            ->where('rule_key', OfficialFailingSubjectRule::KEY)->whereNull('resolved_at')->count();
        $this->assertSame(1, $concern(), 'an official failing grade opens a Subject Performance Concern');
        $this->actingAs($this->bong)->get(route('adviser.grades', ['period' => 1]))->assertSee('data-official', false);

        // The correction: no reason field exists or is required.
        $this->actingAs($this->bong)->post(route('adviser.grades.store'), ['grading_period' => 1,
            'grades' => [['student_id' => $this->ana->id, 'subject_id' => $this->cesc->id, 'grade' => 80]]])->assertSessionHas('success');
        $after = $official->fresh();
        $this->assertSame('80.00', number_format((float) $after->grade, 2));
        $this->assertTrue((bool) $after->is_verified, 'existing behaviour: it stays official');
        $this->assertEquals($official->verified_at, $after->verified_at);
        $this->assertSame((float) $official->computed_grade, (float) $after->computed_grade);
        $this->assertSame(0, $concern(), 'a passing official value resolves the concern');

        $log = ActivityLog::where('action', 'correct_official_grade')->sole();
        $this->assertStringContainsString('from ' . number_format((float) $official->grade, 2) . ' to 80.00', $log->description);
        $this->assertSame($official->id, (int) $log->record_id);

        $row = $this->actingAs($this->bong)->get(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->cesc->id]))
            ->viewData('performance')->first(fn($r) => $r['student']->id === $this->ana->id);
        $this->assertSame('verified_changed', $row['verify_state']);
        $this->assertStringContainsString('official value was changed after verification', $row['verify_reason']);

        // An unchanged re-save and a typed grade on an UNverified row are not corrections.
        $this->actingAs($this->bong)->post(route('adviser.grades.store'), ['grading_period' => 1,
            'grades' => [['student_id' => $this->ana->id, 'subject_id' => $this->cesc->id, 'grade' => 80]]]);
        $this->actingAs($this->bong)->post(route('adviser.grades.store'), ['grading_period' => 1,
            'grades' => [['student_id' => $this->ben->id, 'subject_id' => $this->cesc->id, 'grade' => 85]]]);
        $this->assertSame(1, ActivityLog::where('action', 'correct_official_grade')->count());

        // Only the subject's assigned teacher can make it: Science's official grades are out of Bong's reach here.
        $this->verifyOne($this->ana);
        $this->actingAs($this->bong)->post(route('adviser.grades.store'), ['grading_period' => 1,
            'grades' => [['student_id' => $this->ana->id, 'subject_id' => $this->science->id, 'grade' => 99]]]);
        $this->assertNotSame('99.00', number_format((float) Grade::where('subject_id', $this->science->id)->sole()->grade, 2));
    }

    public function test_links_bookmarks_and_redirects_point_at_the_right_page(): void
    {
        // Old #verify bookmarks: the fragment never reaches the server, so Encode Grades carries a narrow
        // client-side redirect to a URL built only from validated context.
        $html = $this->actingAs($this->bong)->get(route('adviser.grades', ['period' => 1, 'subject_id' => $this->science->id]))->getContent();
        $target = route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->science->id]);
        $this->assertStringContainsString("window.location.hash === '#verify'", $html);
        $json = fn(string $url) => json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); // Blade @json's flags
        $this->assertStringContainsString('window.location.replace(' . $json($target) . ')', $html);
        $bogus = $this->actingAs($this->bong)->get(route('adviser.grades', ['period' => 1, 'subject_id' => 999999]))->getContent();
        $this->assertStringContainsString('window.location.replace(' . $json(route('adviser.verify-grades', ['period' => 1])) . ')', $bogus, 'an unknown subject is dropped, never forwarded');

        foreach ([route('adviser.dashboard'), route('adviser.advisory-overview', ['period' => 1])] as $page) {
            $this->assertStringNotContainsString('#verify', $this->actingAs($this->bong)->get($page)->assertOk()->getContent());
        }

        // A failed verify returns to Verify Grades with the same class, never to Encode Grades.
        $this->verifyOne($this->ben, ['status_filter' => 'No Evidence Yet'])
            ->assertRedirect(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->science->id, 'status_filter' => 'No Evidence Yet']))
            ->assertSessionHas('error');
        // A save on Encode Grades returns to Encode Grades.
        $this->actingAs($this->bong)->post(route('adviser.grades.store'), ['grading_period' => 1, 'grades' => [['student_id' => $this->ana->id, 'subject_id' => $this->cesc->id, 'grade' => 88]]])
            ->assertRedirect(route('adviser.grades', ['period' => 1]));
    }
}
