<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Monitoring\MonitoringStatus;
use App\Monitoring\Rules\MissingRequirementRule;
use App\Monitoring\Rules\OfficialFailingSubjectRule;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use App\Services\MonitoringClassificationService;
use App\Services\SubjectAnalysisService;
use App\Services\SubjectResultSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SUBJECT FIRST (professor's pre-final-defense feedback).
 *
 * Each subject is evaluated on its own; the overall average is summary only
 * and never decides whether a learner needs review. An OFFICIAL failing
 * subject grade (the app's existing Failing rule — verified, not
 * provisional, at or below the DepEd failing mark) opens a Subject
 * Performance Concern for that subject, however high the others are.
 *
 *   Monitoring = No Current Concern (0 open, active-rule concerns) | For Review
 *   The count is never a severity; risk_results never count; an in-term
 *   Below Target reading, an unverified or a provisional grade opens nothing.
 */
class SubjectFirstMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $adviser;
    private Section $section;
    private Student $ana;
    private Student $ben;
    /** @var array<string, Subject> */
    private array $subjects = [];
    private MonitoringClassificationService $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->principal = User::factory()->principal()->create();
        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create([
            'name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027',
            'track_id' => Track::factory()->create()->id, 'adviser_id' => $this->adviser->id,
        ]);
        foreach (['Effective Communication', 'General Mathematics', 'General Science', 'Mabisang Komunikasyon', 'Life and Career Skills'] as $name) {
            $this->subjects[$name] = Subject::factory()->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        }
        $this->ana = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'ABAD', 'first_name' => 'ANA', 'lrn' => '110000000001']);
        $this->ben = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'BAUTISTA', 'first_name' => 'BEN', 'lrn' => '110000000002']);
        $this->classifier = app(MonitoringClassificationService::class);
    }

    // --- helpers ---------------------------------------------------------------

    private function grade(Student $s, string $subject, float $grade, bool $verified = true, bool $provisional = false): Grade
    {
        return Grade::updateOrCreate(
            ['student_id' => $s->id, 'subject_id' => $this->subjects[$subject]->id, 'grading_period' => 1, 'school_year' => '2026-2027'],
            ['section_id' => $this->section->id, 'grade' => $grade, 'is_verified' => $verified, 'is_provisional' => $provisional,
             'encoded_by' => $this->adviser->id]
        );
    }

    /** The professor's example: 90, 72, 88, 92, 93. */
    private function professorScenario(Student $s): void
    {
        foreach (['Effective Communication' => 90, 'General Mathematics' => 72, 'General Science' => 88,
                  'Mabisang Komunikasyon' => 92, 'Life and Career Skills' => 93] as $subject => $grade) {
            $this->grade($s, $subject, $grade);
        }
    }

    private function monitoring(?Student $s = null): MonitoringStatus
    {
        return $this->classifier->forStudent(($s ?? $this->ana)->id, '2026-2027', 1);
    }

    private function subjectConcerns(string $status = 'open')
    {
        return AcademicConcern::where('rule_key', OfficialFailingSubjectRule::KEY)->where('status', $status)->get();
    }

    private function missingOn(string $subject, Student $s): void
    {
        $item = Assessment::factory()->create([
            'subject_id' => $this->subjects[$subject]->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1', 'component' => 'written_work',
            'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);
        (new AssessmentStatusService())->markMissing($item, $s->id, $this->adviser->id);
        app(AcademicMonitoringService::class)->synchronizeAssessments([$item->id]);
    }

    // --- the professor's scenario -------------------------------------------------

    public function test_passing_overall_average_does_not_hide_failed_subject(): void
    {
        $this->professorScenario($this->ana);

        $results = SubjectResultSummary::fromGrades(Grade::with('subject')->where('student_id', $this->ana->id)->get());
        $this->assertSame(87.0, $results['overall_average'], 'the overall average is passing');
        $this->assertSame([4, 1, 0], [$results['passed'], $results['failed'], $results['not_official']]);
        $this->assertSame([['subject' => 'General Mathematics', 'term' => 1, 'grade' => 72.0]], $results['failed_subjects']);

        $concern = $this->subjectConcerns()->sole();
        $this->assertSame($this->subjects['General Mathematics']->id, $concern->subject_id);
        $this->assertEquals(72.0, $concern->evidence['official_grade']['grade']);

        $status = $this->monitoring();
        $this->assertSame(MonitoringStatus::FOR_REVIEW, $status->classification());
        $this->assertSame('For Review — Subject Performance Concern', $status->headline());
        $this->assertSame(['Subject Performance Concern: General Mathematics — official grade 72.00 (Failed)'], $status->details);

        // Principal student detail and the section report both name the failed subject beside the passing average.
        $this->actingAs($this->principal)->get(route('principal.students.show', ['student' => $this->ana->id, 'period' => 1]))
            ->assertOk()->assertSee('Overall Average: <strong data-overall-average>87.00</strong>', false)
            ->assertSee('Failed: General Mathematics — 72.00')->assertSee('For Review');
        // The section report shows it once the adviser has SUBMITTED the term (2026-10-07).
        $this->actingAs($this->principal)->get(route('principal.reports', ['section_id' => $this->section->id]))
            ->assertOk()->assertDontSee('General Mathematics — 72.00 (Term 1)');
        $this->submittedReport($this->section, 1);
        $this->actingAs($this->principal)->get(route('principal.reports', ['section_id' => $this->section->id]))
            ->assertOk()->assertSee('General Mathematics — 72.00 (Term 1)')->assertSee('Passed 4');

        // More high grades never hide or resolve it.
        $this->grade($this->ana, 'Effective Communication', 99);
        $this->assertTrue($concern->fresh()->isOpen());
    }

    public function test_two_failed_subjects_are_both_visible_even_when_overall_average_is_passing(): void
    {
        foreach (['Effective Communication' => 95, 'General Mathematics' => 70, 'General Science' => 73,
                  'Mabisang Komunikasyon' => 96, 'Life and Career Skills' => 98] as $subject => $grade) {
            $this->grade($this->ana, $subject, $grade);
        }

        $this->assertCount(2, $this->subjectConcerns(), 'two distinct subject concerns, never merged');
        $status = $this->monitoring();
        $this->assertSame('For Review — 2 Open Concerns', $status->headline());
        $this->assertSame(['Subject Performance Concern' => 2], $status->reasons);
        $this->assertStringNotContainsString('High', $status->headline());

        $this->actingAs($this->principal)->get(route('principal.students.show', ['student' => $this->ana->id, 'period' => 1]))
            ->assertOk()->assertSee('Failed: General Mathematics — 70.00')->assertSee('Failed: General Science — 73.00');
    }

    // --- what is and is not an official failed subject ---------------------------

    public function test_only_an_official_failing_grade_opens_a_concern(): void
    {
        $this->grade($this->ana, 'General Mathematics', 75);                         // passing (75 is not Failing)
        $this->grade($this->ana, 'General Science', 70, verified: false);            // not yet official
        $this->grade($this->ana, 'Effective Communication', 68, provisional: true);  // provisional transmutation

        $this->assertSame(0, AcademicConcern::count());
        $this->assertSame(MonitoringStatus::NO_CURRENT_CONCERN, $this->monitoring()->classification());

        $results = SubjectResultSummary::fromGrades(Grade::with('subject')->where('student_id', $this->ana->id)->get());
        $this->assertSame([1, 0, 2], [$results['passed'], $results['failed'], $results['not_official']], 'never labelled Failed until official');

        // No official grade at all is not Failed either.
        $this->assertSame(0, SubjectResultSummary::fromGrades(collect())['failed']);
    }

    public function test_an_in_term_below_target_reading_opens_nothing(): void
    {
        $item = Assessment::factory()->create([
            'subject_id' => $this->subjects['General Mathematics']->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1', 'component' => 'written_work',
            'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);
        (new AssessmentStatusService())->recordScore($item, $this->ana->id, 3); // 15% — far below target, still in term
        app(AcademicMonitoringService::class)->evaluate('2026-2027', 1);

        $this->assertSame(0, AcademicConcern::count(), 'no in-term numeric trigger was invented');
    }

    // --- lifecycle --------------------------------------------------------------

    public function test_repeated_sync_does_not_duplicate_and_evidence_change_resolves_with_history(): void
    {
        $this->grade($this->ana, 'General Mathematics', 72);
        $this->grade($this->ana, 'General Mathematics', 71);  // re-saved, still failing
        app(AcademicMonitoringService::class)->evaluate('2026-2027', 1);
        app(AcademicMonitoringService::class)->evaluate('2026-2027', 1);
        $this->assertCount(1, $this->subjectConcerns());
        $this->assertEquals(71.0, $this->subjectConcerns()->sole()->evidence['official_grade']['grade'], 'evidence refreshed in place');

        $this->grade($this->ana, 'General Mathematics', 80);  // a valid passing official grade
        $resolved = $this->subjectConcerns('resolved')->sole();
        $this->assertSame('The official General Mathematics grade is now 80.00 (passing).', $resolved->resolution_reason);
        $this->assertSame(MonitoringStatus::NO_CURRENT_CONCERN, $this->monitoring()->classification());

        $this->grade($this->ana, 'General Mathematics', 74);  // recurrence: a new row, the old one kept
        $this->assertSame(2, AcademicConcern::where('rule_key', OfficialFailingSubjectRule::KEY)->count());
        $this->assertCount(1, $this->subjectConcerns());
    }

    public function test_missing_requirement_stays_independent_and_can_coexist(): void
    {
        $this->grade($this->ana, 'General Mathematics', 72);
        $this->missingOn('General Science', $this->ana);

        $status = $this->monitoring();
        $this->assertSame('For Review — 2 Open Concerns', $status->headline());
        $this->assertSame(['Subject Performance Concern' => 1, 'Missing Requirement' => 1], $status->reasons);

        // Resolving the subject concern leaves the Missing Requirement open, and vice versa.
        $this->grade($this->ana, 'General Mathematics', 85);
        $this->assertSame(1, AcademicConcern::open()->forRule(MissingRequirementRule::KEY)->count());
        $this->assertSame('For Review — Missing Requirement', $this->monitoring()->headline());
    }

    public function test_review_and_intervention_never_resolve_a_subject_concern(): void
    {
        $this->grade($this->ana, 'General Mathematics', 72);
        $concern = $this->subjectConcerns()->sole();

        $this->actingAs($this->principal)->from(route('principal.academic-monitoring'))
            ->post(route('principal.academic-monitoring.review', $concern))->assertSessionHas('success');
        $this->actingAs($this->principal)->from(route('principal.academic-monitoring'))
            ->post(route('principal.interventions.store'), [
                'academic_concern_id' => $concern->id, 'student_id' => $this->ana->id,
                'subject_id' => $this->subjects['General Mathematics']->id, 'grading_period' => 1, 'recommended_type' => 'remediation',
            ])->assertSessionHas('success');

        $this->assertSame($concern->id, Intervention::sole()->academic_concern_id);
        $this->assertTrue($concern->fresh()->isOpen());
        $this->assertTrue($this->monitoring()->isForReview());
    }

    // --- counts, other pages, legacy ---------------------------------------------

    public function test_dashboard_counts_distinct_learners_and_open_concerns(): void
    {
        $this->professorScenario($this->ana);
        $this->grade($this->ana, 'General Science', 73);
        $this->missingOn('Effective Communication', $this->ana);
        $this->grade($this->ben, 'General Mathematics', 90);

        $summary = $this->classifier->summary('2026-2027');
        $this->assertSame(1, $summary['learners_for_review']);
        $this->assertSame(3, $summary['open_concerns']);
        $this->assertSame([MissingRequirementRule::KEY => 1, OfficialFailingSubjectRule::KEY => 1], collect($summary['learners_by_rule'])->sortKeys()->all());

        $page = $this->actingAs($this->principal)->get(route('principal.dashboard'))->assertOk();
        $this->assertSame($summary, $page->viewData('academicMonitoring'));
        $page->assertSee('Learners — Subject Performance')->assertDontSee('Attendance');
    }

    public function test_subject_analysis_counts_each_subjects_own_concerns_and_official_results(): void
    {
        $this->professorScenario($this->ana);
        $this->grade($this->ben, 'General Mathematics', 90);
        $this->missingOn('General Science', $this->ben);

        $rows = collect(app(SubjectAnalysisService::class)->getSubjectSummaries('2026-2027', null, 1))->keyBy(fn($r) => $r['subject']->name);
        $this->assertSame([1, 0], [$rows['General Mathematics']['open_subject_performance'], $rows['General Mathematics']['open_missing']]);
        $this->assertSame([1, 2], [$rows['General Mathematics']['failing_count'], $rows['General Mathematics']['graded_count']]);
        $this->assertSame([0, 1], [$rows['General Science']['open_subject_performance'], $rows['General Science']['open_missing']]);
        $this->assertSame(0, $rows['Effective Communication']['open_concern_count'], 'a high subject is not charged with another subject\'s concern');

        $this->actingAs($this->principal)->get(route('principal.subject-analysis', ['term' => 1]))->assertOk()
            ->assertSee('Subject Performance 1 · Missing 0')->assertDontSee('Attendance');
    }

    public function test_risk_results_never_change_monitoring(): void
    {
        RiskResult::create([
            'student_id' => $this->ana->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'average_grade' => 55, 'risk_level' => 'high', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $this->assertSame(MonitoringStatus::NO_CURRENT_CONCERN, $this->monitoring()->classification());
        $this->assertSame(0, $this->classifier->summary('2026-2027')['open_concerns']);
    }

    public function test_the_adviser_sees_the_subject_concern_beside_academic_performance(): void
    {
        $this->professorScenario($this->ana);
        $item = Assessment::factory()->create([
            'subject_id' => $this->subjects['Effective Communication']->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1', 'component' => 'written_work',
            'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);
        (new AssessmentStatusService())->recordScore($item, $this->ana->id, 19);

        $this->actingAs($this->adviser)->get(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->subjects['Effective Communication']->id]))
            ->assertOk()->assertSee('Academic Performance')->assertSee('Subject Performance Concern')
            ->assertSee('General Mathematics — official grade 72.00 (Failed)');
        $this->actingAs($this->adviser)->get(route('adviser.dashboard'))->assertOk()
            ->assertSee('1 For Review', false)->assertDontSee('Attendance');
    }

    public function test_one_concern_query_per_page_however_many_learners(): void
    {
        $this->grade($this->ana, 'General Mathematics', 72);
        $count = function (): int {
            $n = 0;
            DB::listen(function ($q) use (&$n) {
                if (str_contains($q->sql, 'academic_concerns')) {
                    $n++;
                }
            });
            $this->actingAs($this->adviser)->get(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->subjects['General Mathematics']->id]))->assertOk();

            return $n;
        };
        $few = $count();
        foreach (range(3, 12) as $i) {
            Student::factory()->create(['section_id' => $this->section->id, 'lrn' => '1100000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        $this->assertSame($few, $count(), 'the concern query count does not grow with the roster');
        $this->assertSame(1, $few);
    }

    public function test_monitoring_pages_stay_role_restricted(): void
    {
        $this->actingAs($this->adviser)->get(route('principal.students.show', ['student' => $this->ana->id]))->assertForbidden();
        $this->actingAs($this->adviser)->get(route('principal.academic-monitoring'))->assertForbidden();
        $this->actingAs($this->principal)->get(route('adviser.grades'))->assertForbidden();
        $this->actingAs($this->principal)->get(route('adviser.verify-grades'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('principal.dashboard'))->assertForbidden();
    }
}
