<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TransmutationRange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 3 of "Naggasican DSS: unblock verification" — dss:recompute-grades
 * must mark a grade computed under the fallback scheme so it can be
 * found later, and clear that marking once the real table is seeded and
 * a recompute lands on a real match.
 */
class RecomputeGradesProvisionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    public function test_recompute_clears_the_provisional_flag_once_the_real_scheme_gains_a_matching_band(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');

        // WW=84, PT=60, Exam=70 -> 67.8 under do015_2026's core_academic
        // weights — off the real scheme's single seeded anchor, so this
        // verifies PROVISIONAL (using do8_2015's 66.40-67.99 -> 79 band).
        foreach (['written_work' => 84, 'performance_task' => 60, 'examination' => 70] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'is_provisional' => 1, 'provisional_scheme' => 'do8_2015', 'grade' => 79.0,
        ]);

        // The real table gets entered — a test band covering 67.8 that
        // is NOT the same transmuted value as the do8_2015 fallback gave,
        // so the test can tell the real scheme's value was actually used.
        TransmutationRange::create(['scheme' => 'do015_2026', 'min_initial' => 67.60, 'max_initial' => 68.39, 'transmuted' => 82]);

        $this->artisan('dss:recompute-grades', ['school_year' => '2026-2027', 'grading_period' => 1])
            ->expectsConfirmation('Apply these 1 change(s) to verified grades? This rewrites the official grade.', 'yes')
            ->assertExitCode(0);

        $grade = Grade::where('student_id', $student->id)->first();
        $this->assertEquals(82.0, $grade->grade);
        $this->assertFalse((bool) $grade->is_provisional);
        $this->assertNull($grade->provisional_scheme);
    }

    public function test_recompute_leaves_a_still_unmatched_provisional_grade_marked(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');

        foreach (['written_work' => 84, 'performance_task' => 60, 'examination' => 70] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        // No new do015_2026 band added — still off the real scheme.
        $this->artisan('dss:recompute-grades', ['school_year' => '2026-2027', 'grading_period' => 1])
            ->assertExitCode(0);

        $grade = Grade::where('student_id', $student->id)->first();
        $this->assertTrue((bool) $grade->is_provisional);
        $this->assertSame('do8_2015', $grade->provisional_scheme);
    }
}
