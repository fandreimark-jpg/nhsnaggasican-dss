<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Adviser Assessments page's "Student Performance" section — renders
 * PerformanceAnalysisService's output per student for the selected
 * subject/term, but only once there's actually something to analyze.
 */
class AssessmentPerformancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_section_is_hidden_when_no_assessment_items_exist_yet(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        Student::factory()->create(['section_id' => $section->id]);

        $response = $this->actingAs($adviser)->get('/adviser/assessments?period=1&subject_id=' . $subject->id);

        $response->assertOk();
        $response->assertViewHas('performance', fn($performance) => $performance->isEmpty());
        $response->assertDontSee('Student Performance —');
    }

    public function test_performance_section_shows_weakest_component_once_items_and_scores_exist(): void
    {
        $adviser = User::factory()->create();
        // grade_level pinned to 12 (not the factory's random 11/12) — see
        // TASK 1 of "DO 015 grading weights": Grade 11 in SY 2026-2027
        // now uses different (subject-group-dependent) weights, so this
        // test's hardcoded do8_2015 25/50/25 value must stay deterministic.
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Reyes', 'first_name' => 'Ana']);

        foreach ([['written_work', 84], ['performance_task', 60], ['examination', 70]] as [$component, $earned]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $response = $this->actingAs($adviser)->get('/adviser/assessments?period=1&subject_id=' . $subject->id);

        $response->assertOk();
        $response->assertSee('Student Performance —');
        $response->assertSee('Reyes, Ana');
        $response->assertSee('68.5'); // computed grade
        $response->assertViewHas('performance', function ($performance) {
            $row = $performance->first();
            return $row['weakest_component'] === 'performance_task'
                && $row['components']['performance_task']['status'] === 'Needs Attention';
        });
    }
}
