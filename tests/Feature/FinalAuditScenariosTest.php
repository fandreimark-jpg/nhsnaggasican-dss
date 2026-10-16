<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\ReportSubmission;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Monitoring\MonitoringStatus;
use App\Monitoring\Rules\MissingRequirementRule;
use App\Monitoring\Rules\OfficialFailingSubjectRule;
use App\Services\AcademicMonitoringService;
use App\Services\MonitoringClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final pre-demo audit (2026-09-27) — the mandatory business scenarios walked
 * end to end through the real HTTP workflows (Adviser Add Item, Encode
 * Grades, Submit Report; Principal Academic Monitoring, Dashboard,
 * intervention; Adviser acknowledge/deliver), on synthetic data only.
 */
class FinalAuditScenariosTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;

    private User $principal;
    private User $adviser;
    private Section $section;
    /** @var array<string, Subject> */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->principal = User::factory()->principal()->create();
        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create([
            'name' => 'Shakespeare', 'grade_level' => 11, 'school_year' => '2026-2027', 'curriculum' => 'sshs',
            'track_id' => Track::factory()->create()->id, 'adviser_id' => $this->adviser->id,
        ]);
        foreach (['Subject A', 'Subject B', 'Subject C'] as $name) {
            $this->subjects[$name] = Subject::factory()->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        }
    }

    private function learner(string $last, string $lrn): Student
    {
        return Student::factory()->create(['section_id' => $this->section->id, 'last_name' => $last, 'first_name' => 'X', 'lrn' => $lrn]);
    }

    /** An OFFICIAL grade exactly as Verify records it (verified, not provisional). */
    private function official(Student $s, string $subject, float $grade): void
    {
        Grade::create([
            'student_id' => $s->id, 'subject_id' => $this->subjects[$subject]->id, 'section_id' => $this->section->id,
            'grading_period' => 1, 'school_year' => '2026-2027', 'grade' => $grade, 'computed_grade' => $grade,
            'is_verified' => true, 'verified_at' => now(), 'encoded_by' => $this->adviser->id,
        ]);
    }

    /** The Adviser's supported correction path: Encode Grades (POST adviser.grades.store). */
    private function encode(Student $s, string $subject, float $grade)
    {
        return $this->actingAs($this->adviser)->post(route('adviser.grades.store'), [
            'grading_period' => 1,
            'grades' => [['student_id' => $s->id, 'subject_id' => $this->subjects[$subject]->id, 'grade' => $grade]],
        ]);
    }

    private function monitoringOf(Student $s): MonitoringStatus
    {
        return app(MonitoringClassificationService::class)->forStudent($s->id, '2026-2027', 1);
    }

    private function openSubjectConcerns(Student $s)
    {
        return AcademicConcern::where('student_id', $s->id)->where('rule_key', OfficialFailingSubjectRule::KEY)->where('status', 'open')->get();
    }

    // §10/§11 — A = 18, B = 0, C = blank, D = explicit Missing, via the Adviser's real Add Item form.
    public function test_missing_requirement_abcd_through_add_item_and_the_principal_sees_c_and_d(): void
    {
        [$a, $b, $c, $d] = [$this->learner('Alpha', '120000000001'), $this->learner('Bravo', '120000000002'),
            $this->learner('Charlie', '120000000003'), $this->learner('Delta', '120000000004')];

        // Add Assessment (details only), then the scores in the Class Record grid.
        $this->addAssessment($this->adviser, $this->section, $this->subjects['Subject A'], ['item_name' => 'Quiz 1', 'max_score' => 20])
            ->assertSessionHasNoErrors();
        $item = Assessment::where('name', 'Quiz 1')->sole();
        $this->saveCells($this->adviser, $item, [$a->id => '18', $b->id => '0', $c->id => '', $d->id => 'M'])->assertOk();
        $this->assertEquals(18, AssessmentScore::where('assessment_id', $item->id)->where('student_id', $a->id)->value('score'));
        $this->assertEquals(0, AssessmentScore::where('assessment_id', $item->id)->where('student_id', $b->id)->value('score'), 'a 0 is a recorded score');
        $this->assertFalse(AssessmentScore::where('student_id', $c->id)->exists(), 'blank is never 0');
        $this->assertFalse(AssessmentScore::where('student_id', $d->id)->exists());

        // Automatic Missing (2026-10-04): Charlie's blank, saved with Quiz 1, is Missing too.
        $concerns = AcademicConcern::where('rule_key', MissingRequirementRule::KEY)->where('status', 'open')->get();
        $this->assertEqualsCanonicalizing([$c->id, $d->id], $concerns->pluck('student_id')->all(), 'only the Missing learners have a concern; a 0 is not Missing');
        foreach ([$a, $b] as $s) {
            $this->assertSame(MonitoringStatus::NO_CURRENT_CONCERN, $this->monitoringOf($s)->classification());
        }
        foreach ([$c, $d] as $s) {
            $this->assertSame(MonitoringStatus::FOR_REVIEW, $this->monitoringOf($s)->classification());
        }

        // Idempotent: a full re-evaluation opens nothing new.
        app(AcademicMonitoringService::class)->evaluate('2026-2027', 1);
        app(AcademicMonitoringService::class)->evaluate('2026-2027', 1);
        $this->assertSame(2, AcademicConcern::where('status', 'open')->count());

        $page = $this->actingAs($this->principal)->get(route('principal.academic-monitoring'))->assertOk();
        $html = $page->getContent();
        $page->assertSee('Delta')->assertSee('Charlie')->assertSee('Quiz 1')->assertSee('Subject A')->assertSee('Missing Requirement');
        foreach (['Alpha', 'Bravo'] as $name) {
            $this->assertStringNotContainsString($name, $html, $name . ' must not be listed');
        }
    }

    // §13 — professor scenario, then a higher grade elsewhere, then correction through Encode Grades.
    public function test_professor_scenario_a_higher_other_subject_never_resolves_and_a_real_correction_does(): void
    {
        $l = $this->learner('Navarro', '120000000011');
        $this->official($l, 'Subject A', 90);
        $this->official($l, 'Subject B', 88);
        $this->official($l, 'Subject C', 74);

        $report = $this->actingAs($this->adviser)->get(route('adviser.submit.report', ['period' => 1]))->assertOk();
        $r = $report->viewData('officialResults')[$l->id];
        $this->assertSame([84.0, 2, 1], [$r['overall_average'], $r['passed'], $r['failed']]);
        $this->assertStringContainsString('Subject C — 74.00 — Failed', $report->getContent());
        $this->assertCount(1, $this->openSubjectConcerns($l));
        $this->assertSame(MonitoringStatus::FOR_REVIEW, $this->monitoringOf($l)->classification());

        // Raise an already-passing subject far higher: C's concern stays open.
        $this->encode($l, 'Subject A', 100)->assertSessionHas('success');
        $this->assertEquals(100, Grade::where('student_id', $l->id)->where('subject_id', $this->subjects['Subject A']->id)->value('grade'));
        $open = $this->openSubjectConcerns($l);
        $this->assertCount(1, $open);
        $this->assertSame($this->subjects['Subject C']->id, $open->first()->subject_id);

        // Correct Subject C through the same supported path: resolved, history kept.
        $this->encode($l, 'Subject C', 80)->assertSessionHas('success');
        $this->assertCount(0, $this->openSubjectConcerns($l));
        $resolved = AcademicConcern::where('student_id', $l->id)->where('status', 'resolved')->sole();
        $this->assertNotNull($resolved->resolved_at);
        $this->assertNotEmpty($resolved->resolution_reason);
        $this->assertSame(MonitoringStatus::NO_CURRENT_CONCERN, $this->monitoringOf($l)->classification());

        // Re-evaluation is idempotent: nothing is reopened or duplicated.
        app(AcademicMonitoringService::class)->evaluate('2026-2027', 1);
        $this->assertSame(1, AcademicConcern::where('student_id', $l->id)->count());
    }

    // §14/§15/§16/§19 — two failed subjects = one learner, two concerns; clean and Missing-only learners.
    public function test_multiple_failures_count_one_learner_and_clean_and_missing_only_learners_read_correctly(): void
    {
        $many = $this->learner('Evangelista', '120000000021');
        $clean = $this->learner('Lazaro', '120000000022');
        $missingOnly = $this->learner('Ilagan', '120000000023');
        foreach ([[$many, [73, 74, 90]], [$clean, [95, 91, 89]], [$missingOnly, [85, 86, 87]]] as [$s, $g]) {
            $this->official($s, 'Subject A', $g[0]);
            $this->official($s, 'Subject B', $g[1]);
            $this->official($s, 'Subject C', $g[2]);
        }
        $this->addAssessment($this->adviser, $this->section, $this->subjects['Subject B'],
            ['item_name' => 'Performance Task 2', 'component' => 'performance_task', 'max_score' => 50])->assertSessionHasNoErrors();
        $this->saveCells($this->adviser, Assessment::where('name', 'Performance Task 2')->sole(), [$missingOnly->id => 'M'])->assertOk();

        $this->assertCount(2, $this->openSubjectConcerns($many));
        $this->assertSame(2, $this->monitoringOf($many)->openCount());
        $this->assertSame(MonitoringStatus::NO_CURRENT_CONCERN, $this->monitoringOf($clean)->classification());
        $this->assertSame(MonitoringStatus::FOR_REVIEW, $this->monitoringOf($missingOnly)->classification());
        $this->assertCount(0, $this->openSubjectConcerns($missingOnly), 'a Missing Requirement invents no failed subject');

        $summary = $this->actingAs($this->principal)->get(route('principal.dashboard'))->assertOk()->viewData('academicMonitoring');
        $this->assertSame(2, $summary['learners_for_review'], 'distinct learners, not concerns');
        $this->assertSame(3, $summary['open_concerns']);

        $report = $this->actingAs($this->adviser)->get(route('adviser.submit.report', ['period' => 1]))->assertOk();
        $this->assertSame(2, $report->viewData('officialResults')[$many->id]['failed']);
        $this->assertSame(0, $report->viewData('officialResults')[$clean->id]['failed']);
        $this->assertSame(0, $report->viewData('officialResults')[$missingOnly->id]['failed']);
        $report->assertDontSee('High Risk')->assertDontSee('Moderate Risk')->assertDontSee('Low Risk');
    }

    // §22 — Principal intervention from a concern → Adviser acknowledge + deliver → concern still open.
    public function test_intervention_lifecycle_never_resolves_the_underlying_concern(): void
    {
        $l = $this->learner('Navarro', '120000000031');
        $this->official($l, 'Subject C', 74);
        $concern = $this->openSubjectConcerns($l)->sole();

        $this->actingAs($this->principal)->post(route('principal.academic-monitoring.review', $concern))->assertSessionHas('success');
        $this->actingAs($this->principal)->post(route('principal.interventions.store'), [
            'academic_concern_id' => $concern->id, 'student_id' => $l->id, 'subject_id' => $this->subjects['Subject C']->id,
            'grading_period' => 1, 'recommended_type' => 'teacher_monitoring',
        ])->assertSessionHas('success');
        $iv = Intervention::sole();
        $this->assertSame($concern->id, $iv->academic_concern_id);

        $this->actingAs($this->adviser)->get(route('adviser.interventions'))->assertOk()->assertSee('Navarro')->assertSee('Subject C');
        $this->actingAs($this->adviser)->post(route('adviser.interventions.acknowledge', $iv))->assertSessionHasNoErrors();
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $iv), ['delivery_notes' => 'Met the learner after class.'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($iv->fresh()->delivered_at);
        $this->assertTrue($concern->fresh()->isOpen(), 'only academic evidence resolves a concern');

        // A second delivery is refused and does not overwrite the first.
        $firstDelivered = $iv->fresh()->delivered_at;
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $iv), ['delivery_notes' => 'Again.']);
        $this->assertEquals($firstDelivered, $iv->fresh()->delivered_at);
        $this->assertSame('Met the learner after class.', $iv->fresh()->delivery_notes);
    }

    // §33 — a double-submitted Add Item: the twin that loses the race gets the
    // ordinary refusal, never a 500, and never overwrites the winner's item.
    public function test_a_raced_duplicate_add_item_is_refused_cleanly_and_leaves_the_winner_intact(): void
    {
        $l = $this->learner('Racer', '120000000051');
        $subjectId = $this->subjects['Subject A']->id;

        // Simulate the twin request committing between this request's
        // same-name check and its insert.
        Assessment::creating(function (Assessment $item) use ($subjectId) {
            if ($item->name === 'Race Quiz' && !Assessment::where('name', 'Race Quiz')->exists()) {
                \Illuminate\Support\Facades\DB::table('assessments')->insert([
                    'subject_id' => $subjectId, 'section_id' => $item->section_id, 'grading_period' => 1, 'school_year' => '2026-2027',
                    'name' => 'Race Quiz', 'assessment_type' => 'Race Quiz', 'component' => 'written_work', 'max_score' => 50,
                    'uploaded_by' => $this->adviser->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->actingAs($this->adviser)->from(route('adviser.grades'))->post(route('teacher.assessments.store'), [
            'section_id' => $this->section->id, 'subject_id' => $subjectId, 'grading_period' => 1, 'item_name' => 'Race Quiz',
            'component' => 'written_work', 'max_score' => 10,
        ])->assertRedirect()->assertSessionHasErrors('item_name', null, 'addItem');

        // One SQLite connection cannot hold a separately committed twin: the
        // simulated twin was written inside the loser's transaction and rolled
        // back with it. The winner-survives half was proven on MariaDB with 8
        // truly concurrent processes (final audit, 2026-09-27: 1 item, 0
        // errors). What one connection CAN prove: the loser's own writes are
        // all gone and it was refused, not 500'd, not turned into an update.
        $this->assertSame(0, Assessment::where('max_score', 10)->count(), "the loser's item was never written or merged");
        $this->assertSame(0, AssessmentScore::count(), "the loser's scores are rolled back");
        $this->assertSame(0, \App\Models\AssessmentUpload::count(), "the loser's upload row is rolled back");
    }

    // Release pass §24 — Encode Grades never presents a typed (unverified)
    // grade as a failed official result; only the shared Failing rule tints.
    public function test_encode_grades_labels_a_typed_grade_not_official_and_tints_only_official_failures(): void
    {
        $typed = $this->learner('Typed', '120000000061');
        $official = $this->learner('Official', '120000000062');
        $this->encode($typed, 'Subject A', 70)->assertSessionHas('success');
        $this->official($official, 'Subject A', 70);

        $html = $this->actingAs($this->adviser)->get(route('adviser.grades', ['period' => 1]))->assertOk()->getContent();
        $row = fn(string $name) => substr($html, strpos($html, $name), strpos($html, '</tr>', strpos($html, $name)) - strpos($html, $name));

        $this->assertStringContainsString('data-not-official', $row('Typed'));
        $this->assertStringNotContainsString('text-status-risk', $row('Typed'), 'an unverified 70 is not tinted as a failure');
        $this->assertStringContainsString('text-status-risk', $row('Official'), 'a verified 70 is an official failure');
        $this->assertStringNotContainsString('data-not-official', $row('Official'));
        $this->assertSame(0, AcademicConcern::where('student_id', $typed->id)->count(), 'a typed grade opens no concern');
    }

    // §4 — every failed sign-in says why, and the sixth is throttled with its own message.
    public function test_each_failed_login_shows_its_message_and_the_limit_then_throttles(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->from(route('login'))->post(route('login'), ['email' => $this->principal->email, 'password' => 'wrong-' . $attempt])
                ->assertRedirect(route('login'));
            $this->get(route('login'))->assertOk()->assertSee('These credentials do not match our records.');
            $this->assertGuest();
        }

        $this->from(route('login'))->post(route('login'), ['email' => $this->principal->email, 'password' => 'password'])
            ->assertRedirect(route('login'));
        $this->get(route('login'))->assertSee('Too many login attempts');
        $this->assertGuest();
    }

    // §24 — a closed term refuses item creation, score edits, grade encoding, verify and Submit Report.
    public function test_a_closed_term_refuses_every_adviser_academic_write(): void
    {
        $l = $this->learner('Closed', '120000000041');
        $item = Assessment::factory()->create([
            'subject_id' => $this->subjects['Subject A']->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 9', 'assessment_type' => 'Quiz 9', 'component' => 'written_work',
            'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);
        AcademicTerm::where('school_year', '2026-2027')->update(['is_open' => false]);

        $this->addAssessment($this->adviser, $this->section, $this->subjects['Subject A'], ['item_name' => 'Quiz 2', 'max_score' => 20]);
        $this->editAssessment($this->adviser, $item, ['max_score' => 25]);
        $this->saveCells($this->adviser, $item, [$l->id => '15'])->assertStatus(423);
        $this->encode($l, 'Subject A', 80);
        $this->actingAs($this->adviser)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $l->id, 'subject_id' => $this->subjects['Subject A']->id]);
        $this->actingAs($this->adviser)->post(route('adviser.submit.report.post'), ['grading_period' => 1, 'expected_version' => 0]);

        $this->assertSame(1, Assessment::count(), 'no item created');
        $this->assertEquals(20, $item->fresh()->max_score, 'no item edited');
        $this->assertSame(0, AssessmentScore::count(), 'no score written');
        $this->assertSame(0, Grade::count(), 'no grade written');
        $this->assertSame(0, ReportSubmission::count(), 'no report submitted');
    }
}
