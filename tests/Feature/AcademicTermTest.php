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
 * Term N+1 must not open until Term N is 100% encoded across every
 * section for the school year — enforced server-side in
 * AcademicTermController::open(), not just suggested in the UI.
 */
class AcademicTermTest extends TestCase
{
    use RefreshDatabase;

    private function fullyEncodeTerm(Section $section, Student $student, Subject $subject, int $term): void
    {
        Grade::create([
            'student_id'     => $student->id,
            'subject_id'     => $subject->id,
            'section_id'     => $section->id,
            'encoded_by'     => $section->adviser_id,
            'grading_period' => $term,
            'grade'          => 85,
            'school_year'    => $section->school_year,
        ]);
    }

    public function test_term_1_can_always_open_with_no_prior_term(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);

        $this->actingAs($admin)
            ->post('/admin/academic-terms/1/open')
            ->assertRedirect();

        $this->assertTrue(AcademicTerm::isOpen('2026-2027', 1));
    }

    public function test_term_2_cannot_open_while_term_1_is_incomplete(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        Student::factory()->create(['section_id' => $section->id]);
        Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        // No grades encoded at all for Term 1 — incomplete.

        $response = $this->actingAs($admin)->post('/admin/academic-terms/2/open');

        $response->assertRedirect(route('admin.academic-terms'));
        $response->assertSessionHas('error');
        $this->assertFalse(AcademicTerm::isOpen('2026-2027', 2));
    }

    public function test_term_2_can_open_once_term_1_is_fully_encoded(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        $this->fullyEncodeTerm($section, $student, $subject, 1);

        $response = $this->actingAs($admin)->post('/admin/academic-terms/2/open');

        $response->assertRedirect(route('admin.academic-terms'));
        $response->assertSessionHas('success');
        $this->assertTrue(AcademicTerm::isOpen('2026-2027', 2));
        // Opening Term 2 must close Term 1 — only one term open at a time.
        $this->assertFalse(AcademicTerm::isOpen('2026-2027', 1));
    }

    public function test_closing_a_term_blocks_grade_encoding_for_it(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor('2026-2027');
        $this->actingAs($admin)->post('/admin/academic-terms/1/close');

        $response = $this->actingAs($adviser)->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [
                ['student_id' => $student->id, 'subject_id' => $subject->id, 'grade' => 90],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('grades', ['student_id' => $student->id]);
    }

    public function test_adviser_can_encode_grades_while_term_is_open(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor('2026-2027'); // Term 1 opens by default

        $response = $this->actingAs($adviser)->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [
                ['student_id' => $student->id, 'subject_id' => $subject->id, 'grade' => 90],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'grade'      => 90,
        ]);
    }

    public function test_non_admin_cannot_open_or_close_terms(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)
            ->post('/admin/academic-terms/1/open')
            ->assertForbidden();
    }

    public function test_report_submission_is_blocked_when_the_term_is_closed(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        $this->fullyEncodeTerm($section, $student, $subject, 1);
        $this->actingAs($admin)->post('/admin/academic-terms/1/close');

        $response = $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('report_submissions', ['section_id' => $section->id]);
    }

    public function test_closing_an_already_closed_term_returns_an_error_instead_of_reclosing(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        AcademicTerm::ensureExistFor('2026-2027');

        $this->actingAs($admin)->post('/admin/academic-terms/1/close');
        $firstClosedAt = AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->value('closed_at');

        $response = $this->actingAs($admin)->post('/admin/academic-terms/1/close');

        $response->assertSessionHas('error');
        $secondClosedAt = AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->value('closed_at');
        $this->assertEquals($firstClosedAt, $secondClosedAt);
    }

    public function test_opening_a_later_term_does_not_overwrite_an_earlier_terms_closed_at_history(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        $this->fullyEncodeTerm($section, $student, $subject, 1);
        $this->actingAs($admin)->post('/admin/academic-terms/2/open'); // closes term 1

        $term1ClosedAtAfterTerm2 = AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->value('closed_at');
        $this->assertNotNull($term1ClosedAtAfterTerm2);

        $this->fullyEncodeTerm($section, $student, $subject, 2);
        $this->actingAs($admin)->post('/admin/academic-terms/3/open'); // closes term 2, must NOT touch term 1

        $term1ClosedAtAfterTerm3 = AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->value('closed_at');
        $this->assertEquals($term1ClosedAtAfterTerm2, $term1ClosedAtAfterTerm3);
    }
}
