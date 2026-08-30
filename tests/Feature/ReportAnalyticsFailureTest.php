<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adviser\ReportController::submit() must not silently swallow a Python
 * classifier failure — the submission still records (grades are real and
 * saved regardless of ML availability), but the adviser must be told risk
 * levels didn't generate, instead of getting an identical "success" message.
 */
class ReportAnalyticsFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_succeeds_with_a_warning_when_the_classifier_binary_is_missing(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        \App\Models\Grade::create([
            'student_id'     => $student->id,
            'subject_id'     => $subject->id,
            'section_id'     => $section->id,
            'encoded_by'     => $adviser->id,
            'grading_period' => 1,
            'grade'          => 85,
            'school_year'    => $section->school_year,
        ]);

        AcademicTerm::ensureExistFor($section->school_year);

        config(['services.python_path' => 'this-python-binary-does-not-exist-xyz']);

        $response = $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);

        $response->assertSessionHas('warning');
        $response->assertSessionMissing('success');
        $this->assertDatabaseHas('report_submissions', [
            'section_id'     => $section->id,
            'grading_period' => 1,
            'status'         => 'submitted',
        ]);
        $this->assertDatabaseMissing('risk_results', ['student_id' => $student->id]);
    }
}
