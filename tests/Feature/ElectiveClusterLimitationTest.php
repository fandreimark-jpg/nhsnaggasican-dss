<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 6 — UN-SKIPPED. This test used to
 * document the limitation recorded in CLAUDE.md's "Known limitations"
 * section: Subject::forSection() returned every elective matching a
 * section's track/specialization — the whole cluster, not the 2 a
 * Strengthened-SHS Grade 11 learner actually picks from it. The
 * section_subject pivot that was missing now exists; this is the exact
 * assertion it was built to satisfy.
 *
 * `curriculum: 'sshs'` is the one addition to the original setup below —
 * without it, Subject::forSection() takes the (unchanged) k12_2013
 * specialization-matching path instead of reading section_subject at
 * all, and this test would still fail for the original reason.
 */
class ElectiveClusterLimitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_who_takes_only_two_of_three_cluster_electives_can_still_reach_term_completion(): void
    {
        $adviser = User::factory()->create();
        $track = Track::factory()->create(['code' => 'ACAD']);
        $specialization = Specialization::factory()->create(['track_id' => $track->id, 'code' => 'STEM']);
        $section = Section::factory()->create([
            'adviser_id' => $adviser->id, 'grade_level' => 11, 'curriculum' => 'sshs',
            'track_id' => $track->id, 'specialization_id' => $specialization->id,
            'school_year' => '2026-2027',
        ]);

        // A cluster of THREE STEM electives — this is the part that isn't
        // exercised anywhere else in this codebase's fixtures/tests today.
        $preCalc = Subject::factory()->create(['name' => 'Pre-Calculus', 'type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $specialization->id]);
        $biology = Subject::factory()->create(['name' => 'General Biology 1', 'type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $specialization->id]);
        Subject::factory()->create(['name' => 'Physics', 'type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $specialization->id]);

        // The section is assigned exactly the 2 it actually takes — Physics
        // is never assigned, so it never becomes part of forSection()'s
        // result for this section at all. ("Student identity and
        // term-specific subject offerings" pass: an offering is per term.)
        AcademicTerm::ensureExistFor($section->school_year);
        \App\Models\SectionSubject::offer($section, $preCalc, 1);
        \App\Models\SectionSubject::offer($section, $biology, 1);

        $student = Student::factory()->create(['section_id' => $section->id]);

        // The student only ever takes 2 of the 3 — there is no way to
        // supply a Physics grade for a subject they were never enrolled in.
        foreach ([$preCalc, $biology] as $subject) {
            Grade::create([
                'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'grade' => 88, 'school_year' => $section->school_year,
            ]);
        }

        $status = AcademicTerm::completionStatus($section->school_year, 1);

        $this->assertTrue($status['complete'], 'The term should be complete once every ENROLLED subject has a grade — not every subject in the whole cluster.');
    }
}
