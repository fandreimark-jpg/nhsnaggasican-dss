<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Monitoring\Rules\MissingRequirementRule;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use App\Services\GradingEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsEcrFixture;
use Tests\TestCase;

/**
 * "Rule-based academic monitoring" Phase 2B — Academic Concern Foundation.
 *
 * The one enabled rule, EXPLICIT_MISSING_REQUIREMENT: an adviser's explicit
 * Missing marker opens a For Review concern; a blank (Not Yet Given) never
 * does. Concerns are reconciled idempotently, resolved (never deleted) when
 * the evidence no longer meets the rule, and never create an intervention.
 */
class AcademicConcernTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEcrFixture;

    private User $adviser;
    private Section $section;
    private Subject $subject;
    private Student $ana;
    private Student $ben;
    private AssessmentStatusService $statuses;
    private AcademicMonitoringService $monitoring;
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

        $this->statuses = new AssessmentStatusService();
        $this->monitoring = app(AcademicMonitoringService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function item(string $name = 'Quiz 2', string $component = 'written_work', ?Section $section = null, int $term = 1, float $max = 20): Assessment
    {
        $section ??= $this->section;

        return Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $section->id,
            'grading_period' => $term, 'school_year' => $section->school_year,
            'name' => $name, 'assessment_type' => $name, 'component' => $component,
            'max_score' => $max, 'uploaded_by' => $this->adviser->id,
        ]);
    }

    private function evaluate(bool $dryRun = false, ?int $sectionId = null, int $term = 1): array
    {
        return $this->monitoring->evaluate('2026-2027', $term, $sectionId, $dryRun);
    }

    // ---------------------------------------------------------------
    // Opening
    // ---------------------------------------------------------------

    public function test_an_explicit_missing_marker_opens_a_for_review_concern(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $report = $this->evaluate();

        $this->assertCount(1, $report['created']);
        $concern = AcademicConcern::sole();
        $this->assertSame(AcademicConcern::STATUS_OPEN, $concern->status);
        $this->assertSame('For Review', $concern->statusLabel());
        $this->assertSame(MissingRequirementRule::KEY, $concern->rule_key);
        $this->assertSame(1, $concern->rule_version);
        $this->assertSame('assessment:' . $item->id, $concern->context_key);
        $this->assertSame($this->ana->id, $concern->student_id);
        $this->assertSame($this->subject->id, $concern->subject_id);
        $this->assertSame($this->section->id, $concern->section_id);
        $this->assertSame('2026-2027', $concern->school_year);
        $this->assertSame(1, $concern->grading_period);
        $this->assertNotNull($concern->first_detected_at);
        $this->assertNotNull($concern->last_evaluated_at);
        $this->assertNull($concern->resolved_at);
        $this->assertNull($concern->reviewed_by);
        $this->assertSame('Missing Requirement', $this->monitoring->ruleLabel($concern->rule_key));
    }

    public function test_the_evidence_json_matches_the_actual_assessment_evidence(): void
    {
        $item = $this->item('Summative Test 1', 'examination', max: 30);
        $item->update(['exam_role' => 'st1']);
        $marker = $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->evaluate();

        $e = AcademicConcern::sole()->evidence;
        $this->assertSame(MissingRequirementRule::KEY, $e['rule']);
        $this->assertSame('Missing Requirement', $e['concern']);
        $this->assertSame(['id' => $this->ana->id, 'lrn' => '110000000001', 'name' => 'Ana, Aa'], $e['learner']);
        $this->assertSame(['id' => $this->subject->id, 'name' => 'General Mathematics'], $e['subject']);
        $this->assertSame(['id' => $this->section->id, 'name' => 'Shakespeare'], $e['section']);
        $this->assertSame($item->id, $e['assessment']['id']);
        $this->assertSame('Summative Test 1', $e['assessment']['name']);
        $this->assertSame('examination', $e['assessment']['component']);
        $this->assertSame('Examination', $e['assessment']['component_label']);
        $this->assertSame('st1', $e['assessment']['exam_role']);
        $this->assertSame('Summative Test 1', $e['assessment']['exam_role_label']);
        $this->assertEquals(30, $e['assessment']['max_score']);
        $this->assertFalse($e['assessment']['is_additional_support']);
        $this->assertSame(1, $e['term']);
        $this->assertSame('2026-2027', $e['school_year']);
        $this->assertSame('missing', $e['status']);
        $this->assertSame('Missing', $e['status_label']);
        $this->assertSame($this->adviser->id, $e['marked_by']);
        $this->assertSame($marker->created_at->toIso8601String(), $e['marked_at']);
    }

    // ---------------------------------------------------------------
    // Never from a blank
    // ---------------------------------------------------------------

    public function test_not_yet_given_does_not_create_a_concern(): void
    {
        $item = $this->item();
        AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 0]);
        // Ben: no score, no marker -> Not Yet Given.
        $this->assertSame(AssessmentStatusService::NOT_YET_GIVEN, $this->statuses->statusFor($item, $this->ben->id));

        $report = $this->evaluate();

        $this->assertSame([], $report['created']);
        $this->assertSame(0, AcademicConcern::count(), 'neither a blank nor a recorded zero is a Missing concern');
    }

    public function test_an_ordinary_blank_ecr_cell_does_not_create_a_concern(): void
    {
        $path = $this->buildFilledEcrCopy(
            gradeLevel: '11', sectionName: 'Shakespeare', courseTitle: 'General Mathematics',
            roster: [
                ['lrn' => '110000000001', 'name' => 'Ana, Aa', 'gender' => 'female'],
                ['lrn' => '110000000002', 'name' => 'Ben, Bb', 'gender' => 'female'],
            ],
            scores: ['D' => [$this->femaleTermRow(0) => 18, $this->femaleTermRow(1) => null]],
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
        ])->assertSessionHas('success');

        $this->evaluate();

        $this->assertSame(1, Assessment::count());
        $this->assertSame(0, AcademicConcern::count());
    }

    public function test_a_score_wins_over_a_raw_marker_and_no_concern_is_opened(): void
    {
        $item = $this->item();
        // Only a raw write can produce this pair; the resolver says Completed.
        DB::table('assessment_learner_statuses')->insert([
            'assessment_id' => $item->id, 'student_id' => $this->ana->id, 'status' => 'missing',
            'recorded_by' => $this->adviser->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('assessment_scores')->insert([
            'assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->evaluate();

        $this->assertSame(0, AcademicConcern::count());
    }

    // ---------------------------------------------------------------
    // Idempotence and refresh
    // ---------------------------------------------------------------

    public function test_repeated_evaluation_does_not_duplicate_a_concern(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->evaluate();
        $first = AcademicConcern::sole();
        $this->travel(5)->minutes();
        $second = $this->evaluate();
        $third = $this->evaluate();

        $this->assertSame(1, AcademicConcern::count());
        $this->assertSame([], $second['created']);
        $this->assertSame([], $second['refreshed']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $third['unchanged']);
        $fresh = $first->fresh();
        $this->assertTrue($fresh->first_detected_at->equalTo($first->first_detected_at), 'first detection is never overwritten');
        $this->assertTrue($fresh->last_evaluated_at->greaterThan($first->last_evaluated_at));
    }

    public function test_changed_evidence_refreshes_the_same_concern(): void
    {
        $item = $this->item(max: 20);
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->evaluate();
        $concern = AcademicConcern::sole();

        $item->update(['max_score' => 25]);
        $report = $this->evaluate();

        $this->assertCount(1, $report['refreshed']);
        $this->assertSame(1, AcademicConcern::count());
        $this->assertEquals(25, $concern->fresh()->evidence['assessment']['max_score']);
        $this->assertTrue($concern->fresh()->first_detected_at->equalTo($concern->first_detected_at));
    }

    public function test_the_database_refuses_a_second_open_concern_for_the_same_rule_and_context(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->evaluate();

        $copy = AcademicConcern::sole()->replicate();
        $this->expectException(QueryException::class);
        $copy->save();
    }

    // ---------------------------------------------------------------
    // Resolution — retained, never deleted
    // ---------------------------------------------------------------

    public function test_a_score_replacing_missing_resolves_the_concern_and_keeps_it(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->evaluate();
        $opened = AcademicConcern::sole();

        $this->statuses->recordScore($item, $this->ana->id, 17);
        $report = $this->evaluate();

        $this->assertCount(1, $report['resolved']);
        $resolved = $opened->fresh();
        $this->assertSame(AcademicConcern::STATUS_RESOLVED, $resolved->status);
        $this->assertSame('Resolved', $resolved->statusLabel());
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('A score was recorded for "Quiz 2".', $resolved->resolution_reason);
        $this->assertNull($resolved->open_key);
        $this->assertSame($opened->evidence, $resolved->evidence, 'the last evidence is retained');
        $this->assertTrue($resolved->first_detected_at->equalTo($opened->first_detected_at));
        $this->assertSame(1, AcademicConcern::count());
    }

    public function test_removing_the_marker_without_a_score_resolves_with_that_reason(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->evaluate();

        $this->statuses->clearMissing($item->id, $this->ana->id);
        $this->evaluate();

        $this->assertSame(
            'The Missing marker on "Quiz 2" was removed; no score has been recorded yet.',
            AcademicConcern::sole()->resolution_reason
        );
    }

    public function test_marking_missing_again_after_resolution_opens_a_new_concern_and_keeps_the_old_one(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->evaluate();
        $this->statuses->clearMissing($item->id, $this->ana->id);
        $this->evaluate();

        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->evaluate();

        $this->assertSame(2, AcademicConcern::count());
        $this->assertSame(1, AcademicConcern::resolved()->count());
        $this->assertSame(1, AcademicConcern::open()->count());
    }

    public function test_a_concern_can_never_be_deleted(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->evaluate();

        try {
            AcademicConcern::sole()->delete();
            $this->fail('A concern must never be deleted.');
        } catch (\LogicException) {
        }

        $this->assertSame(1, AcademicConcern::count());
        $this->assertTrue($this->ana->hasAcademicReferences(), 'a learner with a concern is protected from deletion');
    }

    // ---------------------------------------------------------------
    // Never an intervention
    // ---------------------------------------------------------------

    public function test_a_concern_never_creates_an_intervention(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->statuses->markMissing($item, $this->ben->id, $this->adviser->id);

        $this->evaluate();
        $this->statuses->recordScore($item, $this->ana->id, 10);
        $this->evaluate();

        $this->assertSame(2, AcademicConcern::count());
        $this->assertSame(0, Intervention::count());
    }

    // ---------------------------------------------------------------
    // Dry run
    // ---------------------------------------------------------------

    public function test_a_dry_run_reports_the_plan_and_writes_nothing(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $plan = $this->evaluate(dryRun: true);
        $this->assertTrue($plan['dry_run']);
        $this->assertCount(1, $plan['created']);
        $this->assertSame(0, AcademicConcern::count());

        $this->evaluate();
        $concern = AcademicConcern::sole();
        $before = $concern->fresh()->toArray();
        $this->statuses->clearMissing($item->id, $this->ana->id);

        $this->travel(5)->minutes();
        $plan = $this->evaluate(dryRun: true);
        $this->assertCount(1, $plan['resolved']);
        $this->assertSame($before, $concern->fresh()->toArray(), 'a dry run does not even touch last_evaluated_at');
    }

    public function test_the_command_dry_run_writes_nothing_and_the_real_run_opens_the_concern(): void
    {
        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);

        $this->artisan('dss:evaluate-concerns', ['school_year' => '2026-2027', 'term' => 1, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would change: 1 opened, 0 refreshed, 0 unchanged, 0 resolved.')
            ->assertExitCode(0);
        $this->assertSame(0, AcademicConcern::count());

        $this->artisan('dss:evaluate-concerns', ['school_year' => '2026-2027', 'term' => 1])
            ->expectsOutputToContain('Done: 1 opened, 0 refreshed, 0 unchanged, 0 resolved.')
            ->assertExitCode(0);
        $this->assertSame(1, AcademicConcern::count());
    }

    public function test_the_command_refuses_an_unknown_year_term_or_foreign_section(): void
    {
        $this->artisan('dss:evaluate-concerns', ['school_year' => '1999-2000', 'term' => 1])->assertExitCode(1);
        $this->artisan('dss:evaluate-concerns', ['school_year' => '2026-2027', 'term' => 9])->assertExitCode(1);

        AcademicYear::create(['school_year' => '2027-2028', 'is_active' => false]);
        $other = Section::factory()->create(['school_year' => '2027-2028', 'grade_level' => 12]);
        $this->artisan('dss:evaluate-concerns', ['school_year' => '2026-2027', 'term' => 1, '--section' => $other->id])
            ->assertExitCode(1);

        $this->assertSame(0, AcademicConcern::count());
    }

    // ---------------------------------------------------------------
    // Scope / data isolation
    // ---------------------------------------------------------------

    public function test_evaluation_is_scoped_to_its_section_term_and_school_year(): void
    {
        $otherAdviser = User::factory()->create(['role' => 'adviser']);
        $curie = Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $otherAdviser->id]);
        $carl = Student::factory()->create(['section_id' => $curie->id, 'lrn' => '110000000009']);

        $shakespeareItem = $this->item('Quiz 2');
        $curieItem = $this->item('Quiz 2', section: $curie);
        $term2Item = $this->item('Quiz T2', term: 2);
        $this->statuses->markMissing($shakespeareItem, $this->ana->id, $this->adviser->id);
        $this->statuses->markMissing($curieItem, $carl->id, $otherAdviser->id);
        $this->statuses->markMissing($term2Item, $this->ben->id, $this->adviser->id);

        // One section: Curie's marker is not touched.
        $this->evaluate(sectionId: $this->section->id);
        $this->assertSame([$this->ana->id], AcademicConcern::pluck('student_id')->all());

        // Evaluating Curie never resolves Shakespeare's open concern.
        $this->statuses->clearMissing($shakespeareItem->id, $this->ana->id);
        $this->evaluate(sectionId: $curie->id);
        $this->assertSame(AcademicConcern::STATUS_OPEN, AcademicConcern::where('student_id', $this->ana->id)->sole()->status);

        // Term 1 never opens Term 2's concern.
        $this->assertSame(0, AcademicConcern::where('student_id', $this->ben->id)->count());
        $this->evaluate(term: 2);
        $this->assertSame(2, AcademicConcern::where('student_id', $this->ben->id)->sole()->grading_period);
    }

    // ---------------------------------------------------------------
    // Rules registry, grading separation, integrity
    // ---------------------------------------------------------------

    public function test_only_the_approved_rule_is_enabled_and_no_rules_means_no_concerns(): void
    {
        $this->assertSame([MissingRequirementRule::class], config('dss.monitoring_rules'));

        $item = $this->item();
        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        (new AcademicMonitoringService([]))->evaluate('2026-2027', 1);

        $this->assertSame(0, AcademicConcern::count());
    }

    public function test_monitoring_does_not_change_official_grading(): void
    {
        $ww = $this->item('Quiz 1', 'written_work', max: 20);
        $ww2 = $this->item('Quiz 2', 'written_work', max: 20);
        $pt = $this->item('PT 1', 'performance_task', max: 50);
        $ex = $this->item('Exam', 'examination', max: 40);
        foreach ([[$ww, 18], [$pt, 40], [$ex, 30]] as [$item, $score]) {
            AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => $score]);
        }
        $this->statuses->markMissing($ww2, $this->ana->id, $this->adviser->id);

        $engine = fn() => (new GradingEngine())->computeGrade($this->ana, $this->subject, $this->section, 1, '2026-2027');
        $before = $engine();
        $this->evaluate();

        $this->assertSame(1, AcademicConcern::count());
        $this->assertSame($before, $engine());
        $this->assertEqualsWithDelta(90.0, $before['components']['written_work'], 0.001, 'Missing is still not a zero');
    }

    public function test_the_migration_is_reversible_and_touches_no_other_table(): void
    {
        $migration = require database_path('migrations/2026_09_27_000001_create_academic_concerns_table.php');
        $item = $this->item();
        AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 15]);

        $migration->down();
        $this->assertFalse(\Schema::hasTable('academic_concerns'));
        $this->assertSame(1, AssessmentScore::count(), 'rolling back touches only its own table');

        $migration->up();
        $this->assertTrue(\Schema::hasTable('academic_concerns'));
        $this->assertSame(1, AssessmentScore::count());
    }

    public function test_the_integrity_command_reports_concerns_out_of_date_until_evaluated(): void
    {
        $item = $this->item();
        $this->artisan('dss:check-integrity')->assertExitCode(0);

        $this->statuses->markMissing($item, $this->ana->id, $this->adviser->id);
        $this->artisan('dss:check-integrity')
            ->expectsOutputToContain('Academic concerns out of date')
            ->assertExitCode(1);

        $this->evaluate();
        $this->artisan('dss:check-integrity')->assertExitCode(0);
        $this->assertSame(1, AcademicConcern::count(), 'the integrity check itself writes nothing');
    }
}
