<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Decision flow, report scoping, and dashboard pass" TASK 5b — the "What
 * Needs Your Attention Now" block replaces the static In-Term Status /
 * Risk Level definitions at the top of the Adviser dashboard with a
 * checklist of only the things that actually require action.
 */
class AdviserAttentionNeededTest extends TestCase
{
    use RefreshDatabase;

    private function scoreComponent(Subject $subject, Section $section, Student $student, string $component, int $term = 1): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => $term, 'school_year' => '2026-2027', 'component' => $component,
            'name' => $component . ' item', 'max_score' => 20,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 18]);
    }

    public function test_all_clear_message_shown_when_nothing_needs_attention(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 12]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        AcademicTerm::create(['school_year' => '2026-2027', 'term' => 1, 'is_open' => true]);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->scoreComponent($subject, $section, $student, $component);
        }

        Grade::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027', 'is_verified' => true,
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('Nothing needs your attention right now.');
    }

    public function test_acknowledged_but_not_delivered_intervention_is_counted(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        Intervention::factory()->decided()->create([
            'student_id' => $student->id,
            'acknowledged_at' => now(),
            'delivered_at' => null,
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('acknowledged but not yet delivered');
    }

    public function test_subject_with_incomplete_evidence_is_counted(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 12]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        AcademicTerm::create(['school_year' => '2026-2027', 'term' => 1, 'is_open' => true]);

        // Only Written Work scored — Performance Task and Examination missing.
        $this->scoreComponent($subject, $section, $student, 'written_work');

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('with incomplete assessment evidence in Term 1');
    }

    public function test_grade_computed_but_not_verified_is_counted(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 12]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        AcademicTerm::create(['school_year' => '2026-2027', 'term' => 1, 'is_open' => true]);

        // Complete evidence, but never verified into an official grade.
        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $this->scoreComponent($subject, $section, $student, $component);
        }

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('computed but not yet verified');
    }

    public function test_grades_encoded_card_states_the_denominator_and_period(): void
    {
        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('across 3 terms');
    }
}
