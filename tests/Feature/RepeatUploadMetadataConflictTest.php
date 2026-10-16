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
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;

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
