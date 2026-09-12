<?php

namespace Tests\Feature;

use App\Models\DepedSubjectCatalog;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Follow-up audit after "subject classification and grading weights
 * cleanup" — proves the three safety-net requirements explicitly, as
 * tests rather than only as a design decision:
 *
 *  1. Neither of the two real Subject-creation paths (Admin\
 *     SubjectController::store()/update(), and SubjectsImport) can put a
 *     Grade 11 subject into the database with a null/invalid
 *     subject_group. There is no third path — every assessment/grade
 *     upload route requires `exists:subjects,id`, so an ECR/assessment
 *     import can never create a Subject at all, let alone one defaulted
 *     to core_academic.
 *  2. SubjectGroupWeight::resolve() still throws for an unclassified
 *     do015_2026 subject rather than defaulting it — kept strict on
 *     purpose (SubjectClassificationConsistencyTest already covers the
 *     unit-level guarantee; this file adds the end-to-end angle: a
 *     subject that reaches this state despite the safety net still fails
 *     LOUDLY at grading time, not silently).
 *  3. The Admin Subjects Data Health card no longer cites the stale
 *     "101 of 139" historical figure.
 */
class SubjectClassificationSafetyNetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_form_cannot_create_a_grade_11_subject_with_no_subject_group(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Unclassified Grade 11 Subject', 'type' => 'core', 'grade_level' => 11,
        ])->assertSessionHasErrors('subject_group');

        $this->assertDatabaseMissing('subjects', ['name' => 'Unclassified Grade 11 Subject']);
    }

    public function test_an_import_row_with_no_subject_group_cannot_create_a_grade_11_subject(): void
    {
        $admin = User::factory()->admin()->create();

        $csv = "name,type,grade_level,track,specialization\n";
        $csv .= "Unclassified Import Row,core,11,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_safety_net_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $this->actingAs($admin)->post('/admin/subjects/import', ['grade_level' => '11', 'file' => $file]);
        @unlink($path);

        $this->assertDatabaseMissing('subjects', ['name' => 'Unclassified Import Row']);
    }

    /**
     * There is no fourth column-mapping route: only `subject_id` FK
     * references are accepted anywhere an assessment/grade is uploaded —
     * confirmed by grep across app/Http/Controllers, not assumed. This
     * proves the consequence directly: posting a grade/assessment for a
     * subject id that does not exist is rejected, not silently
     * auto-vivified into a new (and therefore unclassified) Subject row.
     */
    public function test_assessment_upload_rejects_an_unknown_subject_id_rather_than_creating_one(): void
    {
        $adviser = User::factory()->create();
        \App\Models\Section::factory()->create(['adviser_id' => $adviser->id]);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/item', [
            'subject_id'     => 999999,
            'grading_period' => 1,
            'item_name'      => 'Quiz 1',
            'component'      => 'written_work',
            'max_score'      => 20,
        ]);

        $response->assertSessionHasErrors('subject_id', null, 'addItem');
        $this->assertSame(0, Subject::count(), 'No Subject row must ever be silently created by an assessment upload.');
    }

    public function test_an_unclassified_do015_subject_fails_loudly_at_grading_time_not_silently(): void
    {
        // Simulates data that reached this state despite the safety net
        // (e.g. a legacy row, or direct DB manipulation) -- factories
        // bypass the Admin/import validation layer on purpose so this
        // exact scenario can be exercised.
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'subject_group' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/classify the subject or seed one/');

        SubjectGroupWeight::resolve('do015_2026', $subject->subject_group);
    }

    public function test_the_data_health_card_does_not_cite_a_stale_hardcoded_disagreement_count(): void
    {
        $admin = User::factory()->admin()->create();

        // Force the card to render by creating a subject that still
        // trips withSuspectSubjectGroup() -- a catalog-linked subject
        // whose stored subject_group weights disagree with the catalog.
        $catalog = DepedSubjectCatalog::create([
            'scheme' => 'do015_2026', 'track' => 'ACADEMIC', 'cluster' => 'STEM',
            'course_title' => 'Safety Net Test Subject', 'grade_levels' => '11',
            'ww_weight' => 20, 'pt_weight' => 50, 'ex_weight' => 100,
            'teacher_supplied' => false,
        ]);
        Subject::factory()->create([
            'name' => 'Safety Net Test Subject', 'type' => 'core', 'grade_level' => 11,
            'subject_group' => 'core_academic', 'catalog_id' => $catalog->id,
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertDontSee('101 of 139');
        $response->assertSee('may have the wrong grading weight');
    }
}
