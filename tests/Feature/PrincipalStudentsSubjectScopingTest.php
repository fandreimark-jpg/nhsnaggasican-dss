<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 1 of "subject scoping and bulk threshold" — the Principal
 * Students page's Subject dropdown scoped to the selected section via
 * Subject::forSection() (the same lookup the adviser's Assessments page
 * already trusts — see Principal\StudentController::index()).
 */
class PrincipalStudentsSubjectScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAbmAndStemSections(): array
    {
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        $abm = Specialization::factory()->create(['track_id' => $track->id, 'name' => 'ABM', 'code' => 'ABM']);
        $stem = Specialization::factory()->create(['track_id' => $track->id, 'name' => 'STEM', 'code' => 'STEM']);

        $core = Subject::factory()->create(['name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11, 'track_id' => null, 'specialization_id' => null]);
        $abmElective = Subject::factory()->create(['name' => 'Business Finance', 'type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $abm->id]);
        $stemElective = Subject::factory()->create(['name' => 'Pre-Calculus', 'type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $stem->id]);

        $abmSection = Section::factory()->create(['name' => 'Narra', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $abm->id]);
        $stemSection = Section::factory()->create(['name' => 'Ipil', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $stem->id]);
        Student::factory()->create(['section_id' => $abmSection->id]);
        Student::factory()->create(['section_id' => $stemSection->id]);

        return compact('track', 'abm', 'stem', 'core', 'abmElective', 'stemElective', 'abmSection', 'stemSection');
    }

    public function test_selecting_an_abm_section_scopes_the_subject_dropdown_to_core_and_abm_only(): void
    {
        $principal = User::factory()->principal()->create();
        $data = $this->makeAbmAndStemSections();

        $response = $this->actingAs($principal)->get('/principal/students?section_id=' . $data['abmSection']->id);

        $response->assertOk();
        $response->assertSee('General Mathematics');
        $response->assertSee('Business Finance');
        $response->assertDontSee('Pre-Calculus');
    }

    public function test_switching_to_a_stem_section_changes_the_list_and_clears_an_invalid_subject_selection(): void
    {
        $principal = User::factory()->principal()->create();
        $data = $this->makeAbmAndStemSections();

        // Selected while viewing the ABM section...
        $response = $this->actingAs($principal)->get('/principal/students?section_id=' . $data['stemSection']->id . '&subject_id=' . $data['abmElective']->id);

        $response->assertOk();
        // ...but Business Finance is not offered to the STEM section, so it must be cleared, not carried forward.
        $response->assertSee('Select a subject to view students');
        $response->assertDontSee('Business Finance');
        $response->assertSee('Pre-Calculus');
        $response->assertSee('General Mathematics');
    }

    public function test_a_valid_subject_for_the_newly_selected_section_stays_selected(): void
    {
        $principal = User::factory()->principal()->create();
        $data = $this->makeAbmAndStemSections();

        $response = $this->actingAs($principal)->get('/principal/students?section_id=' . $data['stemSection']->id . '&subject_id=' . $data['stemElective']->id);

        $response->assertOk();
        $response->assertViewHas('subject', fn($s) => $s && $s->id === $data['stemElective']->id);
    }

    public function test_no_section_selected_shows_the_full_list_grouped_by_grade_level(): void
    {
        $principal = User::factory()->principal()->create();
        Subject::factory()->create(['name' => 'Grade 11 Subject', 'type' => 'core', 'grade_level' => 11]);
        Subject::factory()->create(['name' => 'Grade 12 Subject', 'type' => 'core', 'grade_level' => 12]);

        $response = $this->actingAs($principal)->get('/principal/students');

        $response->assertOk();
        $response->assertSee('<optgroup label="Grade 11">', false);
        $response->assertSee('<optgroup label="Grade 12">', false);
    }

    public function test_a_section_with_no_track_or_specialization_names_that_as_the_cause(): void
    {
        $principal = User::factory()->principal()->create();
        // No core subjects at this grade level, and the section has no track/specialization.
        $section = Section::factory()->create(['name' => 'Unassigned Section', 'grade_level' => 11, 'track_id' => null, 'specialization_id' => null]);
        Student::factory()->create(['section_id' => $section->id]);

        $response = $this->actingAs($principal)->get('/principal/students?section_id=' . $section->id);

        $response->assertOk();
        $response->assertSee('Track and/or Specialization is not set', false);
    }
}
