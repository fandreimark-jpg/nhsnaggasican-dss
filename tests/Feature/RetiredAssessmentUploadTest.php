<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentUpload;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Class Record refinements" pass (2026-09-28) — teachers and advisers no
 * longer upload assessment/ECR score files. Scores are entered only in the
 * Class Record grid. This is the replacement coverage for the upload tests
 * retired with the feature: every retired endpoint is gone (a direct or
 * crafted request writes nothing), a file staged before the retirement can
 * never be committed, no teacher-facing page offers an upload, and the
 * Admin learner imports — a different, still supported upload — work.
 */
class RetiredAssessmentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRED_ROUTES = [
        'adviser.assessments.detect', 'adviser.assessments.preview', 'adviser.assessments.import',
        'adviser.assessments.record', 'adviser.assessments.item.store', 'adviser.assessments.item.update',
    ];

    private User $adviser;
    private User $teacher;
    private Section $section;
    private Subject $subject;
    private Student $learner;
    private Assessment $item;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->teacher = User::factory()->create(['role' => 'subject_teacher']);
        $this->section = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        $this->subject = Subject::factory()->create(['name' => 'General Science', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1, 2, 3]);
        $this->learner = Student::factory()->create(['section_id' => $this->section->id, 'lrn' => '012345678901', 'last_name' => 'ABAD', 'first_name' => 'ANA']);
        foreach ([$this->adviser, $this->teacher] as $i => $user) {
            // Both roles hold a real assignment, so a refusal is the retirement — not a missing permission.
            TeachingAssignment::create(['user_id' => $user->id, 'section_id' => $this->section->id, 'subject_id' => $i === 0 ? $this->subject->id : Subject::factory()->create(['name' => 'Other ' . $i, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic'])->id,
                'school_year' => '2026-2027', 'grading_period' => 1]);
        }
        $this->item = Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->adviser->id]);
    }

    public function test_every_retired_endpoint_is_gone(): void
    {
        foreach (self::RETIRED_ROUTES as $name) {
            $this->assertFalse(Route::has($name), "{$name} must no longer exist");
        }
    }

    public function test_direct_and_crafted_requests_to_the_retired_urls_write_nothing(): void
    {
        $csv = UploadedFile::fake()->createWithContent('scores.csv', "lrn,last_name,first_name,Quiz 1\n012345678901,ABAD,ANA,18\n");
        $mapping = ['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'stored_filename' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.csv',
            'columns' => [['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20]]];

        foreach ([$this->adviser, $this->teacher] as $user) {
            foreach (['/adviser/assessments/detect', '/adviser/assessments/preview', '/adviser/assessments/import'] as $url) {
                $status = $this->actingAs($user)->post($url, $mapping + ['file' => $csv])->getStatusCode();
                $this->assertContains($status, [404, 405], "POST {$url} must not be served (got {$status})");
            }
            $this->assertContains($this->actingAs($user)->post('/adviser/assessments/item', ['subject_id' => $this->subject->id, 'grading_period' => 1,
                'item_name' => 'Quiz 2', 'component' => 'written_work', 'max_score' => 20, 'scores' => [$this->learner->id => 10]])->getStatusCode(), [404, 405]);
            $this->assertContains($this->actingAs($user)->put('/adviser/assessments/item/' . $this->item->id, ['max_score' => 5,
                'scores' => [$this->learner->id => 5]])->getStatusCode(), [404, 405]);
            $this->assertContains($this->actingAs($user)->get('/adviser/assessments/record?period=1&subject_id=' . $this->subject->id)->getStatusCode(), [404, 405]);
        }

        $this->assertSame(1, Assessment::count());
        $this->assertSame(0, AssessmentScore::count());
        $this->assertSame(0, AssessmentUpload::count());
        $this->assertEquals(20, $this->item->fresh()->max_score);
    }

    public function test_a_file_staged_before_the_retirement_can_never_be_committed(): void
    {
        // A Verify/Preview session left from before: the stored temp file exists.
        Storage::disk('local')->put('temp_assessment_uploads/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.csv',
            "lrn,last_name,first_name,Quiz 1\n012345678901,ABAD,ANA,18\n");

        $response = $this->actingAs($this->adviser)->post('/adviser/assessments/import', [
            'subject_id' => $this->subject->id, 'grading_period' => 1, 'stored_filename' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.csv',
            'columns' => [['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20]],
        ]);

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertSame(0, AssessmentScore::count());
        $this->assertSame(0, AssessmentUpload::count());
    }

    public function test_no_teacher_facing_page_offers_an_assessment_upload(): void
    {
        AssessmentScore::create(['assessment_id' => $this->item->id, 'student_id' => $this->learner->id, 'score' => 12]);
        $pages = [
            [$this->adviser, route('teacher.class-record', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1])],
            [$this->adviser, route('teacher.classes')],
            [$this->adviser, route('adviser.grades', ['period' => 1, 'subject_id' => $this->subject->id])],
            [$this->adviser, route('adviser.advisory-overview')],
            [$this->adviser, route('adviser.dashboard')],
            [$this->adviser, route('adviser.submit.report')],
            [$this->adviser, route('teacher.assessments.create', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1])],
            [$this->adviser, route('teacher.assessments.edit', $this->item->id)],
            [$this->teacher, route('teacher.classes')],
        ];
        foreach ($pages as [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            foreach (['Upload Assessment', 'assessments/detect', 'Download Assessment Record', 'E-Class Record, a DSS Assessment Record'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$url} still advertises the retired upload ({$needle})");
            }
            // The only file input allowed anywhere here is Encode & Verify Grades'
            // separate FINAL-GRADE import (adviser.grades.import) — not an assessment upload.
            $withoutGradeImport = preg_replace('#<form[^>]+action="' . preg_quote(route('adviser.grades.import'), '#') . '".*?</form>#s', '', $html);
            $this->assertDoesNotMatchRegularExpression('/<input[^>]+type="file"/', $withoutGradeImport, "{$url} has an assessment file input");
        }

        // The retired Assessments page is no longer a destination: it redirects to the Class Record.
        $this->actingAs($this->adviser)->get(route('adviser.assessments', ['period' => 1, 'subject_id' => $this->subject->id]))
            ->assertRedirect(route('teacher.class-record', ['school_year' => '2026-2027', 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1]));

        // Download Excel and Copy Scores remain.
        $this->actingAs($this->adviser)->get(route('teacher.class-record', ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1]))
            ->assertSee('Download Excel Class Record')->assertSee('Copy Scores');
    }

    public function test_the_admin_learner_import_still_works(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertTrue(Route::has('admin.students.import'));
        $this->assertTrue(Route::has('admin.students.import-from-ecr.preview'));

        $this->actingAs($admin)->post(route('admin.students.import'), [
            'section_id' => $this->section->id,
            'file' => UploadedFile::fake()->createWithContent('learners.csv', "lrn,last_name,first_name,middle_name,gender\n123456789012,REYES,ROSA,,female\n"),
        ])->assertRedirect(route('admin.students'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['lrn' => '123456789012', 'last_name' => 'REYES']);

        // Only the Admin: teachers cannot import learners.
        foreach ([$this->adviser, $this->teacher] as $user) {
            $this->actingAs($user)->post(route('admin.students.import'), ['section_id' => $this->section->id])->assertForbidden();
        }
    }
}
