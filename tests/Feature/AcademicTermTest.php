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

    public function test_term_2_cannot_be_submitted_before_term_1_is_submitted(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        // Term 1 fully encoded (so Term 2 is allowed to OPEN) but never
        // actually submitted by the adviser.
        $this->fullyEncodeTerm($section, $student, $subject, 1);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/academic-terms/2/open');
        $this->fullyEncodeTerm($section, $student, $subject, 2);

        $response = $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 2]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('report_submissions', ['section_id' => $section->id, 'grading_period' => 2]);
    }

    public function test_term_2_can_be_submitted_once_term_1_has_been_submitted(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor('2026-2027'); // Term 1 open by default
        $this->fullyEncodeTerm($section, $student, $subject, 1);
        $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/academic-terms/2/open');
        $this->fullyEncodeTerm($section, $student, $subject, 2);

        $response = $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 2]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('report_submissions', ['section_id' => $section->id, 'grading_period' => 2]);
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

    /**
     * "UI legibility pass" — completionStatus()'s 'complete' flag is
     * vacuously true when there is nothing to encode at all (empty()
     * over an empty $incomplete array), which used to read on the
     * Academic Terms page as "All sections fully encoded for this term"
     * on a fresh install with zero sections — a false claim of
     * completion, not an honest "nothing here yet." has_anything_expected
     * distinguishes the two so the page can tell them apart.
     */
    public function test_completion_status_reports_nothing_expected_when_no_section_has_students_and_subjects(): void
    {
        $status = AcademicTerm::completionStatus('2026-2027', 1);

        $this->assertFalse($status['complete']); // Empty terms cannot permit progression.
        $this->assertFalse($status['has_anything_expected']); // but this is what actually distinguishes it
    }

    public function test_completion_status_reports_something_expected_once_a_section_has_students_and_subjects(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $this->fullyEncodeTerm($section, $student, $subject, 1);

        $status = AcademicTerm::completionStatus('2026-2027', 1);

        $this->assertTrue($status['complete']);
        $this->assertTrue($status['has_anything_expected']);
    }

    public function test_academic_terms_page_does_not_claim_completion_when_there_is_nothing_to_encode(): void
    {
        $admin = User::factory()->admin()->create();
        \App\Models\AcademicTerm::ensureExistFor('2026-2027');

        $response = $this->actingAs($admin)->get('/admin/academic-terms');

        $response->assertOk();
        $response->assertSee('No section has both students and subjects assigned yet');
        $response->assertDontSee('All sections fully encoded for this term');
    }

    public function test_academic_terms_page_still_claims_completion_once_it_is_genuinely_true(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $this->fullyEncodeTerm($section, $student, $subject, 1);

        $response = $this->actingAs($admin)->get('/admin/academic-terms');

        $response->assertOk();
        $response->assertSee('All sections fully encoded for this term');
        $response->assertDontSee('No section has both students and subjects assigned yet');
    }

    public function test_term_dates_and_immutable_structure(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        AcademicTerm::ensureExistFor('2030-2031');
        $term = AcademicTerm::where('term', 3)->first();
        foreach (['2030-06-01', '2030-05-01'] as $end) {
            $this->put(route('admin.academic-terms.update', $term), ['start_date' => '2030-06-01', 'end_date' => $end])->assertSessionHasErrors('end_date');
        }
        foreach (['term' => 1, 'term_number' => 4, 'school_year' => '2031-2032', 'academic_year_id' => 99, 'is_open' => true] as $field => $value) {
            $this->put(route('admin.academic-terms.update', $term), [$field => $value])->assertSessionHasErrors($field);
        }
        $this->put(route('admin.academic-terms.update', $term), ['start_date' => '2030-06-01', 'end_date' => '2030-07-01'])->assertSessionHas('success');
        $this->assertSame('2030-06-01', $term->fresh()->start_date->format('Y-m-d'));
        $this->assertSame(3, $term->fresh()->term);
    }

    public function test_edit_forms_render_method_and_csrf_and_post_method_spoofing_updates(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $year = \App\Models\AcademicYear::create(['school_year' => '2030-2031', 'is_active' => true]);
        AcademicTerm::ensureExistFor($year->school_year);
        $this->get(route('admin.academic-terms'))->assertOk()
            ->assertSee('name="_token"', false)->assertSee('value="PUT"', false)->assertDontSee('value="DELETE"', false)
            ->assertSee('data-academic-edit="Year"', false)->assertSee('data-academic-edit="Term"', false);
        $this->post(route('admin.academic-years.update', $year), ['_method' => 'PUT', 'school_year' => $year->school_year])->assertSessionHas('success');
        $this->post(route('admin.academic-years.update', $year), [])->assertStatus(405);
    }

    public function test_academic_configuration_has_no_delete_endpoints_or_page_actions(): void
    {
        $year = \App\Models\AcademicYear::create(['school_year' => '2030-2031', 'is_active' => true]);
        \App\Models\AcademicYear::create(['school_year' => '2031-2032']);
        AcademicTerm::ensureExistFor($year->school_year);
        $term = AcademicTerm::first();
        foreach (['admin', 'adviser', 'principal'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach (['academic-years' => $year, 'academic-terms' => $term] as $resource => $record) {
                $this->assertFalse(\Illuminate\Support\Facades\Route::has("admin.$resource.destroy"));
                $this->delete("/admin/$resource/{$record->id}")->assertStatus(405);
                $this->post("/admin/$resource/{$record->id}", ['_method' => 'DELETE'])->assertStatus(405);
                $this->assertModelExists($record);
            }
            if ($role === 'admin') {
                $html = $this->get(route('admin.academic-terms'))->assertOk()->getContent();
                $page = new \DOMDocument();
                @$page->loadHTML($html);
                $xpath = new \DOMXPath($page);
                // The shared layout has a generic confirmation popup for other
                // pages. Check this page's action forms and icons specifically.
                $this->assertSame(0, $xpath->query('//form[starts-with(@action, "http") and contains(@action, "/admin/academic-")]//input[@name="_method" and @value="DELETE"]')->length);
                $source = file_get_contents(resource_path('views/admin/academic-terms.blade.php'));
                $this->assertStringNotContainsString('bi-trash', $source);
                $this->assertStringNotContainsString('Delete', $source);
                $this->assertStringContainsString('flex-wrap', $source);
            }
        }
    }
}
