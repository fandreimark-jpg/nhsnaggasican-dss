<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The FAILING layer" TASK 4 — the Principal dashboard's Failing tile:
 * one aggregate query, counting distinct STUDENTS (not (student, subject)
 * pairs) with a verified, non-provisional official grade at or below the
 * threshold, scoped to the active school year and currently open term.
 */
class FailingDashboardTileTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_on_an_empty_database(): void
    {
        $summary = (new DashboardAnalyticsService())->getFailingSummary();
        $this->assertSame(0, $summary['failingCount']);
    }

    public function test_a_student_failing_two_subjects_is_counted_once(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 12]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subjectA = Subject::factory()->create(['grade_level' => 12]);
        $subjectB = Subject::factory()->create(['grade_level' => 12]);
        $adviser = User::factory()->create();
        AcademicTerm::ensureExistFor('2026-2027');

        foreach ([$subjectA, $subjectB] as $subject) {
            Grade::factory()->create([
                'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
                'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
                'grade' => 65.0, 'is_verified' => true, 'is_provisional' => false,
            ]);
        }

        $summary = (new DashboardAnalyticsService())->getFailingSummary();
        $this->assertSame(1, $summary['failingCount']);
    }

    public function test_provisional_and_unverified_grades_are_excluded(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12]);
        $adviser = User::factory()->create();
        AcademicTerm::ensureExistFor('2026-2027');

        $provisional = Student::factory()->create(['section_id' => $section->id]);
        Grade::factory()->create([
            'student_id' => $provisional->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 65.0, 'is_verified' => true, 'is_provisional' => true,
        ]);

        $unverified = Student::factory()->create(['section_id' => $section->id]);
        Grade::factory()->create([
            'student_id' => $unverified->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 65.0, 'is_verified' => false, 'is_provisional' => false,
        ]);

        $summary = (new DashboardAnalyticsService())->getFailingSummary();
        $this->assertSame(0, $summary['failingCount']);
    }

    public function test_dashboard_page_shows_the_failing_tile_labelled_distinctly_from_risk(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 12]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => 12]);
        $adviser = User::factory()->create();
        AcademicTerm::ensureExistFor('2026-2027');

        Grade::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 60.0, 'is_verified' => true, 'is_provisional' => false,
        ]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        // "Workflow completion pass" TASK 2 relabelled the tile and moved
        // it into "3. Trend and outcomes" — see FailingIsNotAnInTermBucketTest
        // for the placement assertion.
        $response->assertSee('Failed this term', false);
        $response->assertSee('Official grade 74 and below', false);
    }
}
