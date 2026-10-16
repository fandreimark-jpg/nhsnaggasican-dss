<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Final demo audit (2026-09-30): spreadsheetFileRule() validates by
 * EXTENSION on purpose, so a corrupt or truncated ".xlsx" reaches the reader.
 * The retired assessment upload turned that into a controlled refusal; the
 * two LIVE imports did not — Admin > Students > Import and Adviser > Encode
 * Grades > Import answered HTTP 500 ("Could not find zip member"). Now both
 * refuse with a message and write nothing.
 */
class UnreadableSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    private function corrupt(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'corrupt_') . '.xlsx';
        file_put_contents($path, "PK\x03\x04" . str_repeat("\x00garbage", 500));

        return new UploadedFile($path, 'class.xlsx', 'application/octet-stream', null, true);
    }

    public function test_admin_learner_import_refuses_an_unreadable_workbook(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $this->actingAs($admin)->post(route('admin.students.import'), ['section_id' => $section->id, 'file' => $this->corrupt()])
            ->assertRedirect()
            ->assertSessionHas('error', fn($m) => str_contains($m, 'could not be read as a spreadsheet'));
        $this->assertSame(0, Student::count());
    }

    public function test_adviser_grade_import_refuses_an_unreadable_workbook(): void
    {
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $subject->syncTerms([1]);
        TeachingAssignment::create(['user_id' => $adviser->id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);

        $this->actingAs($adviser)->post(route('adviser.grades.import'), ['grading_period' => 1, 'file' => $this->corrupt()])
            ->assertRedirect(route('adviser.grades', ['period' => 1]))
            ->assertSessionHas('error', fn($m) => str_contains($m, 'could not be read as a spreadsheet'));
        $this->assertSame(0, Grade::count());
    }
}
