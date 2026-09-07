<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 1 of "Naggasican DSS: unblock verification" — config('dss.
 * transmutation_fallback_scheme'). With it null (the default), a scheme
 * with no matching band stays exactly as unavailable as before this task
 * (see GradingEngineTest::test_grade_11_sy_2026_2027_off_the_seeded_anchor_has_no_transmuted_grade
 * and GradeVerificationTest::test_cannot_verify_when_the_transmuted_grade_is_not_available_for_the_scheme,
 * both unchanged by this task). With it naming another scheme that DOES
 * have a match, the grade computes, is marked provisional everywhere,
 * and can be verified.
 */
class TransmutationFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    private function fullyScore(Section $section, Subject $subject, Student $student, int $term = 1): void
    {
        // WW=84, PT=60, Exam=70 -> under do015_2026's core_academic
        // weights (20/50/30): 84*.20 + 60*.50 + 70*.30 = 67.8, off the
        // do015_2026 scheme's single seeded anchor (70.00-70.00) — same
        // fixture as GradingEngineTest's off-anchor test.
        foreach (['written_work' => 84, 'performance_task' => 60, 'examination' => 70] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => $term, 'school_year' => $section->school_year,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }
    }

    public function test_with_no_fallback_configured_an_unmatched_grade_11_scheme_stays_unavailable(): void
    {
        config(['dss.transmutation_fallback_scheme' => null]);

        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $this->fullyScore($section, $subject, $student);

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertEquals(67.8, $result['computed_grade']);
        $this->assertFalse($result['transmutation_available']);
        $this->assertFalse($result['transmutation_provisional']);
        $this->assertNull($result['transmuted_grade']);
        $this->assertNull($result['transmutation_fallback_scheme']);
    }

    public function test_with_do8_2015_configured_as_fallback_the_grade_computes_and_is_marked_provisional(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $this->fullyScore($section, $subject, $student);

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertEquals(67.8, $result['computed_grade'], 'computed_grade is unaffected by the fallback — only the transmuted step changes.');
        $this->assertSame('do015_2026', $result['transmutation_scheme'], 'the REAL scheme is still reported, unchanged.');
        $this->assertTrue($result['transmutation_available']);
        $this->assertTrue($result['transmutation_provisional']);
        $this->assertSame('do8_2015', $result['transmutation_fallback_scheme']);
        // 67.8 falls in do8_2015's 66.40-67.99 band -> transmuted 79.
        $this->assertEquals(79.0, $result['transmuted_grade']);
    }

    public function test_grade_12_is_never_provisional_since_do8_2015_is_its_real_scheme(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $this->fullyScore($section, $subject, $student);

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertSame('do8_2015', $result['transmutation_scheme']);
        $this->assertFalse($result['transmutation_provisional']);
        $this->assertNull($result['transmutation_fallback_scheme']);
        $this->assertEquals(80.0, $result['transmuted_grade']);
    }

    public function test_verifying_a_provisional_grade_stores_the_flag_and_flashes_a_provisional_message(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        $this->fullyScore($section, $subject, $student);

        $response = $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertSessionHas('success');
        $this->assertStringContainsString('PROVISIONAL', session('success'));
        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'grade' => 79.0, 'computed_grade' => 67.8,
            'is_verified' => 1, 'is_provisional' => 1, 'provisional_scheme' => 'do8_2015',
        ]);
    }

    public function test_verifying_a_grade_12_never_marks_it_provisional(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        $this->fullyScore($section, $subject, $student);

        $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'is_provisional' => 0, 'provisional_scheme' => null,
        ]);
    }

    public function test_adviser_dashboard_shows_the_non_dismissible_banner_when_fallback_is_active(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 11]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('Provisional grading fallback active');
        $response->assertSee('DO 8, s. 2015');
    }

    public function test_adviser_dashboard_shows_no_banner_when_fallback_is_not_configured(): void
    {
        config(['dss.transmutation_fallback_scheme' => null]);

        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 11]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertDontSee('Provisional grading fallback active');
    }

    public function test_adviser_dashboard_shows_no_banner_for_a_grade_12_section_even_with_fallback_configured(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertDontSee('Provisional grading fallback active');
    }

    public function test_principal_dashboard_shows_the_banner_naming_grade_11_when_fallback_is_active(): void
    {
        config(['dss.transmutation_fallback_scheme' => 'do8_2015']);

        $principal = User::factory()->principal()->create();
        Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('Provisional grading fallback active');
        $response->assertSee('Grade 11');
    }
}
