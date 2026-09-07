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
 * Documents the limitation recorded in CLAUDE.md's "Known limitations"
 * section: Subject::forSection() returns every elective matching a
 * section's track/specialization — the whole cluster, not the 2 a
 * Strengthened-SHS Grade 11 learner actually picks from it. There is no
 * section_subject pivot to record the difference.
 *
 * This is currently invisible because this codebase's fixtures only ever
 * import 2 STEM electives. This test adds a genuine THIRD one and proves
 * the term can never reach "complete" once it exists — exactly the
 * failure CLAUDE.md describes. Marked skipped rather than left failing:
 * it documents the exact assertion the eventual section_subject pivot
 * fix needs to satisfy, without leaving CI red for a gap that is a
 * deliberate, documented product decision (see the prompt for the
 * "carry max scores"/"remaining system issues" task set — Task 4 says
 * not to build the pivot in this pass, since it needs the user's
 * decision on how electives get assigned to a section).
 */
class ElectiveClusterLimitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_who_takes_only_two_of_three_cluster_electives_can_still_reach_term_completion(): void
    {
        $this->markTestSkipped(
            'Fails today because Subject::forSection() has no way to know a student ' .
            'only takes 2 of the 3 STEM electives offered to their section — it returns ' .
            'all 3, so AcademicTerm::completionStatus() permanently expects a grade the ' .
            'student was never meant to supply. Un-skip once a section_subject (or ' .
            'student_subject) pivot exists and forSection()/completionStatus() are ' .
            'updated to read from it. See CLAUDE.md "Known limitations".'
        );

        $adviser = User::factory()->create();
        $track = Track::factory()->create(['code' => 'ACAD']);
        $specialization = Specialization::factory()->create(['track_id' => $track->id, 'code' => 'STEM']);
        $section = Section::factory()->create([
            'adviser_id' => $adviser->id, 'grade_level' => 11,
            'track_id' => $track->id, 'specialization_id' => $specialization->id,
            'school_year' => '2026-2027',
        ]);

        // A cluster of THREE STEM electives — this is the part that isn't
        // exercised anywhere else in this codebase's fixtures/tests today.
        $preCalc = Subject::factory()->create(['name' => 'Pre-Calculus', 'type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $specialization->id]);
        $biology = Subject::factory()->create(['name' => 'General Biology 1', 'type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $specialization->id]);
        Subject::factory()->create(['name' => 'Physics', 'type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id, 'specialization_id' => $specialization->id]);

        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor($section->school_year);

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
