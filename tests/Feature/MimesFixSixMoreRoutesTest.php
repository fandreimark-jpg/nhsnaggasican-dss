<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * "mimes:" fix, Block 2 — the same six routes named in the work order, each
 * proved at the real HTTP layer, not the service layer. The real official
 * SSHS E-Class Record fixture is reused as the upload for every route here
 * ONLY because its bytes are the confirmed real-world case that sniffs as
 * application/octet-stream (see CLAUDE.md) -- its CONTENT is nonsense for
 * every one of these importers, which is fine and expected: the only thing
 * under test is that the file-type validation layer no longer rejects it
 * by MIME before the importer ever gets to look at what's inside.
 */
class MimesFixSixMoreRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The full real ECR fixture (434KB, 7 richly-formatted sheets) is the
     * confirmed real-world reproduction of the octet-stream MIME-sniffing
     * bug (see CLAUDE.md), but Maatwebsite's importers here read it WITHOUT
     * setReadDataOnly(true) -- unlike our own EcrReaderService/
     * EcrProfileDetector -- and exhaust PHP's memory limit trying to fully
     * parse it. That's a separate, real, pre-existing gap (flagged, not
     * fixed here -- these importers were never built to expect a file this
     * heavy). This is the first 64KB of the SAME real file: still
     * genuinely sniffs as application/octet-stream (confirmed: `file
     * --mime-type` on this exact file), so it still reproduces the actual
     * bug the fix addresses, without the memory blowup.
     */
    private function realOctetStreamSniffingFile(string $name = 'upload.xlsx'): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/octet-stream-sniffing-truncated.xlsx'),
            $name,
            null,
            null,
            true
        );
    }

    public function test_admin_sections_import_accepts_the_real_fixture_past_file_validation(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/sections/import', [
            'file' => $this->realOctetStreamSniffingFile(),
        ]);

        $response->assertSessionDoesntHaveErrors('file');
    }

    public function test_admin_specializations_import_accepts_the_real_fixture_past_file_validation(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/specializations/import', [
            'file' => $this->realOctetStreamSniffingFile(),
        ]);

        $response->assertSessionDoesntHaveErrors('file');
    }

    public function test_admin_subjects_import_accepts_the_real_fixture_past_file_validation(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/subjects/import', [
            'file' => $this->realOctetStreamSniffingFile(),
        ]);

        $response->assertSessionDoesntHaveErrors('file');
    }

    public function test_admin_tracks_import_accepts_the_real_fixture_past_file_validation(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/tracks/import', [
            'file' => $this->realOctetStreamSniffingFile(),
        ]);

        $response->assertSessionDoesntHaveErrors('file');
    }

    public function test_admin_students_import_accepts_the_real_fixture_past_file_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/students/import', [
            'section_id' => $section->id,
            'file'       => $this->realOctetStreamSniffingFile(),
        ]);

        $response->assertSessionDoesntHaveErrors('import');
    }

    public function test_adviser_grades_import_accepts_the_real_fixture_past_file_validation(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->post('/adviser/grades/import', [
            'grading_period' => 1,
            'file'           => $this->realOctetStreamSniffingFile(),
        ]);

        $response->assertSessionDoesntHaveErrors('gradeImport');
    }
}
