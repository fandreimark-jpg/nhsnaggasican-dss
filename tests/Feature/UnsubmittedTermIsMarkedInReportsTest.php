<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\ReportSubmission;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Decision flow, report scoping, and dashboard pass" TASK 2b/2c/2d —
 * an unsubmitted term's grade values stay visible (the adviser verified
 * them — hiding them would be worse than showing them honestly) but are
 * marked as not yet part of a submitted report, and the Risk Level
 * badge states which term it's actually as-of when a later, populated
 * term column could otherwise make it look current.
 */
class UnsubmittedTermIsMarkedInReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsubmitted_term_cell_carries_the_not_submitted_tooltip(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        Grade::factory()->create([
            'student_id' => $student->id, 'section_id' => $section->id,
            'grading_period' => 3, 'grade' => 86.00, 'school_year' => '2026-2027',
        ]);
        // Term 3 deliberately NOT submitted.

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertSee('Verified by the adviser but not yet part of a submitted term report. Not included in the overall average.');
    }

    public function test_risk_level_shows_an_as_of_qualifier_when_a_later_term_is_populated(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        Grade::factory()->create([
            'student_id' => $student->id, 'section_id' => $section->id,
            'grading_period' => 1, 'grade' => 79.00, 'school_year' => '2026-2027',
        ]);
        Grade::factory()->create([
            'student_id' => $student->id, 'section_id' => $section->id,
            'grading_period' => 3, 'grade' => 86.00, 'school_year' => '2026-2027',
        ]);
        ReportSubmission::create([
            'section_id' => $section->id, 'submitted_by' => $section->adviser_id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 79,
            'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertSee('as of Term 1');
    }

    public function test_no_as_of_qualifier_when_no_later_term_is_populated(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        Grade::factory()->create([
            'student_id' => $student->id, 'section_id' => $section->id,
            'grading_period' => 1, 'grade' => 79.00, 'school_year' => '2026-2027',
        ]);
        ReportSubmission::create([
            'section_id' => $section->id, 'submitted_by' => $section->adviser_id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 79,
            'risk_level' => 'moderate', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertDontSee('as of Term 1');
    }
}
