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
 * Superseded 2026-10-07 (report submission boundary): an unsubmitted
 * term shows NO values on the recipients' Reports page — only "Not
 * submitted"; live grades belong to Academic Monitoring / Students. The
 * earlier rule (values shown greyed, "verified but not yet part of a
 * submitted report") exposed unsubmitted work as report content.
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

        // 2026-10-07: an unsubmitted term exposes NO figures to recipients — it is
        // marked "Not submitted"; the value appears only after explicit submission.
        $response->assertOk();
        $response->assertSee('data-not-submitted', false)->assertDontSee('86.00');
        $this->submittedReport($section, 3);
        $this->actingAs($admin)->get('/admin/reports')->assertOk()->assertSee('86.00');
    }
}
