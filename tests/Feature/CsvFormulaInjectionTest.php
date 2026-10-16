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
 * Final demo audit (2026-09-30): the plain CSV downloads must neutralise a
 * text cell a spreadsheet would evaluate as a formula, exactly as the Class
 * Record export and Copy Scores already do (ClassRecordService::safeText()).
 * A learner or subject name is free text an Admin types (or an uploaded
 * file supplies), so "=HYPERLINK(...)" in a name used to reach the adviser's
 * Excel as a live formula. The grade template must still round-trip.
 */
class CsvFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '=HYPERLINK("http://example.invalid","click")';

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        return array_map(fn($line) => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\n", $csv), fn($l) => trim($l) !== '')));
    }

    private function assertNoLiveFormula(array $rows): void
    {
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertFalse($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true), "cell starts a formula: {$cell}");
            }
        }
    }

    public function test_grade_template_neutralises_formula_names_and_still_round_trips(): void
    {
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['name' => '+Formula Subject', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $subject->syncTerms([1]);
        $learner = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000901', 'last_name' => self::PAYLOAD, 'first_name' => '@SUM(A1)']);
        TeachingAssignment::create(['user_id' => $adviser->id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);

        $csv = $this->actingAs($adviser)->get(route('adviser.grades.template', ['period' => 1]))->assertOk()->streamedContent();
        $rows = $this->rows($csv);
        $this->assertNoLiveFormula($rows);
        $this->assertSame("'+Formula Subject", $rows[0][3]);
        $this->assertSame(['100000000901', "'" . self::PAYLOAD, "'@SUM(A1)"], array_slice($rows[1], 0, 3));

        // Fill the grade cell and upload the same file back: the escaped
        // subject header still names the subject.
        $rows[1][3] = '88';
        $path = tempnam(sys_get_temp_dir(), 'tpl_') . '.csv';
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);
        $this->actingAs($adviser)->post(route('adviser.grades.import'), [
            'grading_period' => 1, 'file' => new UploadedFile($path, 'template.csv', 'text/csv', null, true),
        ])->assertSessionHas('success');
        @unlink($path);

        $this->assertSame('88.00', number_format((float) Grade::where('student_id', $learner->id)->where('subject_id', $subject->id)->value('grade'), 2));
    }

    public function test_admin_rejected_rows_csv_neutralises_formula_values(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'rej_') . '.csv';
        file_put_contents($path, "lrn,last_name,first_name,middle_name,gender\n,\"" . str_replace('"', '""', self::PAYLOAD) . "\",-2+3,@x,male\n");
        $this->actingAs($admin)->post('/admin/students/import', [
            'section_id' => $section->id, 'file' => new UploadedFile($path, 'students.csv', 'text/csv', null, true),
        ]);
        @unlink($path);

        $rows = $this->rows($this->actingAs($admin)->get('/admin/students/rejected/download')->assertOk()->getContent());
        $this->assertNoLiveFormula($rows);
        $this->assertSame(["'" . self::PAYLOAD, "'-2+3", "'@x"], array_slice($rows[1], 1, 3));
    }
}
