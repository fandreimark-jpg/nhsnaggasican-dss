<?php

namespace Tests\Unit;

use App\Models\AcademicTerm;
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
 * actually expected THIS term).
 *
 * "Subject applicability" refactor (2026-09-20) — the per-term half now
 * reads Terms Taught on the subject (SubjectApplicabilityService); an
 * SSHS section's electives reach it through an elective choice
 * (SubjectOfferingService::chooseElective()). The scenarios are the same
 * ones; the fixtures are written as subject configuration.
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
        $section = Section::factory()->create(array_merge([
            'curriculum' => 'sshs', 'grade_level' => 11, 'track_id' => $track->id,
            'specialization_id' => null, 'school_year' => '2026-2027',
        ], $overrides));
        AcademicTerm::ensureExistFor($section->school_year);

        return $section;
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
        // all — zero offering rows is the CORRECT state, nothing to assign.
        $section = $this->sshsSection();
        $this->assertTrue($this->status->isFullyConfigured($section));
    }

    public function test_sshs_section_with_electives_available_but_none_assigned_is_not_configured(): void
    {
        $section = $this->sshsSection();
        Subject::factory()->create([
            'type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id,
        ]);

        // Zero offering rows for this section, but an elective DOES exist
        // for its track — this is the gap Part 6 exists to surface.
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

        SectionSubject::offer($section, $assigned, 1);

        // "Configured" means "someone has made the assignment decision for
        // this section," not "every possible elective is assigned" — a
        // section legitimately takes a subset.
        $this->assertTrue($this->status->isFullyConfigured($section));
    }

    // ---------------------------------------------------------------
    // expectedSubjectsForTerm() / expectedGradeCount()
    // ---------------------------------------------------------------

    public function test_a_section_expects_its_core_subjects_in_every_term_they_are_taught(): void
    {
        // A factory subject is taught in every term (the same default the
        // migration backfilled), so it is expected in all three.
        $section = $this->sshsSection();
        Subject::factory()->create(['type' => 'core', 'grade_level' => $section->grade_level]);

        foreach ([1, 2, 3] as $term) {
            $this->assertCount(1, $this->status->expectedSubjectsForTerm($section, $term));
        }
    }

    public function test_a_chosen_elective_is_expected_only_in_the_terms_the_subject_is_taught(): void
    {
        // "Subject applicability" refactor — Terms Taught is SUBJECT
        // configuration; a section's elective choice applies in exactly
        // those terms, and core subjects apply in theirs regardless.
        $section = $this->sshsSection();
        $core = Subject::factory()->create(['type' => 'core', 'grade_level' => $section->grade_level]);
        $elective = Subject::factory()->taughtIn([2])->create(['type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id]);

        (new \App\Services\SubjectOfferingService())->chooseElective($section, $elective);

        $this->assertFalse($this->status->expectedSubjectsForTerm($section, 1)->contains('id', $elective->id), 'Not expected in Term 1');
        $this->assertTrue($this->status->expectedSubjectsForTerm($section, 2)->contains('id', $elective->id), 'Expected in Term 2');
        $this->assertFalse($this->status->expectedSubjectsForTerm($section, 3)->contains('id', $elective->id), 'Not expected in Term 3');
        foreach ([1, 2, 3] as $term) {
            $this->assertTrue($this->status->expectedSubjectsForTerm($section, $term)->contains('id', $core->id), "Core expected in term {$term}");
        }
    }

    public function test_a_core_subject_is_expected_only_in_its_terms_taught(): void
    {
        // Terms Taught narrows a core subject too — a Term-1-only core
        // subject is not expected in Term 2, and a term nothing is taught
        // in is an honest empty set, not a copy of another term.
        $section = $this->sshsSection();
        $termOneCore = Subject::factory()->taughtIn([1])->create(['type' => 'core', 'grade_level' => $section->grade_level]);
        $termTwoCore = Subject::factory()->taughtIn([2])->create(['type' => 'core', 'grade_level' => $section->grade_level]);

        $this->assertSame([$termOneCore->id], $this->status->expectedSubjectsForTerm($section, 1)->pluck('id')->all());
        $this->assertSame([$termTwoCore->id], $this->status->expectedSubjectsForTerm($section, 2)->pluck('id')->all());
        $this->assertCount(0, $this->status->expectedSubjectsForTerm($section, 3), 'Nothing is taught in Term 3');
    }

    public function test_a_two_term_elective_covers_only_the_terms_it_is_taught_in(): void
    {
        $section = $this->sshsSection();
        $elective = Subject::factory()->taughtIn([2, 3])->create(['type' => 'elective', 'grade_level' => $section->grade_level, 'track_id' => $section->track_id]);
        (new \App\Services\SubjectOfferingService())->chooseElective($section, $elective);

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

    public function test_k12_2013_sections_keep_their_specialization_electives_every_term_they_are_taught(): void
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

        // Reached by the section's track/specialization — no choice needed.
        foreach ([1, 2, 3] as $term) {
            $this->assertTrue($this->status->expectedSubjectsForTerm($section, $term)->contains('id', $elective->id));
        }
    }
}
