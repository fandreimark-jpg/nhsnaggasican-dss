<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Monitoring\ConcernFinding;
use App\Monitoring\EvaluationScope;
use App\Monitoring\MonitoringRule;
use App\Monitoring\Rules\MissingRequirementRule;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use App\Services\GradingEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\BuildsEcrFixture;
use Tests\TestCase;

/**
 * "Rule-based academic monitoring" Phase 2C — automatic synchronization.
 *
 * Every adviser write path that changes assessment evidence (Add item,
 * Edit item, ECR import) re-evaluates exactly the items it changed, after
 * the evidence committed, so concerns open and resolve without anyone
 * running dss:evaluate-concerns. Only EXPLICIT_MISSING_REQUIREMENT runs.
 */
class AcademicMonitoringTriggerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEcrFixture;

    private User $adviser;
    private Section $section;
    private Subject $subject;
    private Student $ana;
    private Student $ben;
    private Student $cara;
    private AssessmentStatusService $statuses;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create([
            'name' => 'Shakespeare', 'grade_level' => 11, 'school_year' => '2026-2027',
            'curriculum' => 'sshs', 'adviser_id' => $this->adviser->id,
        ]);
        $this->subject = Subject::factory()->create([
            'name' => 'General Mathematics', 'type' => 'core',
            'grade_level' => 11, 'subject_group' => 'core_academic',
        ]);
        $this->ana = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000001',
            'last_name' => 'Ana', 'first_name' => 'Aa', 'gender' => 'female',
        ]);
        $this->ben = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000002',
            'last_name' => 'Ben', 'first_name' => 'Bb', 'gender' => 'female',
        ]);
        $this->cara = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000003',
            'last_name' => 'Cara', 'first_name' => 'Cc', 'gender' => 'female',
        ]);

        $this->statuses = new AssessmentStatusService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // --- helpers -------------------------------------------------------

    private function item(string $name = 'Quiz 2', string $component = 'written_work', float $max = 20, ?Section $section = null, int $term = 1): Assessment
    {
        $section ??= $this->section;

        return Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $section->id,
            'grading_period' => $term, 'school_year' => '2026-2027',
            'name' => $name, 'assessment_type' => $name, 'component' => $component,
            'max_score' => $max, 'uploaded_by' => $this->adviser->id,
        ]);
    }

    /** The Edit modal's real request shape: every learner's score field and Missing flag. */
    private function edit(Assessment $item, array $scores, array $missing, ?User $as = null)
    {
        $payloadScores = [];
        $payloadMissing = [];
        foreach ([$this->ana, $this->ben, $this->cara] as $s) {
            $payloadScores[$s->id] = $scores[$s->id] ?? '';
            $payloadMissing[$s->id] = $missing[$s->id] ?? '0';
        }

        return $this->actingAs($as ?? $this->adviser)->put('/adviser/assessments/item/' . $item->id, [
            'max_score' => $item->max_score, 'grading_period' => 1, 'assessment_id' => $item->id,
            'scores' => $payloadScores, 'missing' => $payloadMissing,
        ]);
    }

    private function add(string $name, array $scores, array $missing = [])
    {
        return $this->actingAs($this->adviser)->post('/adviser/assessments/item', [
            'subject_id' => $this->subject->id, 'grading_period' => 1, 'item_name' => $name,
            'component' => 'written_work', 'max_score' => 20, 'scores' => $scores, 'missing' => $missing,
        ]);
    }

    private function openConcernFor(Student $student, Assessment $item): ?AcademicConcern
    {
        return AcademicConcern::open()->forStudent($student->id)
            ->where('context_key', MissingRequirementRule::contextKeyFor($item->id))->first();
    }

    private function uploadEcr(array $cells): void
    {
        $scores = [];
        foreach ($cells as $index => $value) {
            $scores[$this->femaleTermRow($index)] = $value;
        }

        $path = $this->buildFilledEcrCopy(
            gradeLevel: '11', sectionName: 'Shakespeare', courseTitle: 'General Mathematics',
            roster: [
                ['lrn' => '110000000001', 'name' => 'Ana, Aa', 'gender' => 'female'],
                ['lrn' => '110000000002', 'name' => 'Ben, Bb', 'gender' => 'female'],
                ['lrn' => '110000000003', 'name' => 'Cara, Cc', 'gender' => 'female'],
            ],
            scores: ['D' => $scores],
            maxScores: ['D' => 20],
            schoolYearStart: '2026',
        );
        $this->tempFiles[] = $path;

        $detect = $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $this->subject->id, 'grading_period' => 1,
            'file' => new UploadedFile($path, 'ecr.xlsx', null, null, true),
        ])->assertOk();
        $columns = collect($detect->viewData('columns'))
            ->map(fn($c) => ['name' => $c['name'], 'component' => 'written_work', 'max_score' => $c['max_score'] ?? 20])
            ->values()->all();

        $this->actingAs($this->adviser)->post('/adviser/assessments/import', [
            'subject_id' => $this->subject->id, 'grading_period' => 1,
            'stored_filename' => $detect->viewData('storedFilename'), 'columns' => $columns,
        ])->assertRedirect()->assertSessionHas('success')->assertSessionMissing('monitoring_warning');
    }

    // --- 1. Not Yet Given -> Missing --------------------------------------

    public function test_1_marking_missing_on_edit_opens_a_concern_automatically(): void
    {
        $item = $this->item();

        $this->edit($item, [], [$this->ana->id => '1'])->assertSessionHasNoErrors()->assertSessionMissing('monitoring_warning');

        $concern = $this->openConcernFor($this->ana, $item);
        $this->assertNotNull($concern, 'no manual dss:evaluate-concerns run was needed');
        $this->assertSame('For Review', $concern->statusLabel());
        $this->assertSame($item->id, $concern->evidence['assessment']['id']);
        $this->assertSame('Quiz 2', $concern->evidence['assessment']['name']);
        $this->assertSame('missing', $concern->evidence['status']);
        $this->assertSame(1, AcademicConcern::count(), 'only the learner marked Missing');
        $this->assertSame(0, Intervention::count());
    }

    public function test_1b_marking_missing_when_adding_an_item_opens_a_concern(): void
    {
        $this->add('Quiz 7', [$this->ana->id => 18], [$this->ben->id => '1'])->assertSessionHas('success');

        $item = Assessment::where('name', 'Quiz 7')->sole();
        $this->assertNotNull($this->openConcernFor($this->ben, $item));
        $this->assertSame(1, AcademicConcern::count());
    }

    // --- 2. Missing -> Completed ------------------------------------------

    public function test_2_a_score_replacing_missing_resolves_the_concern_automatically(): void
    {
        $item = $this->item();
        $this->edit($item, [], [$this->ana->id => '1']);
        $opened = $this->openConcernFor($this->ana, $item);

        $this->edit($item, [$this->ana->id => 16], [])->assertSessionHasNoErrors();

        $resolved = $opened->fresh();
        $this->assertSame(AcademicConcern::STATUS_RESOLVED, $resolved->status);
        $this->assertSame('A score was recorded for "Quiz 2".', $resolved->resolution_reason);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame($opened->evidence, $resolved->evidence, 'history retained');
        $this->assertTrue($resolved->first_detected_at->equalTo($opened->first_detected_at));
        $this->assertSame(1, AcademicConcern::count(), 'no new concern');
        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 16]);
    }

    // --- 3. Missing -> Not Yet Given --------------------------------------

    public function test_3_unticking_missing_with_a_blank_score_resolves_without_creating_a_zero(): void
    {
        $item = $this->item();
        $this->edit($item, [], [$this->ana->id => '1']);

        $this->edit($item, [], [])->assertSessionHasNoErrors();

        $concern = AcademicConcern::sole();
        $this->assertSame(AcademicConcern::STATUS_RESOLVED, $concern->status);
        $this->assertSame('The Missing marker on "Quiz 2" was removed; no score has been recorded yet.', $concern->resolution_reason);
        $this->assertSame(0, AssessmentScore::count(), 'no zero was created');
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $this->ana->id));
    }

    // --- 4-6. Completed, zero, blank ---------------------------------------

    public function test_4_updating_a_score_opens_no_missing_concern(): void
    {
        $item = $this->item();
        $this->edit($item, [$this->ana->id => 12], []);
        $this->edit($item, [$this->ana->id => 19], [])->assertSessionHasNoErrors();

        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertSame(0, AcademicConcern::count());
    }

    public function test_5_an_actual_zero_is_completed_and_opens_no_concern(): void
    {
        $this->add('Quiz 8', [$this->ana->id => 0])->assertSessionHas('success');

        $item = Assessment::where('name', 'Quiz 8')->sole();
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 0]);
        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($item, $this->ana->id));
        $this->assertSame(0, AcademicConcern::count());
    }

    public function test_6_an_ordinary_blank_stays_not_yet_given_and_opens_no_concern(): void
    {
        $this->add('Quiz 9', [$this->ana->id => 15, $this->ben->id => ''])->assertSessionHas('success');

        $item = Assessment::where('name', 'Quiz 9')->sole();
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $this->ben->id));
        $this->assertSame(0, AcademicConcern::count());
    }

    // --- 7-8. Idempotence and re-opening ------------------------------------

    public function test_7_saving_unchanged_missing_evidence_repeatedly_keeps_one_open_concern(): void
    {
        $item = $this->item();
        foreach (range(1, 3) as $i) {
            $this->edit($item, [], [$this->ana->id => '1'])->assertSessionHasNoErrors();
        }

        $this->assertSame(1, AcademicConcern::count());
        $this->assertSame(1, AcademicConcern::open()->count());
    }

    public function test_8_missing_again_after_resolution_opens_a_new_concern_and_keeps_the_old_one(): void
    {
        $item = $this->item();
        $this->edit($item, [], [$this->ana->id => '1']);
        $this->edit($item, [], []);
        $old = AcademicConcern::sole();
        $oldSnapshot = $old->toArray();

        $this->travel(2)->minutes();
        $this->edit($item, [], [$this->ana->id => '1']);

        $this->assertSame(2, AcademicConcern::count());
        $this->assertSame($oldSnapshot, $old->fresh()->toArray(), 'the resolved row is untouched');
        $new = AcademicConcern::open()->sole();
        $this->assertNotSame($old->id, $new->id);
        $this->assertTrue($new->first_detected_at->greaterThan($old->first_detected_at));
    }

    // --- 9-10. ECR import ---------------------------------------------------

    public function test_9_an_imported_real_score_resolves_a_prior_missing_concern(): void
    {
        $this->uploadEcr([0 => 18, 1 => null, 2 => 10]);
        $item = Assessment::sole();
        $this->edit($item, [$this->ana->id => 18, $this->cara->id => 10], [$this->ben->id => '1']);
        $opened = $this->openConcernFor($this->ben, $item);
        $this->assertNotNull($opened);

        $this->uploadEcr([0 => 18, 1 => 14, 2 => 10]);

        $this->assertSame(AcademicConcern::STATUS_RESOLVED, $opened->fresh()->status);
        $this->assertSame('A score was recorded for "' . $item->name . '".', $opened->fresh()->resolution_reason);
        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ben->id, 'score' => 14]);
    }

    public function test_10_an_ecr_blank_creates_no_marker_and_no_concern_and_keeps_an_existing_one(): void
    {
        $this->uploadEcr([0 => 18, 1 => null, 2 => 10]);
        $this->assertSame(0, AssessmentLearnerStatus::count());
        $this->assertSame(0, AcademicConcern::count());

        // A learner the adviser marked Missing, blank again in a repeat upload:
        // the blank proves nothing either way, so the concern stays open.
        $item = Assessment::sole();
        $this->edit($item, [$this->ana->id => 18, $this->cara->id => 10], [$this->ben->id => '1']);
        $this->uploadEcr([0 => 18, 1 => null, 2 => 10]);

        $this->assertSame(1, AcademicConcern::open()->count());
        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ben->id));
    }

    // --- 11, 14. Scope --------------------------------------------------------

    public function test_11_and_14_synchronization_touches_only_the_changed_item(): void
    {
        $otherAdviser = User::factory()->create(['role' => 'adviser']);
        $curie = Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $otherAdviser->id]);
        $carl = Student::factory()->create(['section_id' => $curie->id, 'lrn' => '110000000009']);

        $quiz1 = $this->item('Quiz 1');
        $quiz2 = $this->item('Quiz 2');
        $curieQuiz = $this->item('Quiz 1', section: $curie);
        $term2Quiz = $this->item('Quiz T2', term: 2);
        $this->statuses->markMissing($quiz1, $this->ben->id, $this->adviser->id);
        $this->statuses->markMissing($curieQuiz, $carl->id, $otherAdviser->id);
        $this->statuses->markMissing($term2Quiz, $this->cara->id, $this->adviser->id);
        $monitoring = app(AcademicMonitoringService::class);
        $monitoring->evaluate('2026-2027', 1);
        $monitoring->evaluate('2026-2027', 2);
        $before = AcademicConcern::orderBy('id')->get()->map->toArray()->all();
        $this->assertCount(3, $before);

        $this->travel(5)->minutes();
        $this->edit($quiz2, [], [$this->ana->id => '1'])->assertSessionHasNoErrors();

        $after = AcademicConcern::orderBy('id')->get();
        $this->assertCount(4, $after);
        foreach ($before as $row) {
            $this->assertSame($row, AcademicConcern::find($row['id'])->toArray(),
                'a concern outside the edited item — another item, section or term — is not touched, not even last_evaluated_at');
        }
        $this->assertNotNull($this->openConcernFor($this->ana, $quiz2));

        // The narrow scope also cannot RESOLVE outside itself: clearing
        // Quiz 1's marker behind the service's back is not noticed by an
        // edit of Quiz 2.
        $this->statuses->clearMissing($quiz1->id, $this->ben->id);
        $this->edit($quiz2, [], [$this->ana->id => '1']);
        $this->assertNotNull($this->openConcernFor($this->ben, $quiz1));
    }

    // --- 12-13. No intervention; history -----------------------------------------

    public function test_12_and_13_no_intervention_is_created_and_history_is_retained_through_a_full_cycle(): void
    {
        $item = $this->item();
        $this->edit($item, [], [$this->ana->id => '1', $this->ben->id => '1']);
        $this->edit($item, [$this->ana->id => 17], [$this->ben->id => '1']);
        $this->edit($item, [$this->ana->id => 17], []);

        $this->assertSame(0, Intervention::count());
        $this->assertSame(2, AcademicConcern::count());
        $this->assertSame(2, AcademicConcern::resolved()->count());
        $this->assertSame(
            ['A score was recorded for "Quiz 2".', 'The Missing marker on "Quiz 2" was removed; no score has been recorded yet.'],
            AcademicConcern::orderBy('student_id')->pluck('resolution_reason')->all()
        );
    }

    // --- 15. Authorization ----------------------------------------------------------

    public function test_15_unauthorized_or_closed_term_writes_change_no_concern(): void
    {
        $item = $this->item();
        $otherAdviser = User::factory()->create(['role' => 'adviser']);
        Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $otherAdviser->id]);

        $this->edit($item, [], [$this->ana->id => '1'], $otherAdviser)->assertForbidden();
        $this->edit($item, [], [$this->ana->id => '1'], User::factory()->principal()->create())->assertForbidden();
        $this->edit($item, [], [$this->ana->id => '1'], User::factory()->admin()->create())->assertForbidden();
        $this->assertSame(0, AcademicConcern::count());

        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);
        $this->edit($item, [], [$this->ana->id => '1'])->assertSessionHas('error');
        $this->assertSame(0, AcademicConcern::count());
        $this->assertSame(0, AssessmentLearnerStatus::count());
    }

    // --- 16. Official grading ----------------------------------------------------------

    public function test_16_official_grade_output_is_unchanged_by_automatic_monitoring(): void
    {
        $ww = $this->item('Quiz 1', 'written_work', 20);
        $ww2 = $this->item('Quiz 2', 'written_work', 20);
        $pt = $this->item('PT 1', 'performance_task', 50);
        $ex = $this->item('Exam', 'examination', 40);
        foreach ([[$ww, 18], [$pt, 40], [$ex, 30]] as [$i, $score]) {
            AssessmentScore::create(['assessment_id' => $i->id, 'student_id' => $this->ana->id, 'score' => $score]);
        }
        $grade = fn() => (new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027');
        $before = $grade();

        $this->edit($ww2, [], [$this->ana->id => '1']);

        $this->assertSame(1, AcademicConcern::count());
        $this->assertSame($before, $grade());
        $this->assertEqualsWithDelta(90.0, $before['components']['written_work'], 0.001);
    }

    // --- Concurrency -------------------------------------------------------------------

    public function test_a_concurrent_open_of_the_same_concern_yields_one_row_and_no_error(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $openKey = AcademicConcern::openKeyFor(MissingRequirementRule::KEY, $this->ana->id, MissingRequirementRule::contextKeyFor($item->id));

        // Simulate another request committing the same open concern AFTER
        // this evaluation read the (empty) open set and BEFORE it inserts:
        // the competing row lands right after that read, outside the
        // savepoint the insert runs in — as a real other connection's
        // committed row would be.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $openKey, $item) {
            if ($raced || !str_contains($query->sql, 'from "academic_concerns"') || !str_contains($query->sql, '"status" = ?')) {
                return;
            }
            $raced = true;
            DB::table('academic_concerns')->insert([
                'student_id' => $this->ana->id, 'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
                'school_year' => '2026-2027', 'grading_period' => 1,
                'rule_key' => MissingRequirementRule::KEY, 'rule_version' => 1,
                'context_key' => MissingRequirementRule::contextKeyFor($item->id), 'open_key' => $openKey,
                'evidence' => json_encode(['placeholder' => true]), 'status' => 'open',
                'first_detected_at' => now(), 'last_evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $reports = app(AcademicMonitoringService::class)->synchronizeAssessments([$item->id]);

        $this->assertTrue($raced, 'the competing insert was simulated');
        $this->assertSame(1, AcademicConcern::count(), 'the unique open_key kept it to one row');
        $this->assertSame([], $reports[0]['created'], 'this evaluation did not open it — the other one did');
        $this->assertCount(1, $reports[0]['refreshed'], 'it reconciled the row it lost to instead');
        $concern = $this->openConcernFor($this->ana, $item);
        $this->assertSame('Quiz 2', $concern->evidence['assessment']['name'], 'and brought its evidence up to date');
    }

    public function test_a_non_unique_database_error_while_opening_a_concern_is_not_swallowed(): void
    {
        $item = $this->item();
        $badRule = new class implements MonitoringRule {
            public function key(): string { return 'test_bad_rule'; }
            public function version(): int { return 1; }
            public function label(): string { return 'Test'; }
            public function findings(EvaluationScope $scope): iterable
            {
                // A learner id that does not exist: a foreign-key failure.
                yield new ConcernFinding(999999, null, $scope->sectionIds[0], $scope->schoolYear, $scope->gradingPeriod, 'x:1', [], 'bad');
            }
            public function constrainOpenConcerns(Builder $query, EvaluationScope $scope): void {}
            public function resolutionReason(AcademicConcern $concern): string { return ''; }
        };

        try {
            (new AcademicMonitoringService([$badRule]))->synchronizeAssessments([$item->id]);
            $this->fail('A foreign-key failure must propagate.');
        } catch (QueryException $e) {
            $this->assertNotInstanceOf(UniqueConstraintViolationException::class, $e);
        }
        $this->assertSame(0, AcademicConcern::count());
    }

    // --- Failure after a committed save ---------------------------------------------------

    public function test_a_monitoring_failure_keeps_the_saved_evidence_reports_the_exception_and_tells_the_adviser(): void
    {
        Exceptions::fake();
        config()->set('dss.monitoring_rules', [ThrowingMonitoringRule::class]);
        $item = $this->item();

        $this->edit($item, [$this->ana->id => 12], [$this->ben->id => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success')
            ->assertSessionHas('monitoring_warning', fn(string $message) =>
                str_contains($message, 'Your changes were saved')
                && str_contains($message, 'Academic Monitoring could not update')
                && str_contains($message, 'do not rely on the concern status')
                // No exception text reaches the adviser; it stays in the log.
                && !str_contains($message, 'monitoring rule failed')
                && !str_contains($message, 'RuntimeException'));

        Exceptions::assertReported(\RuntimeException::class);
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 12]);
        $this->assertSame(AssessmentStatusService::MISSING, $this->statuses->statusFor($item, $this->ben->id));
        $this->assertSame(0, AcademicConcern::count());

        // The integrity check makes the gap visible to the administrator.
        config()->set('dss.monitoring_rules', [MissingRequirementRule::class]);
        $this->artisan('dss:check-integrity')->expectsOutputToContain('Academic concerns out of date')->assertExitCode(1);

        $this->followingRedirects()
            ->get('/adviser/assessments?subject_id=' . $this->subject->id . '&period=1')
            ->assertOk();
    }

    public function test_the_monitoring_warning_is_shown_on_the_assessments_page(): void
    {
        $this->actingAs($this->adviser)
            ->withSession(['monitoring_warning' => 'Your changes were saved, but Academic Monitoring could not update its concerns for this assessment.'])
            ->get('/adviser/assessments?subject_id=' . $this->subject->id . '&period=1')
            ->assertOk()
            ->assertSee('Academic Monitoring could not update its concerns');
    }

    // --- Deleted item lifecycle ----------------------------------------------------------------

    public function test_an_item_that_no_longer_exists_is_skipped_by_sync_and_resolved_by_full_evaluation(): void
    {
        // No route deletes an assessment item; only dss:truncate can remove
        // one. The lifecycle is still defined: the concern is resolved with
        // the reason stated, never deleted.
        $item = $this->item();
        $this->edit($item, [], [$this->ana->id => '1']);
        $concern = AcademicConcern::sole();
        $item->delete(); // cascades its marker, as the schema defines

        $this->assertSame([], app(AcademicMonitoringService::class)->synchronizeAssessments([$item->id]));
        $this->assertTrue($concern->fresh()->isOpen());

        app(AcademicMonitoringService::class)->evaluate('2026-2027', 1);
        $this->assertSame(AcademicConcern::STATUS_RESOLVED, $concern->fresh()->status);
        $this->assertSame('The assessment item "Quiz 2" no longer exists.', $concern->fresh()->resolution_reason);
    }
}

/** A rule that always fails — for the post-commit failure test only. */
class ThrowingMonitoringRule implements MonitoringRule
{
    public function key(): string { return 'test_throwing_rule'; }
    public function version(): int { return 1; }
    public function label(): string { return 'Test'; }
    public function findings(EvaluationScope $scope): iterable { throw new \RuntimeException('monitoring rule failed'); }
    public function constrainOpenConcerns(Builder $query, EvaluationScope $scope): void {}
    public function resolutionReason(AcademicConcern $concern): string { return ''; }
}
