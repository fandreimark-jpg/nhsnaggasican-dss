<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Clarity, progress, and visual design pass" TASK 4a — "Hide learners
 * with an active intervention" is a VIEW filter only. It must never
 * change In-Term Status itself (see InTermStatusIgnoresInterventionsTest
 * for that guarantee) — it only shortens what the Principal sees on this
 * one page, and one click (clearing the checkbox) always restores the
 * full list.
 */
class HideActiveInterventionFilterTest extends TestCase
{
    use RefreshDatabase;

    private function atRiskStudent(Section $section, Subject $subject, string $lastName): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => $lastName]);
        foreach (['written_work' => 40, 'performance_task' => 40, 'examination' => 40] as $component => $score) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $score]);
        }
        return $student;
    }

    public function test_hiding_active_intervention_removes_only_the_supported_student_and_status_stays_at_risk(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create();
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        $supported = $this->atRiskStudent($section, $subject, 'Supported');
        $unsupported = $this->atRiskStudent($section, $subject, 'Unsupported');

        Intervention::factory()->create([
            'student_id' => $supported->id, 'subject_id' => $subject->id,
            'grading_period' => 1, 'status' => 'in_progress',
        ]);

        $withoutFilter = $this->actingAs($principal)->get("/principal/students?period=1&subject_id={$subject->id}");
        $withoutFilter->assertOk();
        $withoutFilter->assertSee('Supported');
        $withoutFilter->assertSee('Unsupported');

        $withFilter = $this->actingAs($principal)->get("/principal/students?period=1&subject_id={$subject->id}&hide_active_intervention=1");
        $withFilter->assertOk();
        $withFilter->assertDontSee('Supported, ', false);
        $withFilter->assertSee('Unsupported');
    }
}
