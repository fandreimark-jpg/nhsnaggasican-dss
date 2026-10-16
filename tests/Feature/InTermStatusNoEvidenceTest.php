<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Services\AssessmentStatusService;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "No evidence is not On Track." A learner with no scored item in a
 * subject/term reads No Evidence Yet — never On Track, never a concern —
 * on every per-learner status, filter and roll-up. A recorded 0 is
 * evidence and is evaluated normally.
 */
class InTermStatusNoEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $adviser;
    private User $principal;
    private Section $section;
    private Subject $subject;
    private Assessment $quiz;
    /** @var array<string, Student> */
    private array $learners = [];
    private InTermStatusService $inTerm;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->principal = User::factory()->principal()->create();
        $track = Track::factory()->create();
        $spec = Specialization::factory()->create(['track_id' => $track->id, 'code' => 'HUMSS', 'curriculum' => 'k12_2013']);
        $this->section = Section::factory()->create([
            'name' => 'Agila', 'grade_level' => 12, 'school_year' => '2026-2027',
            'track_id' => $track->id, 'specialization_id' => $spec->id, 'adviser_id' => $this->adviser->id,
        ]);
        $this->subject = Subject::factory()->create([
            'name' => 'Community Engagement Solidarity and Citizenship', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic',
        ]);
        foreach (['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'] as $i => $last) {
            $this->learners[$last] = Student::factory()->create([
                'section_id' => $this->section->id, 'last_name' => $last, 'first_name' => 'TEST', 'lrn' => '12000000009' . $i,
            ]);
        }
        $this->quiz = Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1',
            'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);

        $statuses = new AssessmentStatusService();
        $statuses->recordScore($this->quiz, $this->learners['ALPHA']->id, 16);   // Completed, 80%
        // BRAVO: nothing recorded — Not Yet Given.
        $statuses->markMissing($this->quiz, $this->learners['CHARLIE']->id, $this->adviser->id); // Missing
        $statuses->recordScore($this->quiz, $this->learners['DELTA']->id, 0);    // Completed, a real 0

        $this->inTerm = new InTermStatusService();
    }

    private function learnerStatus(string $last): array
    {
        return $this->inTerm->statusFor($this->learners[$last], $this->subject, $this->section, 1, '2026-2027');
    }

    public function test_no_scored_item_is_no_evidence_yet_never_on_track(): void
    {
        foreach (['BRAVO' => 'Not Yet Given', 'CHARLIE' => 'Missing'] as $last => $why) {
            $s = $this->learnerStatus($last);
            $this->assertSame(InTermStatusService::NO_EVIDENCE, $s['status'], "$why is not evidence of performance");
            $this->assertSame(0, $s['item_count']);
            $this->assertNull($s['computed_grade']);
            $this->assertSame(0, $s['components_below_target']);
        }

        // A subject with no assessment item at all for the learner is the same.
        $other = Subject::factory()->create(['name' => 'Philippine Politics and Governance', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        $this->assertSame(InTermStatusService::NO_EVIDENCE,
            $this->inTerm->statusFor($this->learners['ALPHA'], $other, $this->section, 1, '2026-2027')['status']);
    }

    public function test_a_recorded_score_including_zero_is_evidence_and_evaluated_normally(): void
    {
        $alpha = $this->learnerStatus('ALPHA');
        $this->assertSame('On Track', $alpha['status']);
        $this->assertSame(1, $alpha['item_count']);

        $delta = $this->learnerStatus('DELTA');
        $this->assertSame('Needs Attention', $delta['status'], 'a 0 is a real score (Completed), below the 75 target');
        $this->assertSame(1, $delta['item_count']);

        // The evidence-based classification itself is unchanged.
        $this->assertSame('On Track', InTermStatusService::classify(0));
        $this->assertSame('Needs Attention', InTermStatusService::classify(1));
        $this->assertSame('At Risk', InTermStatusService::classify(2));
        $this->assertNotContains(InTermStatusService::NO_EVIDENCE, InTermStatusService::STATUSES);
    }

    public function test_the_section_roll_up_still_excludes_no_evidence_but_labels_it_in_the_drill_down(): void
    {
        $rows = $this->inTerm->overallStatusForSection(
            $this->section, collect($this->learners)->values(), collect([$this->subject]), 1
        )->keyBy(fn($r) => $r['student']->last_name);

        $this->assertNull($rows['BRAVO']['in_term_status'], 'no evidence anywhere: no overall claim either way');
        $this->assertSame(0, $rows['BRAVO']['subjects_evaluated']);
        $this->assertSame(InTermStatusService::NO_EVIDENCE, $rows['BRAVO']['by_subject'][0]['status']['status']);
        $this->assertSame('On Track', $rows['ALPHA']['in_term_status']['status']);
        $this->assertSame('Needs Attention', $rows['DELTA']['in_term_status']['status']);
    }

    public function test_no_evidence_sorts_after_flagged_learners_and_is_never_ready_for_review(): void
    {
        $this->assertGreaterThan(InTermStatusService::SEVERITY['On Track'], InTermStatusService::SEVERITY[InTermStatusService::NO_EVIDENCE]);
        $this->assertSame(InTermStatusService::PRIORITY_REST, InTermStatusService::priority(null, InTermStatusService::NO_EVIDENCE));

        $iv = Intervention::factory()->decided()->create([
            'student_id' => $this->learners['BRAVO']->id, 'subject_id' => $this->subject->id,
            'section_id' => $this->section->id, 'school_year' => '2026-2027', 'grading_period' => 1,
            'status' => 'approved', 'acknowledged_at' => now(), 'delivered_at' => now(), 'delivered_by' => $this->adviser->id,
        ]);
        $this->assertFalse($this->inTerm->isReadyForReview($iv->fresh()));
    }

    public function test_adviser_assessments_page_shows_no_evidence_yet_and_filters_it_apart_from_on_track(): void
    {
        $page = fn(array $q = []) => $this->actingAs($this->adviser)
            ->get(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->subject->id] + $q))->assertOk();

        $all = $page()->viewData('performance')->keyBy(fn($r) => $r['student']->last_name);
        $this->assertSame(InTermStatusService::NO_EVIDENCE, $all['BRAVO']['in_term_status']['status']);
        $this->assertSame(InTermStatusService::NO_EVIDENCE, $all['CHARLIE']['in_term_status']['status']);
        $page()->assertSee('No Evidence Yet')->assertSee('0 items scored so far')
            ->assertSee('<option value="No Evidence Yet"', false);

        $names = fn($response) => $response->viewData('performance')->map(fn($r) => $r['student']->last_name)->sort()->values()->all();
        $this->assertSame(['ALPHA'], $names($page(['status_filter' => 'On Track'])));
        $this->assertSame(['BRAVO', 'CHARLIE'], $names($page(['status_filter' => 'No Evidence Yet'])));
        $this->assertSame(['DELTA'], $names($page(['status_filter' => 'Needs Attention'])));
    }

    public function test_principal_students_page_shows_no_evidence_yet_and_filters_it_apart_from_on_track(): void
    {
        $page = fn(array $q = []) => $this->actingAs($this->principal)
            ->get(route('principal.students', ['period' => 1, 'subject_id' => $this->subject->id] + $q))->assertOk();

        $page()->assertSee('No Evidence Yet')->assertSee('<option value="No Evidence Yet"', false);

        $names = fn($response) => collect($response->viewData('students')->items())
            ->map(fn($r) => $r['student']->last_name)->sort()->values()->all();
        $this->assertSame(['ALPHA'], $names($page(['status_filter' => 'On Track'])));
        $this->assertSame(['BRAVO', 'CHARLIE'], $names($page(['status_filter' => 'No Evidence Yet'])));
    }
}
