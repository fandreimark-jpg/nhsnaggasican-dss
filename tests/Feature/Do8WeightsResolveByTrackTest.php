<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\Track;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 2d — DO 8, s. 2015 weights by TRACK, not
 * DO 015's subject-group axis, and has no per-subject catalog the way DO 015
 * does. GradingEngine::resolveDo8GroupKey() is the written-down mapping:
 * Academic-track core -> do8_core (25/50/25); Academic-track elective named
 * for Work Immersion/Research/Business Enterprise Simulation ->
 * do8_academic_work_immersion (35/40/25); an ordinary Academic elective ->
 * do8_academic_other (25/45/30); a non-Academic-track (TVL) elective ->
 * one of the do8_tvl_sports_arts_* slugs, both 20/60/20 regardless of name.
 * Zero Grade 12 subjects exist in the real database as of this work order
 * (Part 7 is blocked on Q2), so this is tested with factory-built
 * subjects/sections rather than live data.
 */
class Do8WeightsResolveByTrackTest extends TestCase
{
    use RefreshDatabase;

    private GradingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new GradingEngine();
    }

    public function test_academic_core_subject_resolves_to_do8_core_25_50_25(): void
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        $section = Section::factory()->create(['grade_level' => 12, 'track_id' => $track->id]);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 12, 'track_id' => null]);

        $slug = $this->engine->resolveDo8GroupKey($section, $subject);
        $this->assertSame('do8_core', $slug);

        $weights = SubjectGroupWeight::resolve('do8_2015', $slug);
        $this->assertEquals(25.0, (float) $weights->ww_weight);
        $this->assertEquals(50.0, (float) $weights->pt_weight);
        $this->assertEquals(25.0, (float) $weights->ex_weight);
    }

    public function test_academic_work_immersion_elective_resolves_to_35_40_25(): void
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        $section = Section::factory()->create(['grade_level' => 12, 'track_id' => $track->id]);
        $subject = Subject::factory()->create(['type' => 'elective', 'grade_level' => 12, 'name' => 'Work Immersion', 'track_id' => $track->id]);

        $slug = $this->engine->resolveDo8GroupKey($section, $subject);
        $this->assertSame('do8_academic_work_immersion', $slug);

        $weights = SubjectGroupWeight::resolve('do8_2015', $slug);
        $this->assertEquals(35.0, (float) $weights->ww_weight);
        $this->assertEquals(40.0, (float) $weights->pt_weight);
        $this->assertEquals(25.0, (float) $weights->ex_weight);
    }

    public function test_an_ordinary_academic_elective_resolves_to_25_45_30(): void
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        $section = Section::factory()->create(['grade_level' => 12, 'track_id' => $track->id]);
        $subject = Subject::factory()->create(['type' => 'elective', 'grade_level' => 12, 'name' => 'Creative Writing', 'track_id' => $track->id]);

        $slug = $this->engine->resolveDo8GroupKey($section, $subject);
        $this->assertSame('do8_academic_other', $slug);

        $weights = SubjectGroupWeight::resolve('do8_2015', $slug);
        $this->assertEquals(25.0, (float) $weights->ww_weight);
        $this->assertEquals(45.0, (float) $weights->pt_weight);
        $this->assertEquals(30.0, (float) $weights->ex_weight);
    }

    public function test_a_non_academic_track_elective_resolves_to_20_60_20_regardless_of_name(): void
    {
        $track = Track::factory()->create(['code' => 'TVL', 'name' => 'TVL Track']);
        $section = Section::factory()->create(['grade_level' => 12, 'track_id' => $track->id]);

        // An ordinary name -> do8_tvl_sports_arts_other.
        $ordinary = Subject::factory()->create(['type' => 'elective', 'grade_level' => 12, 'name' => 'Computer Programming (Java)', 'track_id' => $track->id]);
        $ordinarySlug = $this->engine->resolveDo8GroupKey($section, $ordinary);
        $this->assertSame('do8_tvl_sports_arts_other', $ordinarySlug);

        // A "Work Immersion"-named one -> the *_work_immersion slug instead,
        // but both slugs carry the SAME 20/60/20 weights in DO 8's table --
        // "regardless of name" is genuinely true at the weight level even
        // though the slug itself still differs for CheckIntegrityCommand's
        // visibility listing.
        $workImmersion = Subject::factory()->create(['type' => 'elective', 'grade_level' => 12, 'name' => 'Work Immersion for TVL', 'track_id' => $track->id]);
        $workImmersionSlug = $this->engine->resolveDo8GroupKey($section, $workImmersion);
        $this->assertSame('do8_tvl_sports_arts_work_immersion', $workImmersionSlug);

        foreach ([$ordinarySlug, $workImmersionSlug] as $slug) {
            $weights = SubjectGroupWeight::resolve('do8_2015', $slug);
            $this->assertEquals(20.0, (float) $weights->ww_weight);
            $this->assertEquals(60.0, (float) $weights->pt_weight);
            $this->assertEquals(20.0, (float) $weights->ex_weight);
        }
    }

    public function test_computeGrade_actually_uses_the_resolved_do8_weights_end_to_end(): void
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027', 'track_id' => $track->id]);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 12, 'track_id' => null]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $ww = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'WW', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $ww->id, 'student_id' => $student->id, 'score' => 100]);

        $pt = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'PT', 'component' => 'performance_task', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $pt->id, 'student_id' => $student->id, 'score' => 0]);

        $ex = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'EX', 'component' => 'examination', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $ex->id, 'student_id' => $student->id, 'score' => 0]);

        $result = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertTrue($result['complete']);
        // WW 100%*25 + PT 0%*50 + EX 0%*25 = 25.0 -- do8_core's WW weight, proving
        // resolveDo8GroupKey() is actually wired into computeGrade(), not just callable standalone.
        $this->assertEquals(25.0, $result['computed_grade']);
    }
}
