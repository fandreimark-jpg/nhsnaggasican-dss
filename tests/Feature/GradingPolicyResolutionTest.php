<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\Track;
use App\Models\User;
use App\Services\AssessmentUploadService;
use App\Services\EcrReaderService;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\BuildsEcrFixture;
use Tests\TestCase;

/**
 * "SSHS ECR grading correction" pass (2026-09-20) — the prescribed
 * Strengthened SHS E-Class Record (tests/Fixtures/SSHS-E-Class-Record-
 * SY-2026-2027.xlsx) is the primary grading-format reference, and this file
 * reads it as the ORACLE rather than restating its numbers:
 *
 *  - INSTRUCTIONS!E99:I108 is the "Weight of the Components for the SSHS"
 *    table; the seeded do015_2026 subject_group_weights rows must equal it.
 *  - INPUT DATA!F24 validates "11,12": the instrument covers both grade
 *    levels, and the Term sheets' weight cells (D12/Q12/AD12) XLOOKUP the
 *    HELPER catalog by cluster + course title — grade level never enters
 *    the weight. So in SY 2026-2027 an unset-curriculum section of EITHER
 *    grade level grades under DO 015 (TransmutationService::schemeFor()).
 *  - A section whose curriculum is EXPLICITLY k12_2013 keeps DO 8, s. 2015
 *    (GradingEngine::resolveDo8GroupKey()) — legacy preserved, never
 *    inferred from a grade level.
 *
 * GradingEngine::resolveWeightProfile() is the one resolver every consumer
 * reads (computeGrade(), Adviser Assessments, Encode Grades, the SSHS and
 * Grade 12 ECR weight checks, the Principal dashboard trend, Admin >
 * Subjects via resolveSubjectProfile()); the tests below hold each to it.
 */
class GradingPolicyResolutionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEcrFixture;

    private GradingEngine $engine;
    private Track $acad;
    private array $tempFiles = [];

    /** The workbook's table, keyed by the app's subject_group slug for the same category. */
    private const WORKBOOK_ROW_FOR_GROUP = [
        'core_academic'        => 99,  // Core
        'academic_other'       => 101, // Academic Electives — All Other Electives
        'research_innovation'  => 102, // Research and Design and Innovation
        'arts_sports_wellness' => 103, // Arts, Sports, Health and Wellness Electives
        'field_exposure'       => 105, // Field Experience (EX cell reads "*15%")
        'techpro'              => 107, // TechPro Electives — All Other Electives
        'work_immersion'       => 108, // Work Immersion (EX cell reads "---")
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new GradingEngine();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        $this->acad = Track::factory()->create(['code' => 'ACAD', 'name' => 'Academic Track']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) { @unlink($f); }
        parent::tearDown();
    }

    private function section(int $grade, ?string $curriculum = null, ?Track $track = null): Section
    {
        return Section::factory()->create([
            'grade_level' => $grade, 'school_year' => '2026-2027', 'track_id' => ($track ?? $this->acad)->id,
            'curriculum' => $curriculum,
        ]);
    }

    private function weights(array $profile): array
    {
        return [$profile['ww_weight'], $profile['pt_weight'], $profile['ex_weight'], $profile['group_key']];
    }

    /** @return array<string, array{0: float, 1: float, 2: ?float}> slug => [ww, pt, ex] read from the workbook */
    private function workbookTable(): array
    {
        $file = base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['INSTRUCTIONS']);
        $sheet = $reader->load($file)->getSheetByName('INSTRUCTIONS');

        $pct = function ($v): ?float {
            if ($v === null || $v === '' || $v === '---') return null;
            if (is_string($v)) { $v = (float) str_replace(['*', '%'], '', $v) / 100; }
            return round((float) $v * 100, 2);
        };

        $table = [];
        foreach (self::WORKBOOK_ROW_FOR_GROUP as $slug => $row) {
            $table[$slug] = [$pct($sheet->getCell("G{$row}")->getValue()), $pct($sheet->getCell("H{$row}")->getValue()), $pct($sheet->getCell("I{$row}")->getValue())];
        }

        return $table;
    }

    // ------------------------------------------------------------------
    // The workbook is the oracle
    // ------------------------------------------------------------------

    public function test_the_workbook_states_the_expected_table_and_accepts_both_grade_levels(): void
    {
        $table = $this->workbookTable();

        $this->assertSame([20.0, 50.0, 30.0], $table['core_academic']);
        $this->assertSame([20.0, 50.0, 30.0], $table['academic_other']);
        $this->assertSame([40.0, 60.0, null], $table['research_innovation']);
        $this->assertSame([20.0, 60.0, 20.0], $table['arts_sports_wellness']);
        $this->assertSame([15.0, 70.0, 15.0], $table['field_exposure']);
        $this->assertSame([15.0, 65.0, 20.0], $table['techpro']);
        $this->assertSame([20.0, 80.0, null], $table['work_immersion']);

        foreach ($table as $slug => [$ww, $pt, $ex]) {
            $this->assertEqualsWithDelta(100.0, $ww + $pt + ($ex ?? 0), 0.001, "{$slug} totals 100%");
        }

        $file = base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setLoadSheetsOnly(['INPUT DATA']); // data validations need a full load
        $input = $reader->load($file)->getSheetByName('INPUT DATA');
        $gradeLevelRule = collect($input->getDataValidationCollection())->first(fn($dv) => str_contains($dv->getSqref(), 'F24'));
        $this->assertNotNull($gradeLevelRule, 'INPUT DATA!F24 (GRADE LEVEL) carries a list validation.');
        $this->assertSame('"11,12"', $gradeLevelRule->getFormula1(), 'The prescribed SSHS ECR accepts Grade 11 AND Grade 12.');
    }

    public function test_the_seeded_do015_rows_equal_the_workbook_table(): void
    {
        foreach ($this->workbookTable() as $slug => [$ww, $pt, $ex]) {
            $row = SubjectGroupWeight::resolve('do015_2026', $slug);
            $this->assertSame([$ww, $pt, $ex], [(float) $row->ww_weight, (float) $row->pt_weight, $row->ex_weight === null ? null : (float) $row->ex_weight], $slug);
        }
    }

    // ------------------------------------------------------------------
    // Every category resolves identically for Grade 11 and Grade 12
    // ------------------------------------------------------------------

    public function test_every_workbook_category_resolves_the_same_for_grade_11_and_grade_12(): void
    {
        foreach ($this->workbookTable() as $slug => [$ww, $pt, $ex]) {
            $type = $slug === 'core_academic' ? 'core' : 'elective';
            foreach ([11, 12] as $grade) {
                $subject = Subject::factory()->create(['name' => "{$slug} G{$grade}", 'type' => $type, 'grade_level' => $grade, 'subject_group' => $slug, 'track_id' => $type === 'elective' ? $this->acad->id : null]);

                $bySection = $this->engine->resolveWeightProfile($this->section($grade), $subject);
                $bySubject = $this->engine->resolveSubjectProfile($subject);

                $this->assertSame('do015_2026', $bySection['scheme'], "{$slug} G{$grade} scheme");
                $this->assertSame([$ww, $pt, $ex, $slug], $this->weights($bySection), "{$slug} G{$grade} by section");
                $this->assertTrue($bySubject['resolved'], "{$slug} G{$grade} resolves without a section");
                $this->assertSame($this->weights($bySection), $this->weights($bySubject['profile']), "{$slug} G{$grade} by subject");
            }
        }
    }

    public function test_an_explicit_2013_curriculum_section_keeps_do8_and_a_subject_then_shows_both_contexts(): void
    {
        $admin = User::factory()->admin()->create();
        $core = Subject::factory()->create(['name' => 'Physical Education and Health', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        $elective = Subject::factory()->create(['name' => 'Business Finance', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'academic_other', 'track_id' => $this->acad->id]);
        foreach ([$core, $elective] as $s) { $s->syncTerms([1, 2, 3]); }

        $legacy = $this->section(12, 'k12_2013');
        $this->assertSame([25.0, 50.0, 25.0, 'do8_core'], $this->weights($this->engine->resolveWeightProfile($legacy, $core)));
        $this->assertSame([25.0, 45.0, 30.0, 'do8_academic_other'], $this->weights($this->engine->resolveWeightProfile($legacy, $elective)));

        $sshs = $this->section(12, 'sshs');
        $this->assertSame([20.0, 50.0, 30.0, 'core_academic'], $this->weights($this->engine->resolveWeightProfile($sshs, $core)));
        $this->assertSame([20.0, 50.0, 30.0, 'academic_other'], $this->weights($this->engine->resolveWeightProfile($sshs, $elective)));

        // Both curricula are in use at Grade 12, so no single figure is claimed.
        $resolution = $this->engine->resolveSubjectProfile($elective);
        $this->assertFalse($resolution['resolved']);
        $this->assertEqualsCanonicalizing(['k12_2013', 'sshs'], array_column($resolution['variants'], 'curriculum'));

        $html = $this->actingAs($admin)->get(route('admin.subjects'))->assertOk()->getContent();
        $this->assertStringContainsString('Resolved by section context', $html);
        $this->assertStringContainsString('2013 curriculum', $html);
        $this->assertStringContainsString('Strengthened SHS', $html);
    }

    // ------------------------------------------------------------------
    // Business Finance / Philippine Politics and Governance — structured
    // configuration, not names, decide the profile
    // ------------------------------------------------------------------

    public function test_business_finance_and_philippine_politics_resolve_by_their_configured_groups(): void
    {
        $abm = Specialization::factory()->create(['track_id' => $this->acad->id, 'code' => 'ABM']);
        $humss = Specialization::factory()->create(['track_id' => $this->acad->id, 'code' => 'HUMSS']);
        $bf = Subject::factory()->create(['name' => 'Business Finance', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'academic_other', 'track_id' => $this->acad->id, 'specialization_id' => $abm->id]);
        $ppg = Subject::factory()->create(['name' => 'Philippine Politics and Governance', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'arts_sports_wellness', 'track_id' => $this->acad->id, 'specialization_id' => $humss->id]);

        $this->assertSame([20.0, 50.0, 30.0, 'academic_other'], $this->weights($this->engine->resolveSubjectProfile($bf)['profile']));
        $this->assertSame([20.0, 60.0, 20.0, 'arts_sports_wellness'], $this->weights($this->engine->resolveSubjectProfile($ppg)['profile']));

        // The name is not read: renaming changes nothing, re-grouping does.
        $ppg->update(['name' => 'Something Else']);
        $this->assertSame('arts_sports_wellness', (new GradingEngine())->resolveSubjectProfile($ppg->fresh())['profile']['group_key']);
        $ppg->update(['subject_group' => 'academic_other']);
        $this->assertSame([20.0, 50.0, 30.0, 'academic_other'], $this->weights((new GradingEngine())->resolveSubjectProfile($ppg->fresh())['profile']));
    }

    // ------------------------------------------------------------------
    // Admin > Subjects shows the engine's figures for both grade levels
    // ------------------------------------------------------------------

    public function test_the_subjects_table_shows_the_resolved_figures_for_grade_11_and_grade_12(): void
    {
        $admin = User::factory()->admin()->create();
        $g11 = Subject::factory()->create(['name' => 'Effective Communication', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $g12 = Subject::factory()->create(['name' => 'Business Finance', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'academic_other', 'track_id' => $this->acad->id]);
        $wi = Subject::factory()->create(['name' => 'Work Immersion', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'work_immersion', 'track_id' => $this->acad->id]);
        $rd = Subject::factory()->create(['name' => 'Research 1', 'type' => 'elective', 'grade_level' => 11, 'subject_group' => 'research_innovation', 'track_id' => $this->acad->id]);
        foreach ([$g11, $g12, $wi, $rd] as $s) { $s->syncTerms([1, 2, 3]); }

        $html = $this->actingAs($admin)->get(route('admin.subjects'))->assertOk()->getContent();
        $this->assertStringNotContainsString('by section track', $html);

        $row = fn(string $name) => (function () use ($html, $name) {
            preg_match('~<tr class="subject-row">(?:(?!<tr class="subject-row">).)*?' . preg_quote($name, '~') . '.*?</tr>~s', $html, $m);
            return trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('>', '> ', $m[0] ?? ''))));
        })();

        $this->assertStringContainsString('Written Work 20% Performance Task 50% Examination 30%', $row('Effective Communication'));
        $this->assertStringContainsString('Written Work 20% Performance Task 50% Examination 30%', $row('Business Finance'));
        $this->assertStringContainsString('Written Work 20% Performance Task 80% Examination none', $row('Work Immersion'));
        $this->assertStringContainsString('Written Work 40% Performance Task 60% Examination none', $row('Research 1'));
        $this->assertStringContainsString('DO 015, s. 2026', $row('Business Finance'));
    }

    public function test_an_unclassified_subject_fails_safely_as_not_configured(): void
    {
        $admin = User::factory()->admin()->create();
        $subject = Subject::factory()->create(['name' => 'Mystery Subject', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => null, 'track_id' => $this->acad->id]);
        $subject->syncTerms([1]);

        $this->assertStringContainsString('Not configured', $this->actingAs($admin)->get(route('admin.subjects'))->assertOk()->getContent());

        $this->expectException(\RuntimeException::class);
        $this->engine->resolveSubjectProfile($subject);
    }

    // ------------------------------------------------------------------
    // Manual calculation — the formula the workbook states, on the engine
    // ------------------------------------------------------------------

    /** @return array{0: Student, 1: Subject, 2: Section, 3: User} */
    private function evidence(int $grade, string $group, string $type, array $components): array
    {
        $adviser = User::factory()->create();
        $spec = Specialization::factory()->create(['track_id' => $this->acad->id, 'code' => 'SPEC' . rand(1000, 9999)]);
        $section = Section::factory()->create(['grade_level' => $grade, 'school_year' => '2026-2027', 'track_id' => $this->acad->id, 'specialization_id' => $spec->id, 'adviser_id' => $adviser->id, 'curriculum' => null]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['name' => "{$group} evidence G{$grade}", 'type' => $type, 'grade_level' => $grade, 'subject_group' => $group,
            'track_id' => $type === 'elective' ? $this->acad->id : null, 'specialization_id' => $type === 'elective' ? $spec->id : null]);
        $subject->syncTerms([1, 2, 3]);
        $this->assertTrue(Subject::forSection($section, 1)->pluck('id')->contains($subject->id));

        foreach ($components as $component => [$earned, $max]) {
            $a = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'name' => $component, 'component' => $component, 'max_score' => $max]);
            AssessmentScore::factory()->create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        return [$student, $subject, $section, $adviser];
    }

    public function test_a_grade_12_academic_elective_initial_grade_is_ww20_pt50_ex30_end_to_end(): void
    {
        // 90 x .20 + 80 x .50 + 85 x .30 = 18 + 40 + 25.5 = 83.5
        [$student, $subject, $section, $adviser] = $this->evidence(12, 'academic_other', 'elective', [
            'written_work' => [90, 100], 'performance_task' => [80, 100], 'examination' => [85, 100],
        ]);

        $grade = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');
        $this->assertTrue($grade['complete']);
        $this->assertEqualsWithDelta(83.5, $grade['computed_grade'], 0.01);
        $this->assertSame([20.0, 50.0, 30.0, 'academic_other'], $this->weights($this->engine->resolveSubjectProfile($subject)['profile']));

        // Adviser Assessments (evidence analysis) and Encode Grades (verify) both read the engine.
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $this->actingAs($adviser)->get('/adviser/assessments?subject_id=' . $subject->id . '&period=1')->assertOk()->assertSee('83.5');
        $this->actingAs($adviser)->postJson('/adviser/grades/verify', ['student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1])
            ->assertOk()->assertJsonPath('computed_grade', 83.5);
    }

    public function test_work_immersion_and_research_have_no_examination_component_and_still_compute(): void
    {
        // Work Immersion 20/80/—: 90 x .20 + 80 x .80 = 18 + 64 = 82
        [$student, $subject, $section] = $this->evidence(12, 'work_immersion', 'elective', ['written_work' => [90, 100], 'performance_task' => [80, 100]]);
        $grade = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');
        $this->assertTrue($grade['complete'], 'No examination is required when the category has none.');
        $this->assertEqualsWithDelta(82.0, $grade['computed_grade'], 0.01);

        // Research and Design and Innovation 40/60/—: 70 x .40 + 95 x .60 = 28 + 57 = 85
        [$student, $subject, $section] = $this->evidence(11, 'research_innovation', 'elective', ['written_work' => [70, 100], 'performance_task' => [95, 100]]);
        $grade = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');
        $this->assertTrue($grade['complete']);
        $this->assertEqualsWithDelta(85.0, $grade['computed_grade'], 0.01);
    }

    // ------------------------------------------------------------------
    // ECR consistency — the workbook's own category vs the configured subject
    // ------------------------------------------------------------------

    public function test_a_prescribed_ecr_whose_catalog_category_matches_the_configured_group_passes_the_weight_check(): void
    {
        $subject = Subject::factory()->create(['name' => 'Basic Calculus', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'academic_other', 'track_id' => $this->acad->id]);
        $path = $this->buildFilledEcrCopy(gradeLevel: '12', subjectCategory: 'SSHS - ACADEMIC', cluster: 'STEM', courseTitle: 'Basic Calculus');
        $this->tempFiles[] = $path;

        $this->assertNull(app(EcrReaderService::class)->checkWeightMismatch($path, $subject), 'STEM catalog row 20/50/30 agrees with academic_other.');
    }

    public function test_a_prescribed_ecr_whose_catalog_category_contradicts_the_configured_group_is_refused_and_rewrites_nothing(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['name' => 'Rizal', 'grade_level' => 12, 'school_year' => '2026-2027', 'curriculum' => null, 'track_id' => $this->acad->id, 'adviser_id' => $adviser->id]);
        // "Philippine Governance (Philippine Politics and Governance)" is an
        // ARTS, SOCIAL SCIENCES, AND HUMANITIES catalog row at 20/60/20; the
        // subject is (mis)configured as academic_other (20/50/30).
        $subject = Subject::factory()->create(['name' => 'Philippine Governance (Philippine Politics and Governance)', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'academic_other', 'track_id' => $this->acad->id]);
        $subject->syncTerms([1, 2, 3]);
        $this->assertTrue(Subject::forSection($section, 1)->pluck('id')->contains($subject->id), 'Applies by track (no strand on either side).');
        Student::factory()->create(['section_id' => $section->id, 'lrn' => '120000000001', 'last_name' => 'Agbayani', 'first_name' => 'Rhea Mae', 'gender' => 'female']);

        $path = $this->buildFilledEcrCopy(
            gradeLevel: '12', sectionName: 'Rizal', subjectCategory: 'SSHS - ACADEMIC',
            cluster: 'ARTS, SOCIAL SCIENCES, AND HUMANITIES', courseTitle: 'Philippine Governance (Philippine Politics and Governance)',
            roster: [['lrn' => '120000000001', 'name' => 'Agbayani, Rhea Mae', 'gender' => 'female']],
            scores: ['D' => [$this->femaleTermRow(0) => 18]], maxScores: ['D' => 20], schoolYearStart: '2026',
        );
        $this->tempFiles[] = $path;

        $message = app(EcrReaderService::class)->checkWeightMismatch($path, $subject);
        $this->assertNotNull($message);
        $this->assertStringContainsString('20/60/20', $message);
        $this->assertStringContainsString('20/50/30', $message);
        $this->assertTrue(app(AssessmentUploadService::class)->ecrWeightMismatchBlocks($message));

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $subject->id, 'grading_period' => 1,
            'file' => new UploadedFile($path, 'ecr.xlsx', null, null, true),
        ]);
        $response->assertRedirect('/adviser/assessments?period=1&subject_id=' . $subject->id);
        $response->assertSessionHas('error', fn($m) => str_contains($m, 'refused') && str_contains($m, '20/60/20'));
        $this->assertSame('academic_other', $subject->fresh()->subject_group, 'The ECR never rewrites master data.');
        $this->assertCount(0, \Illuminate\Support\Facades\Storage::disk('local')->files('temp_assessment_uploads'));
    }
}
