<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 1 of "status clarity and progress consistency" — the exact
 * scenario the prompt names: a student showing Computed 66.98,
 * Transmuted 79.00, and In-Term Status At Risk looks like a
 * contradiction unless the page says which number drove the status and
 * flags the passing-on-paper case explicitly. Ground rule: this task
 * never changes WHICH number the status is computed from, nor the
 * threshold — only makes the existing choice legible.
 */
class StatusClarityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // TransmutationService reads from this table — the migration only
        // creates it, seeding is a separate step (see TransmutationServiceTest).
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    /**
     * All three components below the 75 target (so status is At Risk),
     * but the raw weighted average is low enough that DepEd's
     * transmutation table still lifts it to a passing 75+.
     */
    private function makePassingOnPaperStudent(Section $section, Subject $subject): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach ([['written_work', 70], ['performance_task', 70], ['examination', 60]] as [$component, $earned]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        return $student;
    }

    public function test_adviser_assessments_page_shows_computed_number_and_passing_on_paper_marker(): void
    {
        $adviser = User::factory()->create();
        // grade_level 12 (not 11) — TASK 2 of "terminology, transmutation,
        // and interface cleanup" wired Grade 11 in this same school year
        // to the do015_2026 scheme, which has only one seeded anchor and
        // would make this "passing on paper" fixture's 67.5 computed
        // grade come back with NO transmuted grade at all. Grade 12
        // deterministically stays on the fully-seeded do8_2015 table.
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = $this->makePassingOnPaperStudent($section, $subject);

        $grade = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');
        $this->assertTrue($grade['complete']);
        $this->assertLessThan(75, $grade['computed_grade']);
        $this->assertGreaterThanOrEqual(75, $grade['transmuted_grade'], 'Fixture must actually reproduce the passing-on-paper case.');

        $response = $this->actingAs($adviser)->get('/adviser/assessments?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('At Risk', false);
        // Visible text, not a tooltip — the exact computed number next to the status.
        $response->assertSee('computed ' . number_format($grade['computed_grade'], 2), false);
        $response->assertSee('passing on paper', false);
        $response->assertSee('transmuted ' . number_format($grade['transmuted_grade'], 2), false);
        // The persistent explanatory line stating the choice plainly.
        $response->assertSee('In-Term Status is based on the Computed grade, not the report card grade', false);
    }

    public function test_principal_students_page_shows_computed_number_and_passing_on_paper_marker(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = $this->makePassingOnPaperStudent($section, $subject);

        $grade = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('At Risk', false);
        $response->assertSee('computed ' . number_format($grade['computed_grade'], 2), false);
        $response->assertSee('passing on paper', false);
        $response->assertSee('In-Term Status is based on the Computed grade, not the report card grade', false);
    }

    /** The marker must never appear for a student who is genuinely On Track. */
    public function test_no_passing_on_paper_marker_for_an_on_track_student(): void
    {
        $adviser = User::factory()->create();
        // grade_level 12 (not 11) — TASK 2 of "terminology, transmutation,
        // and interface cleanup" wired Grade 11 in this same school year
        // to the do015_2026 scheme, which has only one seeded anchor and
        // would make this "passing on paper" fixture's 67.5 computed
        // grade come back with NO transmuted grade at all. Grade 12
        // deterministically stays on the fully-seeded do8_2015 table.
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach ([['written_work', 90], ['performance_task', 90], ['examination', 90]] as [$component, $earned]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $response = $this->actingAs($adviser)->get('/adviser/assessments?subject_id=' . $subject->id . '&period=1');

        $response->assertOk();
        $response->assertSee('On Track', false);
        // Scoped to the marker's own wording (not the static help text
        // above the table, which also mentions the phrase generically).
        $response->assertDontSee('passing on paper (transmuted', false);
    }

    /** Status thresholds themselves are unchanged by this task. */
    public function test_status_still_uses_the_same_two_component_at_risk_threshold(): void
    {
        $service = new \App\Services\InTermStatusService();
        $this->assertSame('On Track', \App\Services\InTermStatusService::classify(0));
        $this->assertSame('Needs Attention', \App\Services\InTermStatusService::classify(1));
        $this->assertSame('At Risk', \App\Services\InTermStatusService::classify(2));
        $this->assertSame('At Risk', \App\Services\InTermStatusService::classify(3));
    }
}
