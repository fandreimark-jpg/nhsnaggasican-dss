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
 * "Workflow completion pass" TASK 1e — the activity log is the audit
 * trail and must show which student got which grade: verifying N
 * students in one batch must write N separate activity_logs rows, never
 * one row summarizing the whole batch.
 */
// NOTE ('SSHS ECR grading correction', 2026-09-20): these Grade 12
// sections carry an EXPLICIT k12_2013 curriculum because this file's
// intent is the DO 8, s. 2015 (legacy) path. An unset curriculum in
// SY 2026-2027 now resolves to DO 015 for both grade levels.
class VerifyAllLogsPerStudentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    public function test_verifying_several_students_produces_one_log_entry_each_not_one_for_the_batch(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['curriculum' => 'k12_2013', 'adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $students = collect();
        for ($i = 0; $i < 5; $i++) {
            $student = Student::factory()->create(['section_id' => $section->id]);
            foreach (['written_work' => 84, 'performance_task' => 60, 'examination' => 70] as $component => $earned) {
                $assessment = Assessment::factory()->create([
                    'subject_id' => $subject->id, 'section_id' => $section->id,
                    'grading_period' => 1, 'school_year' => $section->school_year,
                    'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
                ]);
                AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
            }
            $students->push($student);
        }

        $before = \App\Models\ActivityLog::where('action', 'verify_computed_grade')->count();

        $response = $this->actingAs($adviser)->postJson('/adviser/grades/verify-all', [
            'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertOk();
        $response->assertJson(['verified_count' => 5]);

        $after = \App\Models\ActivityLog::where('action', 'verify_computed_grade')->count();
        $this->assertSame(5, $after - $before, 'Five verified students must produce five activity log entries, not one for the batch.');

        // WW=84, PT=60, Exam=70 under do8_2015's 25/50/25 weights ->
        // computed 68.5, which falls in the 68.00-69.59 band -> transmuted 80
        // (same worked example as GradeVerificationTest).
        foreach ($students as $student) {
            $this->assertDatabaseHas('activity_logs', [
                'action'      => 'verify_computed_grade',
                'description' => 'Verified computed grade (68.5 -> transmuted 80) as official for ' . $student->last_name . ', ' . $student->first_name . ' — ' . $subject->name,
            ]);
        }
    }
}
