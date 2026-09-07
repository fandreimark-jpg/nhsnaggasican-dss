<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 4 of "dashboard structure and upload safeguards" — display-only:
 * neither AcademicTerm::completionStatus() nor the open-term guard change
 * here. These tests confirm the NEW sectionCapacityBreakdown() numbers
 * always agree with completionStatus()'s own arithmetic, and that both
 * the Admin Sections and Admin Academic Terms pages surface it.
 */
class SectionCapacityDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** A section carrying many subjects, so the gap is real and visible — matches the task's own scenario. */
    private function makeHeavySection(): array
    {
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subjects = Subject::factory()->count(5)->create(['grade_level' => 11, 'type' => 'core']);
        $students = Student::factory()->count(3)->create(['section_id' => $section->id]);

        return [$section, $subjects, $students];
    }

    public function test_section_capacity_breakdown_matches_completion_status_arithmetic(): void
    {
        [$section, $subjects, $students] = $this->makeHeavySection();

        // Encode grades for only 2 of the 5 subjects, for every student —
        // this section can NEVER reach completion under the current subject list.
        foreach ($students as $student) {
            foreach ($subjects->take(2) as $subject) {
                Grade::create([
                    'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
                    'encoded_by' => User::factory()->create()->id, 'grading_period' => 1, 'grade' => 85,
                    'school_year' => '2026-2027',
                ]);
            }
        }

        $status = AcademicTerm::completionStatus('2026-2027', 1);
        $breakdown = AcademicTerm::sectionCapacityBreakdown('2026-2027', 1);

        $this->assertFalse($status['complete']);
        $row = collect($breakdown)->firstWhere(fn($r) => $r['section']->id === $section->id);

        $this->assertNotNull($row);
        $this->assertSame(5, $row['subject_count']);
        $this->assertSame(3, $row['student_count']);
        $this->assertSame(15, $row['expected']); // 5 subjects x 3 students
        $this->assertSame(6, $row['encoded']); // 2 subjects x 3 students

        // Must reconcile exactly with what completionStatus() reports for this section.
        $incomplete = collect($status['incomplete_sections'])->first();
        $this->assertSame($row['expected'], $incomplete['expected']);
        $this->assertSame($row['encoded'], $incomplete['encoded']);
    }

    public function test_admin_sections_page_shows_subject_count_and_expected_grades(): void
    {
        $admin = User::factory()->admin()->create();
        [$section, $subjects, $students] = $this->makeHeavySection();

        $response = $this->actingAs($admin)->get('/admin/sections');

        $response->assertOk();
        $response->assertSee('Subjects');
        $response->assertSee('Expected/Term');
        // 5 subjects x 3 students = 15 expected.
        $response->assertSee('15');
    }

    public function test_admin_academic_terms_page_shows_the_same_figures_alongside_encoded_count(): void
    {
        $admin = User::factory()->admin()->create();
        [$section, $subjects, $students] = $this->makeHeavySection();

        foreach ($students as $student) {
            foreach ($subjects->take(2) as $subject) {
                Grade::create([
                    'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
                    'encoded_by' => User::factory()->create()->id, 'grading_period' => 1, 'grade' => 85,
                    'school_year' => '2026-2027',
                ]);
            }
        }

        $response = $this->actingAs($admin)->get('/admin/academic-terms');

        $response->assertOk();
        $response->assertSee('Section breakdown');
        $response->assertSee($section->name, false);
        // Expected 15, encoded 6 — both visible on the page.
        $response->assertSee('15');
        $response->assertSee('6');
    }

    /**
     * The task's explicit verify step: the figures shown here must match
     * what the open-term guard actually reports when it refuses to open
     * the next term.
     */
    public function test_displayed_figures_match_what_the_open_term_guard_reports_when_refused(): void
    {
        $admin = User::factory()->admin()->create();
        [$section, $subjects, $students] = $this->makeHeavySection();
        AcademicTerm::ensureExistFor('2026-2027');

        // Nothing encoded at all for Term 1 — guaranteed refusal.
        $openAttempt = $this->actingAs($admin)->post('/admin/academic-terms/2/open');
        $openAttempt->assertSessionHas('error');
        $guardMessage = session('error');

        // The guard's own message names "encoded/expected" — pull the expected figure from it.
        $this->assertStringContainsString('0/15', $guardMessage);

        $termsPage = $this->actingAs($admin)->get('/admin/academic-terms');
        $termsPage->assertOk();
        $termsPage->assertSee('15');
    }
}
