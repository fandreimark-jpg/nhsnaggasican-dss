<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradingEngine;
use App\Services\RiskFeatureExtractor;
use App\Services\SubjectOfferingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsEcrFixture;
use Tests\TestCase;

/**
 * BLANK IS NOT ZERO — followed through the entire chain in one test file,
 * because this rule is only worth anything if it survives every hop:
 *
 *     prescribed ECR cell
 *        -> EcrReaderService parsing
 *        -> preview (dry run)
 *        -> assessment_scores rows
 *        -> GradingEngine computation
 *        -> RiskFeatureExtractor / the ML payload
 *
 * A learner whose cell is EMPTY has not been assessed. A learner who
 * scored 0 has been assessed and got nothing right. Collapsing the two
 * turns an administrative gap into an academic failure, and it does so
 * invisibly — the learner simply appears to be failing.
 *
 * The chain is tested end to end rather than per-layer on purpose: each
 * individual layer has looked correct before while the rule still broke at
 * a boundary between two of them.
 */
class EcrBlankIsNotZeroEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEcrFixture;

    private User $adviser;
    private Section $section;
    private Subject $subject;
    private Student $blankLearner;
    private Student $zeroLearner;
    private Student $scoredLearner;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create([
            'name' => 'Shakespeare', 'grade_level' => 11, 'school_year' => '2026-2027',
            'curriculum' => 'sshs', 'adviser_id' => $this->adviser->id,
        ]);
        $this->subject = Subject::factory()->create([
            'name' => 'General Mathematics', 'type' => 'core',
            'grade_level' => 11, 'subject_group' => 'core_academic',
        ]);
        // Core subject, taught every term (factory default) — it applies to
        // the section automatically under the subject configuration.

        $this->blankLearner = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000001',
            'last_name' => 'Blank', 'first_name' => 'Bea', 'gender' => 'female',
        ]);
        $this->zeroLearner = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000002',
            'last_name' => 'Zero', 'first_name' => 'Zack', 'gender' => 'female',
        ]);
        $this->scoredLearner = Student::factory()->create([
            'section_id' => $this->section->id, 'lrn' => '110000000003',
            'last_name' => 'Scored', 'first_name' => 'Sam', 'gender' => 'female',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * One Written Work column (D). Row order follows INPUT DATA's female
     * block: Blank, Zero, Scored.
     *
     *   Blank  -> cell left EMPTY   (never assessed)
     *   Zero   -> cell holds 0      (assessed, scored nothing)
     *   Scored -> cell holds 18
     */
    private function uploadEcr(): void
    {
        $path = $this->buildFilledEcrCopy(
            gradeLevel: '11',
            sectionName: 'Shakespeare',
            courseTitle: 'General Mathematics',
            roster: [
                ['lrn' => '110000000001', 'name' => 'Blank, Bea', 'gender' => 'female'],
                ['lrn' => '110000000002', 'name' => 'Zero, Zack', 'gender' => 'female'],
                ['lrn' => '110000000003', 'name' => 'Scored, Sam', 'gender' => 'female'],
            ],
            scores: ['D' => [
                $this->femaleTermRow(0) => null,  // BLANK — never written
                $this->femaleTermRow(1) => 0,     // a real recorded zero
                $this->femaleTermRow(2) => 18,
            ]],
            maxScores: ['D' => 20],
            schoolYearStart: '2026',
        );
        $this->tempFiles[] = $path;

        $detect = $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $this->subject->id, 'grading_period' => 1,
            'file' => new UploadedFile($path, 'ecr.xlsx', null, null, true),
        ]);
        $detect->assertOk();

        $stored = $detect->viewData('storedFilename');
        $columns = collect($detect->viewData('columns'))
            ->map(fn($c) => [
                'name' => $c['name'], 'component' => 'written_work',
                'max_score' => $c['max_score'] ?? 20,
            ])->values()->all();

        $this->actingAs($this->adviser)->post('/adviser/assessments/import', [
            'subject_id' => $this->subject->id, 'grading_period' => 1,
            'stored_filename' => $stored, 'columns' => $columns,
        ])->assertRedirect();
    }

    public function test_a_blank_cell_writes_no_score_row_while_a_zero_writes_a_real_zero(): void
    {
        $this->uploadEcr();

        $assessment = Assessment::where('section_id', $this->section->id)->firstOrFail();

        // The learner nobody assessed has NO row at all. "Missing" is the
        // absence of a record, not a record holding 0.
        $this->assertDatabaseMissing('assessment_scores', [
            'assessment_id' => $assessment->id,
            'student_id'    => $this->blankLearner->id,
        ]);

        // The learner who scored nothing HAS a row, holding 0.
        $this->assertDatabaseHas('assessment_scores', [
            'assessment_id' => $assessment->id,
            'student_id'    => $this->zeroLearner->id,
            'score'         => 0,
        ]);

        $this->assertDatabaseHas('assessment_scores', [
            'assessment_id' => $assessment->id,
            'student_id'    => $this->scoredLearner->id,
            'score'         => 18,
        ]);

        $this->assertSame(2, AssessmentScore::where('assessment_id', $assessment->id)->count());
    }

    public function test_grading_treats_the_blank_learner_as_incomplete_and_the_zero_learner_as_scored(): void
    {
        $this->uploadEcr();

        $engine = new GradingEngine();

        $blank = $engine->computeGrade($this->blankLearner, $this->subject, $this->section, 1, '2026-2027');
        $zero  = $engine->computeGrade($this->zeroLearner, $this->subject, $this->section, 1, '2026-2027');

        // No evidence at all -> the component has no percentage, and the
        // grade is reported INCOMPLETE rather than computed from nothing.
        // "Missing means incomplete, not zero" (CLAUDE.md design decision 1).
        $this->assertNull($blank['components']['written_work']);
        $this->assertFalse($blank['complete']);
        $this->assertNull($blank['computed_grade']);

        // A recorded zero IS evidence — 0 out of 20 is 0%, a real figure.
        $this->assertNotNull($zero['components']['written_work']);
        $this->assertEqualsWithDelta(0.0, $zero['components']['written_work'], 0.01);
    }

    public function test_the_ml_payload_keeps_blank_as_null_and_counts_it_as_a_missing_assessment(): void
    {
        $this->uploadEcr();

        $features = new RiskFeatureExtractor();

        $blank = $features->extract($this->blankLearner, $this->section, 1, '2026-2027', 0.0);
        $zero  = $features->extract($this->zeroLearner, $this->section, 1, '2026-2027', 0.0);

        // The blank learner has no Written Work evidence: null, never 0.0.
        $this->assertNull($blank['ww_mean']);
        $this->assertSame(1, $blank['missing_assessment_count']);

        // The zero learner has evidence worth 0% — a real number, and
        // nothing missing.
        $this->assertNotNull($zero['ww_mean']);
        $this->assertEqualsWithDelta(0.0, $zero['ww_mean'], 0.01);
        $this->assertSame(0, $zero['missing_assessment_count']);

        // The two must be distinguishable in the payload. If they ever
        // collapse to the same value, the model can no longer tell
        // "nobody assessed this learner" from "this learner failed".
        $this->assertNotSame($blank['ww_mean'], $zero['ww_mean']);
    }

    public function test_a_no_examination_profile_reports_a_blank_exam_not_a_zero_exam(): void
    {
        // Research 1 has ex_weight NULL in the DepEd catalog — no
        // Examination component exists at all. That is not the same
        // academic fact as sitting an exam and scoring 0, and the payload
        // must not report it as one.
        $research = Subject::factory()->create([
            'name' => 'Research 1', 'type' => 'elective',
            'grade_level' => 11, 'subject_group' => 'research_innovation',
        ]);
        (new SubjectOfferingService())->chooseElective($this->section, $research);

        $features = (new RiskFeatureExtractor())
            ->extract($this->scoredLearner, $this->section, 1, '2026-2027', 80.0);

        $this->assertNull($features['exam_mean'], 'a subject with no Examination component must report a blank exam_mean');
        $this->assertNotSame(0, $features['exam_mean']);
        $this->assertNotSame(0.0, $features['exam_mean']);
    }

    public function test_the_preview_step_reports_a_blank_cell_as_skipped_rather_than_as_a_zero_row(): void
    {
        $path = $this->buildFilledEcrCopy(
            gradeLevel: '11',
            sectionName: 'Shakespeare',
            courseTitle: 'General Mathematics',
            roster: [
                ['lrn' => '110000000001', 'name' => 'Blank, Bea', 'gender' => 'female'],
                ['lrn' => '110000000002', 'name' => 'Zero, Zack', 'gender' => 'female'],
            ],
            scores: ['D' => [
                $this->femaleTermRow(0) => null,
                $this->femaleTermRow(1) => 0,
            ]],
            maxScores: ['D' => 20],
            schoolYearStart: '2026',
        );
        $this->tempFiles[] = $path;

        $detect = $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $this->subject->id, 'grading_period' => 1,
            'file' => new UploadedFile($path, 'ecr.xlsx', null, null, true),
        ]);
        $detect->assertOk();

        $preview = $this->actingAs($this->adviser)->post('/adviser/assessments/preview', [
            'subject_id' => $this->subject->id, 'grading_period' => 1,
            'stored_filename' => $detect->viewData('storedFilename'),
            'columns' => [[
                'name' => collect($detect->viewData('columns'))->first()['name'],
                'component' => 'written_work', 'max_score' => 20,
            ]],
        ]);

        $preview->assertOk();
        // Both learners are matched; only the one with a real cell value
        // produces a score to write.
        $data = $preview->viewData('preview');
        $this->assertSame(2, $data['matched_rows']);
        // Only the learner with a real cell value produces a cell to
        // write. The blank cell is not counted as a valid 0, and it is not
        // counted as an invalid entry either — it is simply absent.
        $this->assertSame(1, $data['total_valid_cells']);
        $this->assertSame(0, $data['total_invalid_cells']);
    }
}
