<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-13: cross-section access. An adviser must only ever be able to read
 * or write students/grades belonging to THEIR OWN section — verified
 * here by directly attempting to act on another adviser's student via
 * a crafted ID, not by checking the UI hides the option.
 */
class CrossSectionAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_adviser_cannot_update_a_student_in_another_advisers_section(): void
    {
        $adviserA = User::factory()->create();
        $adviserB = User::factory()->create();
        $sectionB = Section::factory()->create(['adviser_id' => $adviserB->id]);
        $otherStudent = Student::factory()->create(['section_id' => $sectionB->id, 'last_name' => 'Original']);

        $sectionA = Section::factory()->create(['adviser_id' => $adviserA->id]);

        $response = $this->actingAs($adviserA)->put('/adviser/students/' . $otherStudent->id, [
            'last_name' => 'Tampered', 'first_name' => 'Name', 'gender' => 'male',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('students', ['id' => $otherStudent->id, 'last_name' => 'Original']);
    }

    public function test_adviser_cannot_encode_a_grade_for_a_student_in_another_advisers_section(): void
    {
        $adviserA = User::factory()->create();
        $adviserB = User::factory()->create();
        $sectionA = Section::factory()->create(['adviser_id' => $adviserA->id]);
        $sectionB = Section::factory()->create(['adviser_id' => $adviserB->id, 'grade_level' => $sectionA->grade_level]);
        $otherStudent = Student::factory()->create(['section_id' => $sectionB->id]);
        $subject = Subject::factory()->create(['grade_level' => $sectionA->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor($sectionA->school_year);

        $response = $this->actingAs($adviserA)->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [
                ['student_id' => $otherStudent->id, 'subject_id' => $subject->id, 'grade' => 95],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('grades', ['student_id' => $otherStudent->id]);
    }

    public function test_adviser_cannot_upload_assessment_scores_for_a_student_in_another_advisers_section(): void
    {
        $adviserA = User::factory()->create();
        $adviserB = User::factory()->create();
        $sectionA = Section::factory()->create(['adviser_id' => $adviserA->id, 'school_year' => '2026-2027']);
        $sectionB = Section::factory()->create(['adviser_id' => $adviserB->id, 'grade_level' => $sectionA->grade_level, 'school_year' => '2026-2027']);
        $otherStudent = Student::factory()->create(['section_id' => $sectionB->id, 'lrn' => '100000000099']);
        $subject = Subject::factory()->create(['grade_level' => $sectionA->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor('2026-2027');

        // AssessmentUploadService only matches students within the target
        // section passed to it, so an LRN from another adviser's section
        // simply won't match — verified directly against the service,
        // same as AssessmentUploadServiceTest's other row-level checks.
        $path = tempnam(sys_get_temp_dir(), 'cross_section_') . '.csv';
        file_put_contents($path, "lrn,last_name,first_name,Quiz 1\n100000000099,Other,Student,18\n");

        $service = new \App\Services\AssessmentUploadService();
        $upload  = \App\Models\AssessmentUpload::factory()->create();

        $result = $service->import(
            $path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $sectionA, $subject, 1, '2026-2027', $adviserA->id, $upload
        );

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertDatabaseMissing('assessment_scores', ['student_id' => $otherStudent->id]);

        @unlink($path);
    }
}
