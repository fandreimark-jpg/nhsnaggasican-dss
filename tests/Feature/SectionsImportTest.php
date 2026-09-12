<?php

namespace Tests\Feature;

use App\Imports\SectionsImport;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * SectionsImport is driven through Excel::import() against a real
 * generated CSV — same reasoning as TracksImportTest/SubjectsImportTest:
 * WithValidation's row-skipping only kicks in through the full
 * Maatwebsite pipeline, not by calling model()/rules() directly.
 */
class SectionsImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'name,grade_level,track,specialization,adviser_email,school_year';

    private function importCsv(array $rows, string $header = self::HEADER): SectionsImport
    {
        $path = tempnam(sys_get_temp_dir(), 'sections_import_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, explode(',', $header));
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        $import = new SectionsImport();
        Excel::import($import, $path);

        @unlink($path);

        return $import;
    }

    private function makeTrackAndSpec(): array
    {
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        $spec = Specialization::factory()->create([
            'track_id' => $track->id, 'name' => 'Science, Technology, Engineering and Mathematics', 'code' => 'STEM',
        ]);

        return [$track, $spec];
    }

    /** Step 2: a clean 4-row file. */
    public function test_a_clean_four_row_file_creates_four_sections_with_correct_foreign_keys(): void
    {
        [$track, $spec] = $this->makeTrackAndSpec();
        // role defaults to 'adviser' via UserFactory::configure() — no explicit state needed.
        $adviser1 = User::factory()->create(['email' => 'adviser1@naggasican.edu.ph']);
        $adviser2 = User::factory()->create(['email' => 'adviser2@naggasican.edu.ph']);

        $import = $this->importCsv([
            ['Narra', '11', 'ACAD', 'STEM', 'adviser1@naggasican.edu.ph', '2026-2027'],
            ['Molave', '11', 'Academic Track', 'STEM', 'adviser2@naggasican.edu.ph', '2026-2027'],
            ['Acacia', '12', 'ACAD', 'Science, Technology, Engineering and Mathematics', '', '2026-2027'],
            ['Mahogany', '12', 'acad', 'stem', '', '2026-2027'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(4, $import->importedCount);
        $this->assertSame(4, Section::count());

        $narra = Section::where('name', 'Narra')->first();
        $this->assertSame($track->id, $narra->track_id);
        $this->assertSame($spec->id, $narra->specialization_id);
        $this->assertSame($adviser1->id, $narra->adviser_id);

        $molave = Section::where('name', 'Molave')->first();
        $this->assertSame($track->id, $molave->track_id);
        $this->assertSame($adviser2->id, $molave->adviser_id);
    }

    /** Step 3: re-importing the same file must fail every row (duplicate name+school_year) and create nothing new. */
    public function test_reimporting_the_same_file_fails_every_row_as_a_duplicate_and_creates_nothing(): void
    {
        $this->makeTrackAndSpec();
        $rows = [
            ['Narra', '11', 'ACAD', 'STEM', '', '2026-2027'],
            ['Molave', '11', 'ACAD', 'STEM', '', '2026-2027'],
            ['Acacia', '12', 'ACAD', 'STEM', '', '2026-2027'],
            ['Mahogany', '12', 'ACAD', 'STEM', '', '2026-2027'],
        ];

        $this->importCsv($rows);
        $this->assertSame(4, Section::count());

        $second = $this->importCsv($rows);

        $this->assertCount(4, $second->failures());
        $this->assertSame(4, Section::count(), 'No new sections should be created on re-import.');
        foreach ($second->failures() as $failure) {
            $this->assertStringContainsString('already exists', implode(' ', $failure->errors()));
        }
    }

    /** Step 4: adviser_email belonging to an admin must fail, naming the role, and create no section with that adviser. */
    public function test_adviser_email_belonging_to_an_admin_fails_naming_the_role(): void
    {
        $this->makeTrackAndSpec();
        $admin = User::factory()->admin()->create(['email' => 'theadmin@naggasican.edu.ph']);

        $import = $this->importCsv([
            ['Narra', '11', 'ACAD', 'STEM', 'theadmin@naggasican.edu.ph', '2026-2027'],
        ]);

        $this->assertCount(1, $import->failures());
        $errorText = implode(' ', $import->failures()->first()->errors());
        $this->assertStringContainsString('admin', $errorText);
        $this->assertSame(0, Section::count());
        $this->assertSame(0, Section::where('adviser_id', $admin->id)->count());
    }

    /** Step 5: a blank adviser_email is valid — the section is created with a null adviser_id. */
    public function test_blank_adviser_email_creates_a_section_with_no_adviser(): void
    {
        $this->makeTrackAndSpec();

        $import = $this->importCsv([
            ['Narra', '11', 'ACAD', 'STEM', '', '2026-2027'],
        ]);

        $this->assertCount(0, $import->failures());
        $section = Section::where('name', 'Narra')->first();
        $this->assertNotNull($section);
        $this->assertNull($section->adviser_id);
    }

    /** Step 6: the same adviser_email on two rows must NOT be blocked — both sections are created, and a warning names both. */
    public function test_the_same_adviser_email_on_two_rows_creates_both_and_warns_naming_both(): void
    {
        $this->makeTrackAndSpec();
        User::factory()->create(['email' => 'busy@naggasican.edu.ph']); // role defaults to 'adviser'

        $import = $this->importCsv([
            ['Narra', '11', 'ACAD', 'STEM', 'busy@naggasican.edu.ph', '2026-2027'],
            ['Molave', '11', 'ACAD', 'STEM', 'busy@naggasican.edu.ph', '2026-2027'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(2, Section::where('adviser_id', User::where('email', 'busy@naggasican.edu.ph')->value('id'))->count());

        $this->assertArrayHasKey('busy@naggasican.edu.ph', $import->duplicateAdviserWarnings);
        $names = $import->duplicateAdviserWarnings['busy@naggasican.edu.ph'];
        $this->assertContains('Narra', $names);
        $this->assertContains('Molave', $names);
    }

    /** Step 7: an unresolvable track must fail, and no section may be created with a null track_id. */
    public function test_an_unresolvable_track_fails_and_creates_no_section(): void
    {
        $import = $this->importCsv([
            ['Narra', '11', 'NoSuchTrack', 'NoSuchSpec', '', '2026-2027'],
        ]);

        $this->assertCount(1, $import->failures());
        $errorText = implode(' ', $import->failures()->first()->errors());
        $this->assertStringContainsString('NoSuchTrack', $errorText);
        $this->assertSame(0, Section::count());
        $this->assertSame(0, Section::whereNull('track_id')->count());
    }

    /** Step 8: end-to-end proof the track/specialization actually resolved — Subject::forSection() must find the elective. */
    public function test_a_section_created_by_import_correctly_resolves_elective_subjects_for_its_students(): void
    {
        [$track, $spec] = $this->makeTrackAndSpec();
        $elective = Subject::factory()->create([
            'name' => 'General Biology 1', 'type' => 'elective', 'grade_level' => 11,
            'track_id' => $track->id, 'specialization_id' => $spec->id,
        ]);

        $import = $this->importCsv([
            ['Narra', '11', 'ACAD', 'STEM', '', '2026-2027'],
        ]);
        $this->assertCount(0, $import->failures());

        $section = Section::where('name', 'Narra')->first();
        Student::factory()->create(['section_id' => $section->id]);

        $subjects = Subject::forSection($section)->get();

        $this->assertTrue($subjects->contains('id', $elective->id), 'The imported section must resolve to the correct elective via its track_id/specialization_id.');
    }

    /**
     * SYSTEM_FIXES_AND_ML_AUDIT.md, "sections/curriculum reconciliation"
     * -- system-side structure only (see the importer's own docblock on
     * why this stays optional, not required, until a real school file
     * exists to justify making it mandatory).
     */
    public function test_curriculum_column_is_optional_and_absent_files_still_import_with_a_null_curriculum(): void
    {
        [$track, $spec] = $this->makeTrackAndSpec();

        $import = $this->importCsv([
            ['Narra', '11', $track->name, $spec->name, '', '2026-2027'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('sections', ['name' => 'Narra', 'curriculum' => null]);
    }

    public function test_curriculum_column_when_given_is_stored_explicitly(): void
    {
        [$track, $spec] = $this->makeTrackAndSpec();

        $import = $this->importCsv([
            ['Shakespeare', '11', $track->name, $spec->name, '', '2026-2027', 'sshs'],
        ], self::HEADER . ',curriculum');

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('sections', ['name' => 'Shakespeare', 'curriculum' => 'sshs']);
    }

    public function test_an_unrecognized_curriculum_value_is_rejected_not_silently_dropped(): void
    {
        [$track, $spec] = $this->makeTrackAndSpec();

        $import = $this->importCsv([
            ['Mystery', '11', $track->name, $spec->name, '', '2026-2027', 'strengthened_shs'], // not a real value
        ], self::HEADER . ',curriculum');

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('sections', ['name' => 'Mystery']);
    }

    public function test_admin_can_import_sections_via_http_and_gets_a_summary(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeTrackAndSpec();

        $csv  = self::HEADER . "\n";
        $csv .= "Narra,11,ACAD,STEM,,2026-2027\n";
        $path = tempnam(sys_get_temp_dir(), 'sections_http_import_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'sections.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/sections/import', ['file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.sections'));
        $response->assertSessionHas('success', '1 section(s) imported successfully!');
        $this->assertDatabaseHas('sections', ['name' => 'Narra', 'school_year' => '2026-2027']);
    }

    public function test_adviser_cannot_import_sections(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->post('/admin/sections/import', [])
            ->assertForbidden();
    }

    public function test_principal_cannot_import_sections(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->post('/admin/sections/import', [])
            ->assertForbidden();
    }
}
