<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\DepedSubjectCatalog;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\EcrSubjectTermResolver;
use App\Services\SubjectOfferingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsEcrFixture;
use Tests\TestCase;

/**
 * The PRESCRIBED E-Class Record is the external academic format, and it is
 * validated against the DSS selection BEFORE anything is imported.
 *
 * Every fixture here is a real copy of the checked-in blank DepEd
 * instrument (tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx) with
 * plain values written into the actual cells the real workbook uses —
 * never a hand-built spreadsheet of our own shape. That is the point of
 * this pass: the workbook's structure is not ours to invent, so a test
 * that invented one would be testing the wrong thing.
 *
 * The distinction these tests pin, which is the whole design:
 *
 *   the workbook SAYS SOMETHING DIFFERENT  -> refuse the upload
 *   the workbook SAYS NOTHING (blank cell) -> import, but say so
 */
class PrescribedEcrMetadataValidationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEcrFixture;

    private User $adviser;
    private Section $shakespeare;
    private Section $curie;
    private Subject $genMath;
    private Subject $basicCalc;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $other = User::factory()->create(['role' => 'adviser']);

        $this->shakespeare = Section::factory()->create([
            'name' => 'Shakespeare', 'grade_level' => 11,
            'school_year' => '2026-2027', 'curriculum' => 'sshs',
            'adviser_id' => $this->adviser->id,
        ]);
        $this->curie = Section::factory()->create([
            'name' => 'Curie', 'grade_level' => 11,
            'school_year' => '2026-2027', 'curriculum' => 'sshs',
            'adviser_id' => $other->id,
        ]);

        // General Mathematics is a real CORE row in the seeded DepEd
        // catalog: 3 terms at Grade 11. Basic Calculus is a real STEM row.
        $this->genMath = Subject::factory()->create([
            'name' => 'General Mathematics', 'type' => 'core',
            'grade_level' => 11, 'subject_group' => 'core_academic',
        ]);
        $this->basicCalc = Subject::factory()->create([
            'name' => 'Basic Calculus', 'type' => 'elective',
            'grade_level' => 11, 'subject_group' => 'academic_other',
        ]);

        // General Mathematics is core: it applies to every Grade 11 section
        // in every term it is taught (all three, the factory default).
        // Basic Calculus is an elective NOT chosen for Shakespeare.

        Student::factory()->create([
            'section_id' => $this->shakespeare->id, 'lrn' => '110000000001',
            'last_name' => 'Agbayani', 'first_name' => 'Rhea Mae', 'gender' => 'female',
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
     * A prescribed ECR naming a class, with one Written Work column
     * scored, ready to POST at /adviser/assessments/detect.
     */
    private function ecr(array $overrides = []): string
    {
        $path = $this->buildFilledEcrCopy(
            gradeLevel: $overrides['grade'] ?? '11',
            sectionName: $overrides['section'] ?? 'Shakespeare',
            subjectCategory: 'SSHS - CORE',
            cluster: $overrides['cluster'] ?? 'CORE',
            courseTitle: $overrides['subject'] ?? 'General Mathematics',
            roster: [['lrn' => '110000000001', 'name' => 'Agbayani, Rhea Mae', 'gender' => 'female']],
            scores: ['D' => [$this->femaleTermRow(0) => 18]],
            maxScores: ['D' => 20],
            gradingPeriod: $overrides['period'] ?? 1,
            schoolYearStart: array_key_exists('year', $overrides) ? $overrides['year'] : '2026',
            termBlockLabel: $overrides['block'] ?? null,
            otherElectiveTermsTaught: $overrides['other_terms'] ?? null,
        );

        $this->tempFiles[] = $path;

        return $path;
    }

    private function detect(string $path, ?Subject $subject = null, int $period = 1)
    {
        return $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => ($subject ?? $this->genMath)->id,
            'grading_period' => $period,
            'file'           => new UploadedFile($path, 'ecr.xlsx', null, null, true),
        ]);
    }

    // -----------------------------------------------------------------
    // Rejection — the workbook contradicts the selection
    // -----------------------------------------------------------------

    public function test_an_ecr_naming_a_different_section_is_rejected(): void
    {
        $response = $this->detect($this->ecr(['section' => 'Curie']));

        $response->assertRedirect();
        $response->assertSessionHas('error', fn($m) => str_contains($m, 'Curie')
            && str_contains($m, 'Shakespeare')
            && str_contains($m, 'nothing was imported'));

        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('assessment_scores', 0);
    }

    public function test_an_ecr_naming_a_different_subject_is_rejected(): void
    {
        $response = $this->detect($this->ecr(['subject' => 'Effective Communication']));

        $response->assertRedirect();
        $response->assertSessionHas('error', fn($m) => str_contains($m, 'Effective Communication')
            && str_contains($m, 'General Mathematics'));
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_an_ecr_naming_a_different_grade_level_is_rejected(): void
    {
        $response = $this->detect($this->ecr(['grade' => '12']));

        $response->assertRedirect();
        $response->assertSessionHas('error', fn($m) => str_contains($m, 'Grade 12')
            && str_contains($m, 'Grade 11'));
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_an_ecr_naming_a_different_school_year_is_rejected(): void
    {
        $response = $this->detect($this->ecr(['year' => '2024']));

        $response->assertRedirect();
        $response->assertSessionHas('error', fn($m) => str_contains($m, '2024-2025')
            && str_contains($m, '2026-2027'));
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_every_mismatch_is_named_at_once_rather_than_one_at_a_time(): void
    {
        $response = $this->detect($this->ecr(['section' => 'Curie', 'grade' => '12', 'year' => '2024']));

        $response->assertSessionHas('error', fn($m) => str_contains($m, 'Curie')
            && str_contains($m, 'Grade 12')
            && str_contains($m, '2024-2025'));
    }

    // -----------------------------------------------------------------
    // Acceptance — the workbook agrees, or says nothing
    // -----------------------------------------------------------------

    public function test_a_matching_ecr_is_accepted_and_reaches_the_verify_screen(): void
    {
        $response = $this->detect($this->ecr());

        $response->assertOk();
        $response->assertViewIs('adviser.assessments-verify');
        $response->assertSessionMissing('error');
    }

    public function test_a_workbook_with_blank_cover_cells_is_accepted_with_a_visible_note(): void
    {
        // The real instrument ships blank. "Cannot confirm" is not the
        // same finding as "contradicts", and blocking on it would refuse
        // legitimate uploads to guard against a conflict nobody showed.
        $path = $this->buildFilledEcrCopy(
            gradeLevel: '',
            sectionName: '',
            courseTitle: '',
            roster: [['lrn' => '110000000001', 'name' => 'Agbayani, Rhea Mae', 'gender' => 'female']],
            scores: ['D' => [$this->femaleTermRow(0) => 18]],
            maxScores: ['D' => 20],
            schoolYearStart: null,
        );
        $this->tempFiles[] = $path;

        $response = $this->detect($path);

        $response->assertOk();
        $response->assertViewIs('adviser.assessments-verify');
        $response->assertViewHas('metadataMismatch', fn($m) => $m !== null
            && str_contains($m, 'could not be fully verified')
            && str_contains($m, 'does not name a section'));
    }

    // -----------------------------------------------------------------
    // Term applicability, from the workbook's own TERMS AND UNITS block
    // -----------------------------------------------------------------

    public function test_a_three_term_catalog_subject_is_applicable_to_every_term(): void
    {
        $catalog = DepedSubjectCatalog::whereRaw('LOWER(course_title) = ?', ['general mathematics'])->first();
        $this->assertNotNull($catalog, 'the DepEd catalog must be seeded for this test to mean anything');
        $this->assertSame(3, (int) $catalog->g11_terms);

        $result = (new EcrSubjectTermResolver())->validate(
            $this->ecr(), $this->shakespeare, $this->genMath, 1
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame([1, 2, 3], $result['applicable_terms']);
        $this->assertSame(3, $result['terms_taught']);
        $this->assertStringContainsString('catalog', $result['terms_source']);
    }

    public function test_a_one_term_other_elective_is_rejected_for_a_term_it_does_not_run_in(): void
    {
        // OTHER ELECTIVE is the one case the teacher types the term count
        // and block directly — the order publishes no catalog row for it.
        AcademicTerm::where('school_year', '2026-2027')->where('term', 2)->update(['is_open' => true]);
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        // The Admin says it is taught in Term 2 and chose it for Shakespeare;
        // the workbook's own TERMS AND UNITS block says Term 1 only. The
        // file contradicts the selection, so the upload is refused.
        $elective = Subject::factory()->taughtIn([2])->create([
            'name' => 'Community Theatre Workshop', 'type' => 'elective',
            'grade_level' => 11, 'subject_group' => 'academic_other',
        ]);
        (new SubjectOfferingService())->chooseElective($this->shakespeare, $elective);

        $path = $this->ecr([
            'subject'     => 'Community Theatre Workshop',
            'cluster'     => 'OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM',
            'period'      => 2,
            'other_terms' => 1,
            'block'       => 'FIRST TERM',   // runs in Term 1 only
        ]);

        $response = $this->detect($path, $elective, 2);

        $response->assertRedirect();
        $response->assertSessionHas('error', fn($m) => str_contains($m, 'does not run in Term 2')
            && str_contains($m, 'Term 1'));
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_a_two_term_other_elective_covers_its_block_and_the_term_after_it(): void
    {
        $elective = Subject::factory()->create([
            'name' => 'Community Theatre Workshop', 'type' => 'elective',
            'grade_level' => 11, 'subject_group' => 'academic_other',
        ]);

        $result = (new EcrSubjectTermResolver())->validate(
            $this->ecr([
                'subject' => 'Community Theatre Workshop',
                'cluster' => 'OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM',
                'other_terms' => 2,
                'block' => 'SECOND TERM',
            ]),
            $this->shakespeare,
            $elective,
            2
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame([2, 3], $result['applicable_terms']);
    }

    public function test_a_two_term_elective_starting_in_term_3_is_refused_as_the_workbook_itself_says(): void
    {
        // HELPER!G34 in the real instrument: "Invalid: Cannot start in
        // Term 3". The workbook labels this combination invalid, so a file
        // carrying it is malformed rather than merely unmatched.
        $elective = Subject::factory()->create([
            'name' => 'Community Theatre Workshop', 'type' => 'elective',
            'grade_level' => 11, 'subject_group' => 'academic_other',
        ]);

        $result = (new EcrSubjectTermResolver())->validate(
            $this->ecr([
                'subject' => 'Community Theatre Workshop',
                'cluster' => 'OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM',
                'other_terms' => 2,
                'block' => 'THIRD TERM',
                'period' => 3,
            ]),
            $this->shakespeare,
            $elective,
            3
        );

        $this->assertSame([], $result['applicable_terms']);
        $this->assertNotSame([], $result['errors']);
    }

    public function test_an_unknown_term_count_is_a_warning_not_a_block(): void
    {
        // A subject with no catalog row and no teacher-typed count: the
        // workbook genuinely does not say. Refusing would block a
        // legitimate upload over missing evidence rather than conflicting
        // evidence.
        $unlisted = Subject::factory()->create([
            'name' => 'Not In The DepEd Catalog', 'type' => 'elective',
            'grade_level' => 11, 'subject_group' => 'academic_other',
        ]);

        $result = (new EcrSubjectTermResolver())->validate(
            $this->ecr(['subject' => 'Not In The DepEd Catalog']),
            $this->shakespeare,
            $unlisted,
            1
        );

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['applicable_terms']);
        $this->assertTrue(collect($result['warnings'])->contains(
            fn($w) => str_contains($w, 'could not be determined')
        ));
    }

    // -----------------------------------------------------------------
    // Backend enforcement — the refusal cannot be replayed around
    // -----------------------------------------------------------------

    public function test_import_re_checks_the_workbook_and_refuses_a_wrong_section_file(): void
    {
        // detect() already refused this file, so a crafted request that
        // jumps straight to import() is the real attack shape. The stored
        // file is re-read and re-checked there.
        $path = $this->ecr(['section' => 'Curie']);
        $stored = \Illuminate\Support\Str::uuid() . '.xlsx';
        \Illuminate\Support\Facades\Storage::disk('local')
            ->put('temp_assessment_uploads/' . $stored, file_get_contents($path));

        $response = $this->actingAs($this->adviser)->post('/adviser/assessments/import', [
            'subject_id'      => $this->genMath->id,
            'grading_period'  => 1,
            'stored_filename' => $stored,
            'columns'         => [[
                'name' => 'Written Work 1', 'component' => 'written_work', 'max_score' => 20,
            ]],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', fn($m) => str_contains($m, 'Curie'));
        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('assessment_scores', 0);
    }

    public function test_preview_re_checks_the_workbook_too(): void
    {
        $path = $this->ecr(['section' => 'Curie']);
        $stored = \Illuminate\Support\Str::uuid() . '.xlsx';
        \Illuminate\Support\Facades\Storage::disk('local')
            ->put('temp_assessment_uploads/' . $stored, file_get_contents($path));

        $response = $this->actingAs($this->adviser)->post('/adviser/assessments/preview', [
            'subject_id'      => $this->genMath->id,
            'grading_period'  => 1,
            'stored_filename' => $stored,
            'columns'         => [[
                'name' => 'Written Work 1', 'component' => 'written_work', 'max_score' => 20,
            ]],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', fn($m) => str_contains($m, 'Curie'));
    }

    // -----------------------------------------------------------------
    // An upload validates against the configuration and never writes it
    // ("Subject applicability" refactor — the former ECR synchronization
    // of section_subjects is gone)
    // -----------------------------------------------------------------

    public function test_a_valid_upload_changes_no_subject_or_section_configuration(): void
    {
        AcademicTerm::where('school_year', '2026-2027')->where('term', 2)->update(['is_open' => true]);
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        $termsBefore = DB::table('subject_terms')->orderBy('id')->get()->toArray();
        $choicesBefore = SectionSubject::count();

        $this->detect($this->ecr(['period' => 2]), $this->genMath, 2)->assertOk();

        $this->assertEquals($termsBefore, DB::table('subject_terms')->orderBy('id')->get()->toArray(), 'Terms Taught must not be touched by an upload');
        $this->assertSame($choicesBefore, SectionSubject::count(), 'section choices must not be touched by an upload');
        $this->assertDatabaseMissing('activity_logs', ['action' => 'sync_section_subject_from_ecr']);
    }

    public function test_an_upload_never_introduces_a_subject_the_section_does_not_take(): void
    {
        // Basic Calculus is not chosen for Shakespeare. An upload does not
        // get to decide a section's subject list — that is the Admin's
        // call, and the refusal is the ordinary one, before the file is read.
        $response = $this->detect($this->ecr(['subject' => 'Basic Calculus']), $this->basicCalc, 1);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Basic Calculus is not applicable to Shakespeare in Term 1.');
        $this->assertSame(0, SectionSubject::forSection($this->shakespeare)->count());
    }

    public function test_a_workbook_cannot_widen_a_subjects_terms_taught(): void
    {
        // The Admin narrowed General Mathematics to Term 1. The prescribed
        // workbook says it runs for 3 terms — but the configuration wins:
        // a Term 2 upload is refused, and Terms Taught stays Term 1 only.
        $this->genMath->syncTerms([1]);
        AcademicTerm::where('school_year', '2026-2027')->where('term', 2)->update(['is_open' => true]);
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);

        $response = $this->detect($this->ecr(['period' => 2]), $this->genMath, 2);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'General Mathematics is not applicable to Shakespeare in Term 2.');
        $this->assertSame([1], $this->genMath->fresh()->termNumbers());
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_the_resolver_has_no_write_path_left(): void
    {
        $this->assertFalse(method_exists(EcrSubjectTermResolver::class, 'synchronize'), 'EcrSubjectTermResolver validates only; it must never write configuration');
    }

    // -----------------------------------------------------------------
    // Authorization is unchanged
    // -----------------------------------------------------------------

    public function test_an_adviser_cannot_upload_an_ecr_for_another_advisers_section(): void
    {
        $intruder = User::factory()->create(['role' => 'adviser']);

        $response = $this->actingAs($intruder)->post('/adviser/assessments/detect', [
            'subject_id'     => $this->genMath->id,
            'grading_period' => 1,
            'file'           => new UploadedFile($this->ecr(), 'ecr.xlsx', null, null, true),
        ]);

        $this->assertNotEquals(200, $response->getStatusCode());
        $this->assertDatabaseCount('assessments', 0);
    }
}
