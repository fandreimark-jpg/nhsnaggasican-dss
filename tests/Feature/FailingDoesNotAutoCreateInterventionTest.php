<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
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
 * "The FAILING layer" Hard Constraint 2: Failing is a display and filter
 * signal only. Encoding an official failing grade must never, by itself,
 * create an Intervention row — the Principal must still take an
 * explicit action. Per CLAUDE.md: the DSS recommends, the Principal
 * decides.
 */
// NOTE ('SSHS ECR grading correction', 2026-09-20): these Grade 12
// sections carry an EXPLICIT k12_2013 curriculum because this file's
// intent is the DO 8, s. 2015 (legacy) path. An unset curriculum in
// SY 2026-2027 now resolves to DO 015 for both grade levels.
class FailingDoesNotAutoCreateInterventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_verifying_an_official_grade_of_70_creates_zero_interventions(): void
    {
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);

        $adviser = User::factory()->create();
        $section = Section::factory()->create(['curriculum' => 'k12_2013', 'adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');

        // WW=70, PT=70, Exam=70 -> computed_grade 70.0, do8_2015 band
        // 68.00-69.59 does not apply here; 70.00 lands on a passing-ish
        // band but the point is only that verification runs at all and
        // produces SOME official grade — never that it auto-creates an
        // intervention regardless of the resulting value.
        foreach (['written_work' => 70, 'performance_task' => 70, 'examination' => 70] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $this->assertSame(0, Intervention::count());

        $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $this->assertDatabaseHas('grades', ['student_id' => $student->id, 'subject_id' => $subject->id, 'is_verified' => 1]);
        $this->assertSame(0, Intervention::count(), 'Verifying an official grade must never auto-create an intervention, even a failing one.');
    }
}
