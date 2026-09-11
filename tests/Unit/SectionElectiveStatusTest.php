<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Services\SectionElectiveStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 6 — the one shared place every "how
 * many grades should exist" consumer reads from. Covers both halves
 * separately, matching the class's own docblock: isFullyConfigured()
 * (is a zero-electives result correct, or just not set up yet?) and
 * expectedSubjectsForTerm()/expectedGradeCount() (how many grades are
 * actually expected THIS term, given per-term elective coverage).
 */
class SectionElectiveStatusTest extends TestCase
{
    use RefreshDatabase;

    private SectionElectiveStatus $status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->status = new SectionElectiveStatus();
    }

    private function sshsSection(array $overrides = []): Section
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        return Section::factory()->create(array_merge([
            'curriculum' => 'sshs', 'grade_level' => 11, 'track_id' => $track->id,
            'specialization_id' => null, 'school_year' => '2026-2027',
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // isFullyConfigured()
    // ---------------------------------------------------------------

    public function test_k12_2013_sections_are_always_considered_configured(): void
    {
        $section = Section::factory()->create(['curriculum' => 'k12_2013']);
        $this->assertTrue($this->status->isFullyConfigured($section));
    }

    public function test_null_curriculum_sections_are_always_considered_configured(): void
    {
        $section = Section::factory()->create(['curriculum' => null]);
        $this->assertTrue($this->status->isFullyConfigured($section));
    }

    public function test_sshs_section_with_no_electives_available_at_all_is_correctly_zero(): void
    {
        // No elective Subject rows exist matching this section's track at
        // all — zero section_subject rows is the CORRECT state, nothing
        // to assign.
        $section = $this->sshsSection();
        $this->assertTrue($this->status->isFullyConfigured($section));
    }

    public function test_sshs_section_with_electives_available_but_none_assigned_is_not_configured(): void
    {
        $section = $this->sshsSection();
        Subject::factory()->create([
            'type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id,
        ]);

        // Zero section_subject rows for this section, but an elective DOES
        // exist for its track — this is the gap Part 6 exists to surface.
        $this->assertFalse($this->status->isFullyConfigured($section));
    }

    public function test_sshs_section_with_at_least_one_assignment_is_configured_even_if_more_electives_exist_unassigned(): void
    {
        $section = $this->sshsSection();
        $assigned = Subject::factory()->create([
            'type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id,
        ]);
        Subject::factory()->create([
            'type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id,
        ]);

        SectionSubject::create([
            'section_id' => $section->id, 'subject_id' => $assigned->id, 'school_year' => $section->school_year,
        ]);

        // "Configured" means "someone has made the assignment decision for
        // this section," not "every possible elective is assigned" — a
        // section legitimately takes a subset.
        $this->assertTrue($this->status->isFullyConfigured($section));
    }

    // ---------------------------------------------------------------
    // expectedSubjectsForTerm() / expectedGradeCount()
    // ---------------------------------------------------------------

    public function test_core_subjects_are_always_expected_every_term(): void
    {
        $section = $this->sshsSection();
        Subject::factory()->create(['type' => 'core', 'grade_level' => $section->grade_level]);

        foreach ([1, 2, 3] as $term) {
            $this->assertCount(1, $this->status->expectedSubjectsForTerm($section, $term));
        }
    }

    public function test_an_assignment_with_no_term_data_is_expected_every_term(): void
    {
        $section = $this->sshsSection();
        $elective = Subject::factory()->create(['type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id]);
        SectionSubject::create([
            'section_id' => $section->id, 'subject_id' => $elective->id, 'school_year' => $section->school_year,
            // starting_term/term_count both left null.
        ]);

        foreach ([1, 2, 3] as $term) {
            $subjects = $this->status->expectedSubjectsForTerm($section, $term);
            $this->assertTrue($subjects->contains('id', $elective->id), "Expected in term {$term}");
        }
    }

    public function test_a_one_term_elective_is_expected_only_in_its_assigned_term(): void
    {
        $section = $this->sshsSection();
        $elective = Subject::factory()->create(['type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id]);
        SectionSubject::create([
            'section_id' => $section->id, 'subject_id' => $elective->id, 'school_year' => $section->school_year,
            'starting_term' => 2, 'term_count' => 1,
        ]);

        $this->assertFalse($this->status->expectedSubjectsForTerm($section, 1)->contains('id', $elective->id), 'Not expected in Term 1');
        $this->assertTrue($this->status->expectedSubjectsForTerm($section, 2)->contains('id', $elective->id), 'Expected in Term 2');
        $this->assertFalse($this->status->expectedSubjectsForTerm($section, 3)->contains('id', $elective->id), 'Not expected in Term 3');
    }

    public function test_a_two_term_elective_starting_in_term_1_covers_terms_1_and_2_only(): void
    {
        $section = $this->sshsSection();
        $elective = Subject::factory()->create(['type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id]);
        SectionSubject::create([
            'section_id' => $section->id, 'subject_id' => $elective->id, 'school_year' => $section->school_year,
            'starting_term' => 1, 'term_count' => 2,
        ]);

        $this->assertTrue($this->status->expectedSubjectsForTerm($section, 1)->contains('id', $elective->id));
        $this->assertTrue($this->status->expectedSubjectsForTerm($section, 2)->contains('id', $elective->id));
        $this->assertFalse($this->status->expectedSubjectsForTerm($section, 3)->contains('id', $elective->id));
    }

    public function test_a_two_term_elective_starting_in_term_2_covers_terms_2_and_3_not_term_1(): void
    {
        // The work order's own text: the ECR "refuses to let a two-term
        // elective begin in Term 3" -- implying Term 1 or Term 2 are both
        // legitimate starts. This is the Term-2-start case.
        $section = $this->sshsSection();
        $elective = Subject::factory()->create(['type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id]);
        SectionSubject::create([
            'section_id' => $section->id, 'subject_id' => $elective->id, 'school_year' => $section->school_year,
            'starting_term' => 2, 'term_count' => 2,
        ]);

        $this->assertFalse($this->status->expectedSubjectsForTerm($section, 1)->contains('id', $elective->id));
        $this->assertTrue($this->status->expectedSubjectsForTerm($section, 2)->contains('id', $elective->id));
        $this->assertTrue($this->status->expectedSubjectsForTerm($section, 3)->contains('id', $elective->id));
    }

    public function test_expected_grade_count_multiplies_by_student_count(): void
    {
        $section = $this->sshsSection();
        Subject::factory()->create(['type' => 'core', 'grade_level' => $section->grade_level]);
        Student::factory()->count(3)->create(['section_id' => $section->id]);

        $this->assertSame(3, $this->status->expectedGradeCount($section, 1)); // 3 students x 1 core subject
    }

    public function test_k12_2013_sections_are_unaffected_by_per_term_filtering(): void
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        $specialization = Specialization::factory()->create(['track_id' => $track->id]);
        $section = Section::factory()->create([
            'curriculum' => 'k12_2013', 'grade_level' => 12, 'track_id' => $track->id,
            'specialization_id' => $specialization->id, 'school_year' => '2026-2027',
        ]);
        $elective = Subject::factory()->create([
            'type' => 'elective', 'grade_level' => 12, 'track_id' => $track->id, 'specialization_id' => $specialization->id,
        ]);

        // No section_subject row exists at all -- k12_2013 never reads it.
        foreach ([1, 2, 3] as $term) {
            $this->assertTrue($this->status->expectedSubjectsForTerm($section, $term)->contains('id', $elective->id));
        }
    }
}
