<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GradeController::store() must only accept subject_ids that are actually
 * offered to the adviser's section (grade level / track / specialization) —
 * 'exists:subjects,id' alone only proves the subject exists SOMEWHERE.
 */
class GradeEncodingScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_adviser_cannot_encode_a_grade_for_a_subject_outside_their_sections_scope(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        // A grade-12 subject — not offered to this grade-11 section at all.
        $foreignSubject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [
                ['student_id' => $student->id, 'subject_id' => $foreignSubject->id, 'grade' => 90],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('grades', [
            'student_id' => $student->id,
            'subject_id' => $foreignSubject->id,
        ]);
    }

    public function test_adviser_can_encode_a_grade_for_an_elective_matching_their_sections_track(): void
    {
        $adviser = User::factory()->create();
        $track   = Track::factory()->create();
        $section = Section::factory()->create([
            'adviser_id' => $adviser->id,
            'grade_level' => 11,
            'track_id'    => $track->id,
        ]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create([
            'grade_level' => 11,
            'type'        => 'elective',
            'track_id'    => $track->id,
        ]);

        AcademicTerm::ensureExistFor($section->school_year);

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
}
