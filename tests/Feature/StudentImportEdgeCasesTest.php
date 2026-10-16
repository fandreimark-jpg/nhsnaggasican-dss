<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Pre-demo audit (2026-10-06): Admin "Import Students" edge cases — long,
 * Unicode, apostrophe and padded names; numeric LRN cells from Excel;
 * partial import feedback; and a safe retry of the same file.
 */
class StudentImportEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
    }

    private function import(UploadedFile $file)
    {
        return $this->actingAs($this->admin)->post(route('admin.students.import'), ['section_id' => $this->section->id, 'file' => $file]);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('learners.csv', "lrn,last_name,first_name,middle_name,gender\n" . $body);
    }

    public function test_an_over_long_middle_name_is_a_rejected_row_not_a_server_error(): void
    {
        $long = str_repeat('a', 300);
        $this->import($this->csv("123456789012,Cruz,Ana,{$long},female\n123456789013,Reyes,Ben,,male\n"))
            ->assertRedirect(route('admin.students'));

        $this->assertSame(['123456789013'], Student::pluck('lrn')->all(), 'the valid row is imported, the long one rejected');
        $this->assertNotEmpty(session('import_errors'));
    }

    public function test_unicode_apostrophes_and_padding_are_kept_exactly_but_trimmed(): void
    {
        $this->import($this->csv("\" 123456789012 \",\"  O'Brien-Ñuñez \",\" María José \",\" dela Cruz \",\"Female \"\n"))->assertSessionHas('success');

        $s = Student::sole();
        $this->assertSame(["O'Brien-Ñuñez", 'María José', 'dela Cruz', 'female'], [$s->last_name, $s->first_name, $s->middle_name, $s->gender]);
    }

    public function test_a_numeric_lrn_cell_from_excel_is_accepted(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['lrn', 'last_name', 'first_name', 'middle_name', 'gender'], [123456789012, 'Cruz', 'Ana', null, 'Female']]);
        $path = tempnam(sys_get_temp_dir(), 'lrn') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $this->import(new UploadedFile($path, 'learners.xlsx', null, null, true))->assertSessionHas('success');
        $this->assertSame('123456789012', Student::sole()->lrn);
    }

    public function test_re_uploading_the_same_file_creates_no_duplicate_learner_or_enrollment(): void
    {
        $body = "123456789012,Cruz,Ana,,female\n123456789013,Reyes,Ben,,male\n";
        $this->import($this->csv($body))->assertSessionHas('success');
        $this->import($this->csv($body))->assertSessionHas('warning');

        $this->assertSame(2, Student::count());
        $this->assertSame(2, StudentEnrollment::count());
        $this->assertSame(2, StudentEnrollment::where('section_id', $this->section->id)->where('school_year', '2026-2027')->distinct('student_id')->count('student_id'));
    }
}
