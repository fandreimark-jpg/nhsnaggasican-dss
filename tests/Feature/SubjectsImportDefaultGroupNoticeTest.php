<?php

namespace Tests\Feature;

use App\Models\DepedSubjectCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Subject classification and grading weights cleanup" pass — this file
 * used to prove a CORE row with no subject_group column silently defaulted
 * to core_academic, with a named notice on the result page. That leniency
 * is now REMOVED on purpose (SubjectGroupWeight::classificationError(): a
 * Grade 11 row of any type, core included, has no safe default — an
 * unclassified subject must stay unclassified, never quietly become Core).
 * This file now proves the opposite of what it used to: the same
 * no-subject_group-column CORE row is REJECTED, not defaulted, via a real
 * HTTP import. It also proves the notice channel this file exercised
 * still works for its new purpose: reporting a subject auto-linked to the
 * DepEd catalog by exact name match at import time.
 */
class SubjectsImportDefaultGroupNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_core_row_with_no_subject_group_column_is_rejected_not_defaulted(): void
    {
        $admin = User::factory()->admin()->create();

        // No subject_group column in the header at all -- the exact shape
        // every pre-existing import file has, and the exact shape that
        // used to silently default to core_academic for a CORE row.
        $csv = "name,type,grade_level,track,specialization\n";
        $csv .= "Empowerment Technologies,core,11,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_no_default_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/subjects/import', ['grade_level' => '11', 'file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.subjects'));
        $response->assertSessionHas('warning');
        $this->assertStringContainsString('Subject Group is required', implode("\n", session('import_errors')));
        $this->assertDatabaseMissing('subjects', ['name' => 'Empowerment Technologies']);
    }

    public function test_a_subject_matching_the_deped_catalog_by_name_is_auto_linked_and_reported(): void
    {
        $admin = User::factory()->admin()->create();

        DepedSubjectCatalog::create([
            'scheme' => 'do015_2026', 'track' => 'ACADEMIC', 'cluster' => 'STEM',
            'course_title' => 'Basic Calculus', 'grade_levels' => '11',
            'ww_weight' => 20, 'pt_weight' => 50, 'ex_weight' => 30,
            'teacher_supplied' => false,
        ]);

        $csv = "name,type,grade_level,subject_group,track,specialization\n";
        $csv .= "Basic Calculus,core,11,core_academic,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_catalog_link_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/subjects/import', ['grade_level' => '11', 'file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.subjects'));
        $response->assertSessionHas('success', '1 subject(s) imported successfully!');
        $warnings = session('import_warnings');
        $this->assertNotEmpty($warnings, 'The catalog auto-link notice must actually be present.');
        $this->assertStringContainsString('Basic Calculus', $warnings[0]);
        $this->assertMatchesRegularExpression('/auto-linked/i', $warnings[0]);

        $this->assertDatabaseHas('subjects', ['name' => 'Basic Calculus', 'catalog_id' => DepedSubjectCatalog::where('course_title', 'Basic Calculus')->value('id')]);
    }

    public function test_a_row_with_no_catalog_match_gets_no_link_notice(): void
    {
        $admin = User::factory()->admin()->create();

        $csv = "name,type,grade_level,subject_group,track,specialization\n";
        $csv .= "A Totally Made Up Subject Name,core,11,core_academic,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_no_link_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/subjects/import', ['grade_level' => '11', 'file' => $file]);

        @unlink($path);

        $response->assertSessionHas('import_warnings', []);
    }
}
