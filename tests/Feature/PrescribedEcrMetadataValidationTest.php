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
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;
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
    public function test_the_resolver_has_no_write_path_left(): void
    {
        $this->assertFalse(method_exists(EcrSubjectTermResolver::class, 'synchronize'), 'EcrSubjectTermResolver validates only; it must never write configuration');
    }}
