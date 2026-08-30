<?php

namespace Tests\Feature;

use App\Imports\GradesImport;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * GradesImport reads a bulk grade upload (lrn | last_name | first_name |
 * <subject columns...>) and writes rows into `grades`. These tests drive
 * collection() directly with in-memory rows instead of a real spreadsheet
 * file, since ToCollection only needs a Collection of arrays — matching
 * how the class is actually consumed by GradeController::importGrades().
 */
class GradesImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeImport(Section $section, Collection $subjects, Collection $students, int $period = 1): GradesImport
    {
        return new GradesImport($section->id, $period, $section->school_year, $subjects, $students);
    }

    public function test_valid_rows_are_imported(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000001']);
        $subject = Subject::factory()->create();

        $import = $this->makeImport($section, collect([$subject]), collect([$student]));

        $rows = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            [$student->lrn, $student->last_name, $student->first_name, 88.5],
        ]);

        $import->collection($rows);

        $this->assertSame(1, $import->importedCount);
        $this->assertEmpty($import->errors);
        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'grade'      => 88.5,
        ]);
    }

    public function test_unknown_lrn_is_reported_as_an_error_and_not_imported(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $import = $this->makeImport($section, collect([$subject]), collect([$student]));

        $rows = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            ['999999999999', 'Nobody', 'Ghost', 90],
        ]);

        $import->collection($rows);

        $this->assertSame(0, $import->importedCount);
        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('999999999999', $import->errors[0]);
        $this->assertDatabaseMissing('grades', ['grade' => 90]);
    }

    public function test_a_student_from_another_section_is_not_matched(): void
    {
        // GradesImport is only ever handed the adviser's OWN section's
        // students (see GradeController::importGrades) — a student from
        // a different section simply isn't in that lookup, so their LRN
        // is treated the same as an unknown LRN, never imported.
        $adviser      = User::factory()->create();
        $mySection    = Section::factory()->create(['adviser_id' => $adviser->id]);
        $otherSection = Section::factory()->create();
        $otherStudent = Student::factory()->create(['section_id' => $otherSection->id]);
        $subject      = Subject::factory()->create();

        $import = $this->makeImport($mySection, collect([$subject]), collect()); // empty — not this adviser's student

        $rows = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            [$otherStudent->lrn, $otherStudent->last_name, $otherStudent->first_name, 85],
        ]);

        $import->collection($rows);

        $this->assertSame(0, $import->importedCount);
        $this->assertCount(1, $import->errors);
    }

    public function test_score_below_60_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $import = $this->makeImport($section, collect([$subject]), collect([$student]));

        $rows = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            [$student->lrn, $student->last_name, $student->first_name, 59],
        ]);

        $import->collection($rows);

        $this->assertSame(0, $import->importedCount);
        $this->assertCount(1, $import->errors);
        $this->assertDatabaseMissing('grades', ['student_id' => $student->id]);
    }

    public function test_score_above_100_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $import = $this->makeImport($section, collect([$subject]), collect([$student]));

        $rows = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            [$student->lrn, $student->last_name, $student->first_name, 101],
        ]);

        $import->collection($rows);

        $this->assertSame(0, $import->importedCount);
        $this->assertCount(1, $import->errors);
    }

    public function test_non_numeric_score_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $import = $this->makeImport($section, collect([$subject]), collect([$student]));

        $rows = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            [$student->lrn, $student->last_name, $student->first_name, 'incomplete'],
        ]);

        $import->collection($rows);

        $this->assertSame(0, $import->importedCount);
        $this->assertCount(1, $import->errors);
    }

    public function test_blank_grade_cell_is_skipped_silently_not_an_error(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $import = $this->makeImport($section, collect([$subject]), collect([$student]));

        $rows = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            [$student->lrn, $student->last_name, $student->first_name, ''],
        ]);

        $import->collection($rows);

        $this->assertSame(0, $import->importedCount);
        $this->assertEmpty($import->errors); // blank is not an error, just skipped
    }

    public function test_a_column_not_matching_any_known_subject_is_ignored(): void
    {
        // Prevents an unrecognized/unmapped column from silently entering
        // the grading calculation under the wrong subject.
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $import = $this->makeImport($section, collect([$subject]), collect([$student]));

        $rows = collect([
            ['lrn', 'last_name', 'first_name', 'Some Unrelated Column'],
            [$student->lrn, $student->last_name, $student->first_name, 95],
        ]);

        $import->collection($rows);

        $this->assertSame(0, $import->importedCount);
        $this->assertDatabaseMissing('grades', ['student_id' => $student->id]);
    }

    public function test_re_importing_the_same_student_and_subject_updates_rather_than_duplicates(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();

        $rows = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            [$student->lrn, $student->last_name, $student->first_name, 80],
        ]);

        $this->makeImport($section, collect([$subject]), collect([$student]))->collection($rows);

        $rowsAgain = collect([
            ['lrn', 'last_name', 'first_name', $subject->name],
            [$student->lrn, $student->last_name, $student->first_name, 92],
        ]);

        $this->makeImport($section, collect([$subject]), collect([$student]))->collection($rowsAgain);

        $this->assertSame(1, Grade::where('student_id', $student->id)->where('subject_id', $subject->id)->count());
        $this->assertDatabaseHas('grades', ['student_id' => $student->id, 'subject_id' => $subject->id, 'grade' => 92]);
    }

    public function test_empty_file_reports_an_error(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $import = $this->makeImport($section, collect(), collect());
        $import->collection(collect());

        $this->assertNotEmpty($import->errors);
    }
}
