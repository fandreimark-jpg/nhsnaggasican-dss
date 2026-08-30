<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentComponent;
use App\Models\AssessmentScore;
use App\Models\AssessmentUpload;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-4: the additive assessment data layer (assessments = evidence,
 * assessment_scores = per-student scores, assessment_components = weight
 * lookup, assessment_uploads = upload audit trail). No workflow yet
 * (that's P-5) — this covers the schema, models, relationships, and the
 * DB-level constraints that must hold regardless of what UI is built on
 * top of them later.
 */
class AssessmentDataLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_assessment_components_are_seeded_and_weights_sum_to_100(): void
    {
        $components = AssessmentComponent::all();

        $this->assertCount(3, $components);
        $this->assertEqualsWithDelta(100.0, $components->sum('weight'), 0.001);

        $this->assertSame(25.0, (float) AssessmentComponent::writtenWork()->weight);
        $this->assertSame(50.0, (float) AssessmentComponent::performanceTask()->weight);
        $this->assertSame(25.0, (float) AssessmentComponent::examination()->weight);
    }

    public function test_an_assessment_item_can_be_created_with_scores_for_multiple_students(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level]);
        $studentA = Student::factory()->create(['section_id' => $section->id]);
        $studentB = Student::factory()->create(['section_id' => $section->id]);

        $assessment = Assessment::factory()->create([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'name'       => 'Quiz 1',
            'component'  => 'written_work',
            'max_score'  => 20,
            'uploaded_by' => $adviser->id,
        ]);

        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $studentA->id, 'score' => 18]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $studentB->id, 'score' => 15]);

        $this->assertCount(2, $assessment->scores);
        $this->assertEquals(90.0, $assessment->scores->firstWhere('student_id', $studentA->id)->percentage);
        $this->assertEquals(75.0, $assessment->scores->firstWhere('student_id', $studentB->id)->percentage);
    }

    public function test_the_same_assessment_name_cannot_be_created_twice_for_the_same_subject_section_term(): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();

        Assessment::factory()->create([
            'section_id' => $section->id, 'subject_id' => $subject->id,
            'grading_period' => 1, 'school_year' => '2026-2027', 'name' => 'Quiz 1',
        ]);

        $this->expectException(QueryException::class);

        Assessment::factory()->create([
            'section_id' => $section->id, 'subject_id' => $subject->id,
            'grading_period' => 1, 'school_year' => '2026-2027', 'name' => 'Quiz 1',
        ]);
    }

    public function test_a_student_cannot_have_two_scores_for_the_same_assessment_item(): void
    {
        $assessment = Assessment::factory()->create();
        $student    = Student::factory()->create();

        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id]);

        $this->expectException(QueryException::class);

        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id]);
    }

    public function test_deleting_an_assessment_item_cascades_to_its_scores(): void
    {
        $assessment = Assessment::factory()->create();
        $score      = AssessmentScore::factory()->create(['assessment_id' => $assessment->id]);

        $assessment->delete();

        $this->assertDatabaseMissing('assessment_scores', ['id' => $score->id]);
    }

    public function test_an_assessment_upload_records_the_verified_column_mapping(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $upload = AssessmentUpload::create([
            'section_id'         => $section->id,
            'subject_id'         => $subject->id,
            'uploaded_by'        => $adviser->id,
            'grading_period'     => 1,
            'school_year'        => $section->school_year,
            'original_filename'  => 'term1_quizzes.xlsx',
            'column_mapping'     => ['Quiz 1' => 'written_work', 'Performance Task 1' => 'performance_task'],
            'status'             => 'pending_review',
        ]);

        $this->assertSame('written_work', $upload->fresh()->column_mapping['Quiz 1']);
        $this->assertSame('pending_review', $upload->status);
    }
}
