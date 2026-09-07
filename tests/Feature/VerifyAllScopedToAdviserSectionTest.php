<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Workflow completion pass" TASK 1f — Verify All Remaining must be
 * enforced in the query scope, not only hidden in the UI. An Adviser can
 * only ever verify students in THEIR OWN assigned section, tested via a
 * direct request naming a subject that belongs to a different section's
 * grade level/track (never offered to the requesting Adviser's own
 * section).
 */
class VerifyAllScopedToAdviserSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    public function test_an_adviser_cannot_preview_verify_all_for_a_subject_outside_their_own_section(): void
    {
        $adviserA = User::factory()->create();
        $sectionA = Section::factory()->create(['adviser_id' => $adviserA->id, 'grade_level' => 11]);

        $adviserB = User::factory()->create();
        $sectionB = Section::factory()->create(['adviser_id' => $adviserB->id, 'grade_level' => 12]);
        $subjectB = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        $response = $this->actingAs($adviserA)
            ->getJson('/adviser/grades/verify-all/preview?subject_id=' . $subjectB->id . '&grading_period=1');

        $response->assertForbidden();
    }

    public function test_an_adviser_cannot_execute_verify_all_for_a_subject_outside_their_own_section(): void
    {
        $adviserA = User::factory()->create();
        $sectionA = Section::factory()->create(['adviser_id' => $adviserA->id, 'grade_level' => 11]);

        $adviserB = User::factory()->create();
        $sectionB = Section::factory()->create(['adviser_id' => $adviserB->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subjectB = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $otherStudent = Student::factory()->create(['section_id' => $sectionB->id]);
        foreach (['written_work' => 90, 'performance_task' => 90, 'examination' => 90] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subjectB->id, 'section_id' => $sectionB->id, 'grading_period' => 1,
                'school_year' => $sectionB->school_year, 'name' => $component . '-' . uniqid(),
                'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $otherStudent->id, 'score' => $earned]);
        }

        $response = $this->actingAs($adviserA)->postJson('/adviser/grades/verify-all', [
            'subject_id' => $subjectB->id, 'grading_period' => 1,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('grades', ['student_id' => $otherStudent->id]);
    }

    public function test_verify_all_only_ever_reaches_students_in_the_advisers_own_section_even_with_a_shared_subject(): void
    {
        // A CORE subject is offered to every section at its grade level —
        // both sections here legitimately offer the same Subject row. The
        // scoping guarantee that matters is the STUDENT query
        // (Student::where('section_id', $section->id)), not the subject
        // lookup, which will resolve successfully for both advisers.
        $adviserA = User::factory()->create();
        $sectionA = Section::factory()->create(['adviser_id' => $adviserA->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $adviserB = User::factory()->create();
        $sectionB = Section::factory()->create(['adviser_id' => $adviserB->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $studentInSectionB = Student::factory()->create(['section_id' => $sectionB->id]);
        foreach (['written_work' => 90, 'performance_task' => 90, 'examination' => 90] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $sectionB->id, 'grading_period' => 1,
                'school_year' => $sectionB->school_year, 'name' => $component . '-' . uniqid(),
                'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $studentInSectionB->id, 'score' => $earned]);
        }

        // AdviserA has no students in their own section for this subject/term.
        $response = $this->actingAs($adviserA)->postJson('/adviser/grades/verify-all', [
            'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        // Nothing eligible in AdviserA's own (empty) section — the OTHER
        // section's student must never be touched even though the
        // subject_id resolved successfully.
        $response->assertStatus(422);
        $this->assertDatabaseMissing('grades', ['student_id' => $studentInSectionB->id]);
    }
}
