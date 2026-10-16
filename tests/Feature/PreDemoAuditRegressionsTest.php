<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * "Pre-demo full-system audit" (2026-09-20) — regressions for defects found
 * by exercising the live application, not the unit layer:
 *
 *  1. A file with a spreadsheet extension that PhpSpreadsheet cannot open
 *     (truncated/corrupt) produced a 500 from adviser/assessments/detect —
 *     the extension rule deliberately does not sniff content, so nothing
 *     caught the reader exception.
 *  2. Five pages (Adviser Assessments, Adviser Grades, Admin Tracks, Admin
 *     Specializations, Principal Interventions) rendered neither
 *     $errors nor a page-level alert, so a rejected form (wrong file type,
 *     duplicate track, invalid grade) bounced back with no message at all.
 *     `partials/validation-errors` is now included on each.
 */
class PreDemoAuditRegressionsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;

    private function adviserWithSection(): array
    {
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core', 'subject_group' => 'core_academic']);
        $subject->syncTerms([1, 2, 3]);
        Student::factory()->create(['section_id' => $section->id]);

        return [$adviser, $section, $subject];
    }
    public function test_a_duplicate_track_shows_its_validation_error_on_the_tracks_page(): void
    {
        $admin = User::factory()->admin()->create();
        Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        $this->actingAs($admin)->from(route('admin.tracks'))->post(route('admin.tracks.store'), ['name' => 'Academic Track', 'code' => 'ACAD2'])
            ->assertRedirect(route('admin.tracks'))->assertSessionHasErrors('name');

        $this->actingAs($admin)->get(route('admin.tracks'))->assertOk()
            ->assertSee('data-validation-errors', false)
            ->assertSee('already been taken');
    }

    public function test_a_duplicate_specialization_shows_its_validation_error_on_the_specializations_page(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create(['code' => 'ACAD']);
        Specialization::factory()->create(['track_id' => $track->id, 'code' => 'STEM', 'name' => 'STEM']);

        $response = $this->actingAs($admin)->from(route('admin.specializations'))
            ->post(route('admin.specializations.store'), ['track_id' => $track->id, 'code' => 'STEM', 'name' => 'STEM']);
        $response->assertRedirect(route('admin.specializations'));
        $this->assertTrue($response->getSession()->has('errors') || $response->getSession()->has('error'), 'A duplicate must be refused with a message.');

        $html = $this->actingAs($admin)->get(route('admin.specializations'))->assertOk()->getContent();
        $this->assertTrue(str_contains($html, 'data-validation-errors') || str_contains($html, 'alert-danger'), 'The refusal is visible on the page.');
    }

    public function test_an_invalid_grade_shows_its_validation_error_on_the_grades_page(): void
    {
        [$adviser, $section, $subject] = $this->adviserWithSection();
        $student = Student::enrolledIn($section)->first();

        $this->actingAs($adviser)->from('/adviser/grades')->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [['student_id' => $student->id, 'subject_id' => $subject->id, 'grade' => 150]],
        ])->assertRedirect('/adviser/grades')->assertSessionHasErrors('grades.0.grade');

        $this->actingAs($adviser)->get('/adviser/grades')->assertOk()->assertSee('data-validation-errors', false);
    }

    // ------------------------------------------------------------------
    // Phase 29 — invariants that were form-only now have a DB constraint
    // ------------------------------------------------------------------

    public function test_a_duplicate_section_name_in_the_same_grade_and_year_is_refused_by_the_form_and_the_database(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create(['code' => 'ACAD']);
        $spec = Specialization::factory()->create(['track_id' => $track->id]);
        Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'track_id' => $track->id, 'specialization_id' => $spec->id]);

        $this->actingAs($admin)->from(route('admin.sections'))->post(route('admin.sections.store'), [
            'name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'track_id' => $track->id, 'specialization_id' => $spec->id,
        ])->assertRedirect(route('admin.sections'))->assertSessionHasErrors('name');
        $this->assertSame(1, Section::where('name', 'Curie')->count());

        // Same name at the OTHER grade level, same year, is allowed.
        $this->actingAs($admin)->post(route('admin.sections.store'), [
            'name' => 'Curie', 'grade_level' => 12, 'school_year' => '2026-2027', 'track_id' => $track->id, 'specialization_id' => $spec->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Section::where('name', 'Curie')->count());

        // The database refuses the duplicate even when validation is bypassed.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'track_id' => $track->id, 'specialization_id' => $spec->id]);
    }

    public function test_a_duplicate_subject_name_at_the_same_grade_level_is_refused_by_the_database(): void
    {
        Subject::factory()->create(['name' => 'General Mathematics', 'grade_level' => 11]);
        Subject::factory()->create(['name' => 'General Mathematics', 'grade_level' => 12]); // other grade: fine

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Subject::factory()->create(['name' => 'General Mathematics', 'grade_level' => 11]);
    }

    public function test_the_principal_interventions_page_renders_default_validation_errors(): void
    {
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->from(route('principal.interventions'))
            ->post(route('principal.interventions.store'), ['student_id' => 999999])
            ->assertRedirect(route('principal.interventions'))->assertSessionHasErrors();

        $this->actingAs($principal)->get(route('principal.interventions'))->assertOk()->assertSee('data-validation-errors', false);
    }

    /**
     * Final pre-demo audit (2026-09-20) — the Adviser's My Students page
     * had no validation-error display for a rejected Edit Student submit.
     * Since 2026-09-27 the adviser cannot edit learner master data at all:
     * the route is gone, the request 404s and the record is untouched. The
     * page still renders a flashed validation message.
     */
    public function test_a_student_edit_by_the_adviser_is_refused_and_my_students_still_shows_errors(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Kept']);

        $this->actingAs($adviser)->put('/adviser/students/' . $student->id, ['last_name' => '', 'first_name' => 'X', 'gender' => 'male'])
            ->assertNotFound();
        $this->assertSame('Kept', $student->fresh()->last_name);

        $this->actingAs($adviser)->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['x' => 'A flashed message.']))])
            ->get(route('adviser.students'))->assertOk()
            ->assertSee('data-validation-errors', false)
            ->assertSee('A flashed message.');
    }
}
