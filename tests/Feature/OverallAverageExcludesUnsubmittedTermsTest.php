<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\ReportSubmission;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Decision flow, report scoping, and dashboard pass" TASK 2a — Overall
 * Avg on the Reports page must cover submitted terms only. Term 3 grades
 * are real (the adviser verified them) but Term 3 has not been
 * submitted for this section, so it must not silently pull the average
 * toward a provisional number. Terms 79.00 / 85.00 / 86.00 with only
 * Terms 1-2 submitted must average to 82.00 (two terms), never 83.33
 * (all three).
 */
class OverallAverageExcludesUnsubmittedTermsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSectionWithGrades(): array
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ThreeTermCase']);

        foreach ([1 => 79.00, 2 => 85.00, 3 => 86.00] as $term => $grade) {
            Grade::factory()->create([
                'student_id' => $student->id, 'section_id' => $section->id,
                'grading_period' => $term, 'grade' => $grade, 'school_year' => '2026-2027',
            ]);
        }

        // Only Terms 1 and 2 are submitted — Term 3 is verified but not final.
        foreach ([1, 2] as $term) {
            ReportSubmission::create([
                'section_id' => $section->id, 'submitted_by' => $section->adviser_id, 'grading_period' => $term,
                'school_year' => '2026-2027', 'status' => 'submitted', 'submitted_at' => now(),
            ]);
        }

        return [$section, $student];
    }

    public function test_overall_average_covers_submitted_terms_only(): void
    {
        [$section, $student] = $this->makeSectionWithGrades();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        // (79.00 + 85.00) / 2 = 82.00 — never 83.33 (the mean of all three).
        $response->assertSee('82.00');
        $response->assertDontSee('83.33');
    }

    public function test_overall_average_label_states_how_many_terms_it_covers(): void
    {
        [$section, $student] = $this->makeSectionWithGrades();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertSee('2 of 3 terms submitted');
    }

    public function test_when_every_term_is_submitted_the_average_covers_all_three(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach ([1 => 79.00, 2 => 85.00, 3 => 86.00] as $term => $grade) {
            Grade::factory()->create([
                'student_id' => $student->id, 'section_id' => $section->id,
                'grading_period' => $term, 'grade' => $grade, 'school_year' => '2026-2027',
            ]);
            ReportSubmission::create([
                'section_id' => $section->id, 'submitted_by' => $section->adviser_id, 'grading_period' => $term,
                'school_year' => '2026-2027', 'status' => 'submitted', 'submitted_at' => now(),
            ]);
        }

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertSee('83.33');
        $response->assertSee('3 of 3 terms submitted');
    }
}
