<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2 of "close the intervention loop": the adviser dashboard's
 * "My Students" panel used to show "No data — Submit report to generate"
 * on every row until a term report was submitted. It now draws from
 * InTermStatusService (assessment evidence), which has something to say
 * from the first upload onward.
 */
class AdviserDashboardInTermStatusTest extends TestCase
{
    use RefreshDatabase;

    private function score(Section $section, Subject $subject, Student $student, string $component, float $earned, float $max = 100): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_dashboard_shows_real_in_term_status_with_zero_submitted_reports(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $strong = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Strong']);
        $weak = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Weak']);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subject, $strong, $component, 95);
        }
        $this->score($section, $subject, $weak, 'written_work', 50);
        $this->score($section, $subject, $weak, 'performance_task', 50);

        $this->assertSame(0, RiskResult::count());

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertDontSee('No data');
        $response->assertDontSee('Submit report to generate');
        $response->assertSee('On Track');
        $response->assertSee('At Risk');
        $response->assertSee('Not yet available'); // Risk Level column, no report submitted yet
    }

    public function test_dashboard_sorts_at_risk_first(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $strong = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'AAA_Strong']);
        $weak = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ZZZ_Weak']);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $subject, $strong, $component, 95);
        }
        $this->score($section, $subject, $weak, 'written_work', 50);
        $this->score($section, $subject, $weak, 'performance_task', 50);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');
        $content = $response->getContent();

        $posWeak = strpos($content, 'ZZZ_Weak');
        $posStrong = strpos($content, 'AAA_Strong');

        $this->assertNotFalse($posWeak);
        $this->assertNotFalse($posStrong);
        $this->assertLessThan($posStrong, $posWeak, 'At Risk student must render before an On Track student despite alphabetical order.');
    }

    public function test_a_subject_with_no_evidence_yet_does_not_count_against_the_student(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11, 'school_year' => '2026-2027']);
        $goodSubject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        Subject::factory()->create(['grade_level' => 11, 'type' => 'core']); // no evidence uploaded for this one at all

        $student = Student::factory()->create(['section_id' => $section->id]);
        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->score($section, $goodSubject, $student, $component, 95);
        }

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('On Track');
    }
}
