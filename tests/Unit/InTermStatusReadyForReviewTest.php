<?php

namespace Tests\Unit;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 2 of "bulk dialog and intervention closure" —
 * InTermStatusService::isReadyForReview()'s own boundary conditions,
 * isolated from the controller/view. See InterventionReadyForReviewTest
 * for the end-to-end page behavior.
 */
class InTermStatusReadyForReviewTest extends TestCase
{
    use RefreshDatabase;

    private InTermStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InTermStatusService();
    }

    private function onTrackStudent(Section $section, Subject $subject): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id]);
        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
                'school_year' => $section->school_year, 'name' => $component . '-' . uniqid(),
                'component' => $component, 'max_score' => 20,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 18]); // 90%
        }
        return $student;
    }

    public function test_true_when_delivered_approved_and_on_track(): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();
        $student = $this->onTrackStudent($section, $subject);

        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved', 'delivered_at' => now(),
        ]);

        $this->assertTrue($this->service->isReadyForReview($intervention));
    }

    public function test_true_when_status_is_in_progress(): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();
        $student = $this->onTrackStudent($section, $subject);

        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'in_progress', 'delivered_at' => now(),
        ]);

        $this->assertTrue($this->service->isReadyForReview($intervention));
    }

    /** @dataProvider ineligibleStatuses */
    public function test_false_for_statuses_not_yet_acted_on_or_already_decided(string $status): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();
        $student = $this->onTrackStudent($section, $subject);

        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => $status, 'delivered_at' => now(),
        ]);

        $this->assertFalse($this->service->isReadyForReview($intervention));
    }

    public static function ineligibleStatuses(): array
    {
        return [
            'recommended' => ['recommended'],
            'in_review'   => ['in_review'],
            'completed'   => ['completed'],
            'monitoring'  => ['monitoring'],
        ];
    }

    public function test_false_when_not_delivered(): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();
        $student = $this->onTrackStudent($section, $subject);

        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved', 'delivered_at' => null,
        ]);

        $this->assertFalse($this->service->isReadyForReview($intervention));
    }

    public function test_false_when_student_still_at_risk(): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => 'WW1', 'component' => 'written_work', 'max_score' => 20,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 8]);
        $assessment2 = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => 'PT1', 'component' => 'performance_task', 'max_score' => 20,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment2->id, 'student_id' => $student->id, 'score' => 8]);

        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved', 'delivered_at' => now(),
        ]);

        $this->assertFalse($this->service->isReadyForReview($intervention));
    }

    public function test_false_when_grading_period_is_missing(): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();
        $student = $this->onTrackStudent($section, $subject);

        $intervention = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => null,
            'status' => 'approved', 'delivered_at' => now(),
        ]);

        $this->assertFalse($this->service->isReadyForReview($intervention));
    }
}
