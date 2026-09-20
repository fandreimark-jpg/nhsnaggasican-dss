<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import failure COUNTING — a row that fails on several fields is one
 * skipped row with one combined message, not one message per field.
 * Proven end to end through the real HTTP upload (the shared
 * SummarizesImportFailures trait), not just at the importer level.
 *
 * "Subject applicability" refactor (2026-09-20) — exercised through the
 * learner roster import (Admin > Students), the one bulk master-data
 * upload that remains; the invented Sections/Tracks/Specializations/
 * Subjects file formats this test used to go through were removed.
 */
class ImportFailureCountTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'lrn,last_name,first_name,middle_name,gender';

    public function test_eight_completely_blank_rows_are_reported_as_eight_skipped_rows_not_thirty_two(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();

        // Every required column left blank (lrn, last_name, first_name,
        // gender) but a junk middle_name so the row isn't ENTIRELY empty —
        // a fully empty row is silently skipped by the reader itself before
        // validation ever runs, which would produce zero failures and
        // defeat this test.
        $csv = self::HEADER . "\n";
        for ($i = 0; $i < 8; $i++) {
            $csv .= ",,,junk,\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'blank_students_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'students.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/students/import', ['section_id' => $section->id, 'file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.students'));
        $response->assertSessionHas('warning', function ($warning) {
            return str_contains($warning, '8 row(s) were rejected');
        });

        $errors = session('import_errors');
        $this->assertCount(8, $errors, 'Eight bad rows with four missing fields each must be reported as 8 skipped rows, not 32.');

        foreach ($errors as $index => $message) {
            $this->assertStringStartsWith('Row ' . ($index + 2), $message, 'Each message must combine one row\'s errors, not repeat one field at a time.');
        }

        $this->assertNotNull(session('import_header_hint'), 'A file where every row is missing every required column should surface the header-mismatch hint.');
        $this->assertStringContainsString('lrn, last_name, first_name, gender', session('import_header_hint'));

        // The flashed session data survives into this next request —
        // prove the shared partial actually renders it, not just that
        // the session carries it.
        $page = $this->get(route('admin.students'));
        $page->assertOk();
        $page->assertSee('8 row(s) were rejected', false);
        $page->assertSee('Show all 8 skipped rows', false);
        $page->assertSee('header row matches the template exactly', false);
    }

    public function test_a_mixed_valid_and_invalid_file_still_imports_the_valid_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $csv = self::HEADER . "\n";
        $csv .= "110000000001,Agbayani,Rhea Mae,,female\n";
        $csv .= ",,,junk,\n";

        $path = tempnam(sys_get_temp_dir(), 'mixed_students_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'students.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/students/import', ['section_id' => $section->id, 'file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.students'));
        $this->assertDatabaseHas('students', ['lrn' => '110000000001', 'section_id' => $section->id]);
        $this->assertCount(1, session('import_errors'));
    }
}
