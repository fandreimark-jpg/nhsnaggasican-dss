<?php

namespace Tests\Feature;

use App\Exceptions\AssessmentMetadataConflictException;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentUpload;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AssessmentItemConflictDetector;
use App\Services\AssessmentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pre-demo hardening, Phase 2 (2026-09-21) — a repeat assessment upload
 * that matches an existing item by name must NOT silently rewrite that
 * item's protected metadata (component, exam role, additional-support
 * flag, max score). The rule lives in AssessmentItemConflictDetector; it
 * is enforced at Verify/Preview (the adviser is returned to Verify with
 * the conflicts named) and again at import() — in the controller before
 * the upload record exists and inside AssessmentUploadService::import()'s
 * transaction before the first write. Identical metadata, a new item, or
 * a same-named item in another scope is never a conflict, so a genuine
 * re-upload of a corrected E-Class Record keeps working exactly as before.
 */
class RepeatUploadMetadataConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $adviser;
    private Section $section;
    private Subject $subject;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adviser = User::factory()->create();
        $this->section = Section::factory()->create(['adviser_id' => $this->adviser->id, 'school_year' => '2026-2027']);
        $this->subject = Subject::factory()->create(['grade_level' => $this->section->grade_level, 'type' => 'core']);
        $this->student = Student::factory()->create(['section_id' => $this->section->id, 'lrn' => '100000000020']);
        AcademicTerm::ensureExistFor($this->section->school_year);
    }

    // ----------------------------------------------------------------
    // helpers
    // ----------------------------------------------------------------

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('assessment.csv', $content);
    }

    /** Upload a file through detect() and return the stored temp filename. */
    private function detect(string $csvContent, int $period = 1): string
    {
        $response = $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $this->subject->id,
            'grading_period' => $period,
            'file'           => $this->csv($csvContent),
        ]);
        $response->assertOk()->assertViewIs('adviser.assessments-verify');

        return $response->viewData('storedFilename');
    }

    private function postPreview(string $stored, array $columns, int $period = 1)
    {
        return $this->actingAs($this->adviser)->post('/adviser/assessments/preview', [
            'subject_id' => $this->subject->id, 'grading_period' => $period,
            'stored_filename' => $stored, 'original_filename' => 'assessment.csv', 'columns' => $columns,
        ]);
    }

    private function postImport(string $stored, array $columns, int $period = 1)
    {
        return $this->actingAs($this->adviser)->post('/adviser/assessments/import', [
            'subject_id' => $this->subject->id, 'grading_period' => $period,
            'stored_filename' => $stored, 'original_filename' => 'assessment.csv', 'columns' => $columns,
        ]);
    }

    /** An item already recorded under this section/subject/term/year, with one score. */
    private function existingItem(array $attributes = []): Assessment
    {
        $item = Assessment::create(array_merge([
            'subject_id'      => $this->subject->id,
            'section_id'      => $this->section->id,
            'grading_period'  => 1,
            'school_year'     => $this->section->school_year,
            'name'            => 'Quiz 1',
            'assessment_type' => 'Quiz 1',
            'component'       => 'written_work',
            'exam_role'       => null,
            'is_additional_support' => false,
            'max_score'       => 20,
            'uploaded_by'     => $this->adviser->id,
        ], $attributes));

        AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->student->id, 'score' => 15]);

        return $item;
    }

    /** Every protected column of every item, for a "nothing changed" comparison. */
    private function snapshot(): array
    {
        return [
            'items'  => Assessment::orderBy('id')->get(['id', 'name', 'component', 'exam_role', 'is_additional_support', 'max_score', 'import_batch_id'])->toArray(),
            'scores' => AssessmentScore::orderBy('id')->get(['id', 'assessment_id', 'student_id', 'score'])->toArray(),
            'uploads' => AssessmentUpload::count(),
        ];
    }

    // ----------------------------------------------------------------
    // 1. same item + same metadata -> allowed (and 9. scores still replaced)
    // ----------------------------------------------------------------

    public function test_a_re_upload_with_identical_metadata_is_allowed_and_still_replaces_scores(): void
    {
        $item = $this->existingItem();

        $stored  = $this->detect("lrn,last_name,first_name,Quiz 1\n100000000020,Dela Cruz,Juan,19\n");
        $columns = [['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => '20']];

        $this->postPreview($stored, $columns)
            ->assertOk()
            ->assertViewIs('adviser.assessments-preview')
            ->assertSee('data-existing-items-notice', false)   // the existing "will be replaced" notice, unchanged
            ->assertDontSee('data-metadata-conflict-notice', false);

        $this->postImport($stored, $columns)->assertSessionHas('success');

        $this->assertSame(1, Assessment::count());
        $this->assertDatabaseHas('assessments', ['id' => $item->id, 'component' => 'written_work', 'max_score' => 20, 'is_additional_support' => false]);
        $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $item->id, 'student_id' => $this->student->id, 'score' => 19]);
        $this->assertSame(1, AssessmentScore::count());
    }

    // ----------------------------------------------------------------
    // 2. different component -> blocked at preview, adviser returned to Verify
    // ----------------------------------------------------------------

    public function test_a_different_component_is_blocked_at_preview_and_returns_the_adviser_to_verify(): void
    {
        $this->existingItem(['name' => 'Additional Practice WW 2', 'assessment_type' => 'Additional Practice WW 2']);
        $before = $this->snapshot();

        $stored   = $this->detect("lrn,last_name,first_name,Additional Practice WW 2\n100000000020,Dela Cruz,Juan,19\n");
        $response = $this->postPreview($stored, [
            ['name' => 'Additional Practice WW 2', 'component' => 'performance_task', 'max_score' => '20'],
        ]);

        $response->assertOk()
            ->assertViewIs('adviser.assessments-verify')
            ->assertSee('data-metadata-conflict-notice', false)
            ->assertSee('Component conflict')
            ->assertSee('Stored: <strong>Written Work</strong>', false)
            ->assertSee('Upload: <strong>Performance Task</strong>', false)
            ->assertSee('data-conflict-row', false);

        // The adviser's own choice is what the re-rendered Verify shows —
        // not the classifier's guess — so correcting it is one edit away.
        $response->assertViewHas('columns', fn($cols) => $cols[0]['guessed_component'] === 'performance_task' && $cols[0]['has_conflict'] === true);
        $response->assertViewHas('metadataConflicts', fn($c) => count($c) === 1 && $c[0]['field'] === 'component'
            && $c[0]['message'] === "Existing assessment item 'Additional Practice WW 2' is classified as Written Work, but this upload classifies it as Performance Task.");

        $this->assertSame($before, $this->snapshot());
    }

    // ----------------------------------------------------------------
    // 3. same Examination item + different exam_role -> blocked
    // ----------------------------------------------------------------

    public function test_a_different_exam_role_on_an_examination_item_is_blocked(): void
    {
        $this->existingItem(['name' => 'Summative Test 1', 'assessment_type' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1', 'max_score' => 50]);
        $before = $this->snapshot();

        $stored   = $this->detect("lrn,last_name,first_name,Summative Test 1\n100000000020,Dela Cruz,Juan,40\n");
        $response = $this->postPreview($stored, [
            ['name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st2', 'max_score' => '50'],
        ]);

        $response->assertOk()->assertViewIs('adviser.assessments-verify')
            ->assertSee('Exam role conflict')
            ->assertSee('Stored: <strong>Summative Test 1</strong>', false)
            ->assertSee('Upload: <strong>Summative Test 2</strong>', false);
        $response->assertViewHas('metadataConflicts', fn($c) => count($c) === 1
            && $c[0]['message'] === "Existing assessment item 'Summative Test 1' has exam role Summative Test 1, but this upload assigns Summative Test 2.");

        $this->assertSame($before, $this->snapshot());
    }

    // ----------------------------------------------------------------
    // 4. changed is_additional_support -> blocked
    // ----------------------------------------------------------------

    public function test_a_changed_additional_support_flag_is_blocked(): void
    {
        $this->existingItem(['is_additional_support' => false]);
        $before = $this->snapshot();

        $stored   = $this->detect("lrn,last_name,first_name,Quiz 1\n100000000020,Dela Cruz,Juan,19\n");
        $response = $this->postPreview($stored, [
            ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => '20', 'is_additional_support' => '1'],
        ]);

        $response->assertOk()->assertViewIs('adviser.assessments-verify')
            ->assertSee('Additional support conflict')
            ->assertSee('Stored: <strong>No</strong>', false)
            ->assertSee('Upload: <strong>Yes</strong>', false);
        // The submitted checkbox state survives the re-render.
        $response->assertViewHas('columns', fn($cols) => $cols[0]['is_additional_support'] === true);

        $this->assertSame($before, $this->snapshot());
    }

    // ----------------------------------------------------------------
    // 5. changed max_score -> blocked
    // ----------------------------------------------------------------

    public function test_a_changed_max_score_is_blocked(): void
    {
        $this->existingItem(['name' => 'Activity 1', 'assessment_type' => 'Activity 1', 'max_score' => 50]);
        $before = $this->snapshot();

        $stored   = $this->detect("lrn,last_name,first_name,Activity 1\n100000000020,Dela Cruz,Juan,30\n");
        $response = $this->postPreview($stored, [
            ['name' => 'Activity 1', 'component' => 'written_work', 'max_score' => '40'],
        ]);

        $response->assertOk()->assertViewIs('adviser.assessments-verify')
            ->assertSee('Max score conflict')
            ->assertSee('Stored: <strong>50.00</strong>', false)
            ->assertSee('Upload: <strong>40.00</strong>', false);

        $this->assertSame($before, $this->snapshot());
    }

    // ----------------------------------------------------------------
    // 6. new item -> allowed alongside an untouched existing one
    // ----------------------------------------------------------------

    public function test_a_new_item_is_never_a_conflict(): void
    {
        $item = $this->existingItem();

        $stored  = $this->detect("lrn,last_name,first_name,Quiz 1,Quiz 2\n100000000020,Dela Cruz,Juan,18,9\n");
        $columns = [
            ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => '20'],
            ['name' => 'Quiz 2', 'component' => 'performance_task', 'max_score' => '10'], // new — any classification is fine
        ];

        $this->postPreview($stored, $columns)->assertOk()->assertViewIs('adviser.assessments-preview');
        $this->postImport($stored, $columns)->assertSessionHas('success');

        $this->assertSame(2, Assessment::count());
        $this->assertDatabaseHas('assessments', ['id' => $item->id, 'component' => 'written_work', 'max_score' => 20]);
        $this->assertDatabaseHas('assessments', ['name' => 'Quiz 2', 'component' => 'performance_task', 'max_score' => 10]);
    }

    // ----------------------------------------------------------------
    // 7. + 8. conflict at import() even when Verify/Preview were skipped;
    //         no partial writes — not even the upload record
    // ----------------------------------------------------------------

    public function test_import_refuses_a_conflicting_mapping_that_was_never_previewed_and_writes_nothing(): void
    {
        $this->existingItem(['name' => 'Summative Test 1', 'assessment_type' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1', 'max_score' => 50]);
        $before = $this->snapshot();

        $stored = $this->detect("lrn,last_name,first_name,Summative Test 1,Quiz 9\n100000000020,Dela Cruz,Juan,40,7\n");

        // Straight to import() with a crafted mapping — Preview never ran.
        $response = $this->postImport($stored, [
            ['name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st2', 'max_score' => '50'],
            ['name' => 'Quiz 9', 'component' => 'written_work', 'max_score' => '10'], // would be a NEW item
        ]);

        $response->assertRedirect(route('adviser.assessments', ['period' => 1, 'subject_id' => $this->subject->id]))
            ->assertSessionHas('error', fn($m) => str_contains($m, 'was not imported')
                && str_contains($m, "Existing assessment item 'Summative Test 1' has exam role Summative Test 1, but this upload assigns Summative Test 2."));

        // Nothing at all: the existing item untouched, the new item NOT
        // created, no score written, no upload record left behind.
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('assessments', ['name' => 'Quiz 9']);
        $this->assertSame(0, AssessmentUpload::count());
    }

    public function test_the_service_itself_refuses_a_conflict_inside_its_transaction_with_no_partial_writes(): void
    {
        // The last line of defence: even a caller that skips the
        // controller entirely cannot get the service to rewrite an item.
        $this->existingItem();
        $before  = $this->snapshot();
        $upload  = AssessmentUpload::factory()->create();
        $before['uploads'] = AssessmentUpload::count();
        $levelBefore = DB::transactionLevel(); // RefreshDatabase's own wrapper — must be back here afterwards

        $path = tempnam(sys_get_temp_dir(), 'assess_') . '.csv';
        file_put_contents($path, "lrn,last_name,first_name,Quiz 1,Quiz 2\n100000000020,Dela Cruz,Juan,19,8\n");

        try {
            (new AssessmentUploadService())->import(
                $path,
                [
                    'Quiz 1' => ['component' => 'performance_task', 'max_score' => 20.0], // conflict
                    'Quiz 2' => ['component' => 'written_work', 'max_score' => 10.0],     // new
                ],
                $this->section, $this->subject, 1, $this->section->school_year, $this->adviser->id, $upload
            );
            $this->fail('Expected AssessmentMetadataConflictException');
        } catch (AssessmentMetadataConflictException $e) {
            $this->assertCount(1, $e->conflicts());
            $this->assertSame('component', $e->conflicts()[0]['field']);
        } finally {
            @unlink($path);
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($levelBefore, DB::transactionLevel(), 'the service transaction must be fully rolled back, not left open');
    }

    // ----------------------------------------------------------------
    // 10. scope isolation — same name elsewhere is not this item
    // ----------------------------------------------------------------

    public function test_an_identically_named_item_in_another_scope_does_not_create_a_false_conflict(): void
    {
        $otherSubject = Subject::factory()->create(['grade_level' => $this->section->grade_level, 'type' => 'core']);
        $otherSection = Section::factory()->create(['school_year' => $this->section->school_year, 'grade_level' => $this->section->grade_level]);

        // "Quiz 1" as Performance Task, max 50 — under another SUBJECT,
        // another SECTION, and another TERM of this subject/section.
        foreach ([
            ['subject_id' => $otherSubject->id],
            ['section_id' => $otherSection->id],
            ['grading_period' => 2],
        ] as $elsewhere) {
            Assessment::create(array_merge([
                'subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
                'school_year' => $this->section->school_year, 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1',
                'component' => 'performance_task', 'exam_role' => null, 'is_additional_support' => true, 'max_score' => 50, 'uploaded_by' => $this->adviser->id,
            ], $elsewhere));
        }
        // ...and under another SCHOOL YEAR of this section/subject.
        Assessment::create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2025-2026', 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1',
            'component' => 'performance_task', 'exam_role' => null, 'is_additional_support' => true, 'max_score' => 50, 'uploaded_by' => $this->adviser->id,
        ]);

        $this->assertSame([], (new AssessmentItemConflictDetector())->detect(
            $this->section, $this->subject, 1, $this->section->school_year,
            ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]]
        ));

        $stored  = $this->detect("lrn,last_name,first_name,Quiz 1\n100000000020,Dela Cruz,Juan,18\n");
        $columns = [['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => '20']];
        $this->postPreview($stored, $columns)->assertOk()->assertViewIs('adviser.assessments-preview');
        $this->postImport($stored, $columns)->assertSessionHas('success');

        $this->assertDatabaseHas('assessments', [
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => $this->section->school_year, 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20,
        ]);
        // The four look-alikes are exactly as they were.
        $this->assertSame(4, Assessment::where('component', 'performance_task')->where('max_score', 50)->count());
    }

    // ----------------------------------------------------------------
    // the detector's own rules
    // ----------------------------------------------------------------

    public function test_the_detector_normalises_before_comparing_and_reports_every_protected_field(): void
    {
        $this->existingItem(['name' => 'Term Exam', 'assessment_type' => 'Term Exam', 'component' => 'examination', 'exam_role' => 'term_exam', 'max_score' => 100]);
        $detector = new AssessmentItemConflictDetector();
        $scope    = [$this->section, $this->subject, 1, $this->section->school_year];

        // Representation differences are not conflicts: a typed "100",
        // a padded role, a checkbox "0", a case-different name.
        $this->assertSame([], $detector->detect(...$scope, columnMapping: [
            'term exam' => ['component' => 'Examination ', 'exam_role' => ' TERM_EXAM', 'is_additional_support' => '0', 'max_score' => '100'],
        ]));
        $this->assertSame([], $detector->detect(...$scope, columnMapping: [
            'Term Exam' => ['component' => 'examination', 'exam_role' => 'term_exam', 'max_score' => 100.004],
        ]));

        // Every protected field that differs is its own finding, in a
        // fixed order, each with human labels — never a raw enum.
        $conflicts = $detector->detect(...$scope, columnMapping: [
            'Term Exam' => ['component' => 'examination', 'exam_role' => 'st1', 'is_additional_support' => '1', 'max_score' => '60'],
        ]);
        $this->assertSame(['exam_role', 'is_additional_support', 'max_score'], array_column($conflicts, 'field'));
        $this->assertSame(['Term Exam', 'No', '100.00'], array_column($conflicts, 'stored_label'));
        $this->assertSame(['Summative Test 1', 'Yes', '60.00'], array_column($conflicts, 'incoming_label'));
        $this->assertSame("Existing assessment item 'Term Exam' is recorded as a regular item, but this upload marks it as additional support.", $conflicts[1]['message']);
        $this->assertSame("Existing assessment item 'Term Exam' has a maximum score of 100.00, but this upload sets 60.00.", $conflicts[2]['message']);

        // A component change makes the role difference a consequence, not
        // a second finding; a non-Examination column carries no role.
        $conflicts = $detector->detect(...$scope, columnMapping: [
            'Term Exam' => ['component' => 'written_work', 'exam_role' => 'st1', 'max_score' => 100],
        ]);
        $this->assertSame(['component'], array_column($conflicts, 'field'));

        // An Examination item that never had a role vs one that now does.
        $this->existingItem(['name' => 'Final Exam', 'assessment_type' => 'Final Exam', 'component' => 'examination', 'exam_role' => null, 'max_score' => 50]);
        $conflicts = $detector->detect(...$scope, columnMapping: [
            'Final Exam' => ['component' => 'examination', 'exam_role' => 'term_exam', 'max_score' => 50],
        ]);
        $this->assertSame("Existing assessment item 'Final Exam' has no exam role, but this upload assigns Term Exam.", $conflicts[0]['message']);
    }
}
