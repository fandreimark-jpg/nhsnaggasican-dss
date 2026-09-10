<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 4a/4d — SubjectsImport::model() has
 * always silently defaulted a blank/absent subject_group cell to
 * core_academic; that fallback is correct behaviour (removing it breaks
 * every older import file) but was invisible. This proves the NOTICE
 * actually appears on the page after a real HTTP import — not just that
 * the fallback ran and the database got 'core_academic' written to it,
 * which SubjectsImportTest already covers and which this file deliberately
 * does not re-test.
 */
class SubjectsImportDefaultGroupNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_with_no_subject_group_column_gets_a_named_notice_on_a_successful_import(): void
    {
        $admin = User::factory()->admin()->create();

        // No subject_group column in the header at all -- the exact shape
        // every pre-existing import file has.
        $csv = "name,type,grade_level,track,specialization\n";
        $csv .= "Empowerment Technologies,core,11,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_default_notice_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/subjects/import', ['file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.subjects'));
        // A successful import (no row failures) must STILL carry the notice.
        $response->assertSessionHas('success', '1 subject(s) imported successfully!');
        $response->assertSessionHas('import_warnings');
        $warnings = session('import_warnings');
        $this->assertNotEmpty($warnings, 'The default-group notice must actually be present, not an empty array.');
        $this->assertStringContainsString('Empowerment Technologies', $warnings[0]);
        $this->assertMatchesRegularExpression('/defaulted to Core Academic/i', $warnings[0]);

        // The notice must actually render on the redirected page, not just
        // sit in the session unrendered.
        $follow = $this->actingAs($admin)->get(route('admin.subjects'));
        $follow->assertSee('Empowerment Technologies');
        $follow->assertSee('defaulted to Core Academic', false);
    }

    public function test_a_row_with_an_explicit_subject_group_gets_no_notice(): void
    {
        $admin = User::factory()->admin()->create();

        $csv = "name,type,grade_level,subject_group,track,specialization\n";
        $csv .= "General Mathematics,core,11,core_academic,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_explicit_group_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/subjects/import', ['file' => $file]);

        @unlink($path);

        $response->assertSessionHas('import_warnings', []);
    }
}
