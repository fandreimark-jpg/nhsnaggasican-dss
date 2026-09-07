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
 * "Clarity, progress, and visual design pass" TASK 5 — the dashboard's
 * My Students panel used to render the WHOLE section roster (e.g. 40
 * rows) and push everything else on the dashboard off-screen. It now
 * shows only the 10 highest-priority learners (buildInTermRows() already
 * sorts At Risk -> Needs Attention -> On Track -> no evidence yet), and
 * the full My Students page is paginated at 25/page.
 */
class AdviserDashboardPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_panel_shows_top_ten_of_full_count_and_links_to_view_all(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        $atRiskStudents = Student::factory()->count(3)->create(['section_id' => $section->id]);
        foreach ($atRiskStudents as $student) {
            foreach (['written_work' => 40, 'performance_task' => 40, 'examination' => 40] as $component => $score) {
                $assessment = Assessment::factory()->create([
                    'subject_id' => $subject->id, 'section_id' => $section->id,
                    'grading_period' => 1, 'school_year' => $section->school_year,
                    'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
                ]);
                AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $score]);
            }
        }
        Student::factory()->count(9)->create(['section_id' => $section->id]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('Showing the 10 highest-priority of 12 students', false);
        $response->assertSee(route('adviser.students'), false);
        // TASK 5c — counts in the panel heading, so the Adviser gets the
        // whole picture without scrolling. The 9 no-evidence students
        // count toward none of the three buckets (null status, not "On
        // Track" — see buildInTermRows()'s own "missing means unknown"
        // rule), so only the 3 At Risk students are reflected here.
        $response->assertSee('3 At Risk', false);
        $response->assertSee('0 Needs Attention', false);
        $response->assertSee('0 On Track', false);
    }

    public function test_full_my_students_page_paginates_at_twenty_five_per_page(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        Student::factory()->count(30)->create(['section_id' => $section->id]);

        $page1 = $this->actingAs($adviser)->get('/adviser/students');
        $page1->assertOk();
        $page1->assertSee('Showing 1', false);
        $page1->assertSee('–25 of', false);

        $page2 = $this->actingAs($adviser)->get('/adviser/students?page=2');
        $page2->assertOk();
        $page2->assertSee('–30', false);
    }
}
