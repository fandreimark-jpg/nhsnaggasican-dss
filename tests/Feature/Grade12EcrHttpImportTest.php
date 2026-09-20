<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * End-to-end HTTP test of the real Detect -> Preview -> Import pipeline
 * against tests/Fixtures/GRADE-12-SANITIZED.xlsx (a synthetic fixture
 * matching GRADE-12-AGILA.xlsx's real structure exactly, but with entirely
 * fake names -- never the real school file or real student data).
 */
// NOTE ('SSHS ECR grading correction', 2026-09-20): these Grade 12
// sections carry an EXPLICIT k12_2013 curriculum because this file's
// intent is the DO 8, s. 2015 (legacy) path. An unset curriculum in
// SY 2026-2027 now resolves to DO 015 for both grade levels.
class Grade12EcrHttpImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/GRADE-12-SANITIZED.xlsx'),
            'GRADE-12-SANITIZED.xlsx',
            null, // real extension drives validation, not a forced mime
            null,
            true
        );
    }

    /** Non-Academic track + non-core, non-keyword subject resolves to do8_tvl_sports_arts_other = 20/60/20 -- matches the fixture's own weights exactly. */
    private function makeMatchingSectionAndSubject(): array
    {
        $track = Track::factory()->create(['code' => 'TECHPRO']);
        $section = Section::factory()->create(['curriculum' => 'k12_2013', 
            'name' => 'AGILA', 'grade_level' => 12, 'track_id' => $track->id, 'school_year' => '2026-2027',
        ]);
        $subject = Subject::factory()->create([
            'name' => 'Community Engagement Solidarity and Citizenship', 'type' => 'elective',
            'grade_level' => 12, 'track_id' => $track->id,
        ]);
        AcademicTerm::ensureExistFor('2026-2027');

        return [$section, $subject, $track];
    }

    private function seedMatchingStudents(Section $section): void
    {
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ALPHA', 'first_name' => 'JUAN']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'BRAVO', 'first_name' => 'PEDRO']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'CHARLIE', 'first_name' => 'MARK']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'DELTA', 'first_name' => 'JOSE']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ECHO', 'first_name' => 'MARIA']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'FOXTROT', 'first_name' => 'ANA']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'GOLF', 'first_name' => 'ROSA']);
    }

    public function test_the_real_shaped_grade_12_file_is_detected_and_its_columns_recognised(): void
    {
        [$section, $subject] = $this->makeMatchingSectionAndSubject();
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section->update(['adviser_id' => $adviser->id]);
        $this->seedMatchingStudents($section);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $this->fixtureFile(),
        ]);

        $response->assertSessionDoesntHaveErrors('file');
        $response->assertOk();
        $response->assertSee('Detected ECR Format');
        $response->assertSee('Grade 12 Class Record');
        // 4 WW + 3 PT + 3 EX = 10 columns; item 5 (unused, blank HPS) excluded.
        $response->assertSee('WW1'); $response->assertSee('WW4'); $response->assertDontSee('WW5');
        $response->assertSee('ST1'); $response->assertSee('TE');
    }

    public function test_resolved_dss_weights_matching_the_file_produce_no_mismatch_warning(): void
    {
        [$section, $subject] = $this->makeMatchingSectionAndSubject();
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section->update(['adviser_id' => $adviser->id]);
        $this->seedMatchingStudents($section);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $this->fixtureFile(),
        ]);

        $response->assertOk();
        $response->assertDontSee('Weight mismatch');
    }

    public function test_a_resolved_dss_weight_that_disagrees_with_the_file_is_refused_not_silently_changed(): void
    {
        // Academic track + non-core elective resolves to do8_academic_other
        // (25/45/30) -- genuinely disagrees with the fixture's 20/60/20.
        $track = Track::factory()->create(['code' => 'ACAD']);
        $section = Section::factory()->create(['curriculum' => 'k12_2013', 'name' => 'AGILA', 'grade_level' => 12, 'track_id' => $track->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create([
            'name' => 'Community Engagement Solidarity and Citizenship', 'type' => 'elective',
            'grade_level' => 12, 'track_id' => $track->id,
        ]);
        AcademicTerm::ensureExistFor('2026-2027');
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section->update(['adviser_id' => $adviser->id]);
        $this->seedMatchingStudents($section);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $this->fixtureFile(),
        ]);

        // "SSHS ECR grading correction" (2026-09-20): a declared split that
        // contradicts the configured profile REFUSES the upload — it is no
        // longer a dismissible notice — and rewrites nothing.
        $response->assertRedirect('/adviser/assessments?period=1&subject_id=' . $subject->id);
        $response->assertSessionHas('error', fn($m) => str_contains($m, 'Weight mismatch') && str_contains($m, $subject->name));
        $this->assertCount(0, \Illuminate\Support\Facades\Storage::disk('local')->files('temp_assessment_uploads'));
        $this->assertSame($subject->subject_group, $subject->fresh()->subject_group, "No master-data change.");
    }

    public function test_unresolved_learner_names_are_reported_when_no_matching_student_exists(): void
    {
        [$section, $subject] = $this->makeMatchingSectionAndSubject();
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section->update(['adviser_id' => $adviser->id]);
        // Only 6 of the fixture's 7 names seeded -- GOLF, ROSA is missing.
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ALPHA', 'first_name' => 'JUAN']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'BRAVO', 'first_name' => 'PEDRO']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'CHARLIE', 'first_name' => 'MARK']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'DELTA', 'first_name' => 'JOSE']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ECHO', 'first_name' => 'MARIA']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'FOXTROT', 'first_name' => 'ANA']);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $this->fixtureFile(),
        ]);

        $response->assertOk();
        $response->assertSee('could not be matched');
        $response->assertSee('GOLF, ROSA MENDOZA');
    }

    public function test_import_creates_scores_preserves_blanks_and_rejects_scores_above_hps(): void
    {
        [$section, $subject] = $this->makeMatchingSectionAndSubject();
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section->update(['adviser_id' => $adviser->id]);
        $this->seedMatchingStudents($section);

        $detect = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $this->fixtureFile(),
        ]);
        $detect->assertOk();

        // Extract the stored filename the Verify screen would round-trip
        // as a hidden field, and every column's confirmed component/max,
        // exactly as the real Verify form would submit them.
        $storedFilename = $detect->viewData('storedFilename');
        $columns = $detect->viewData('columns');

        $mapping = [];
        foreach ($columns as $col) {
            $mapping[] = [
                'name'       => $col['name'],
                'component'  => $col['guessed_component'] ?? 'written_work',
                'exam_role'  => $col['guessed_exam_role'] ?? null,
                'max_score'  => (string) $col['file_max_score'],
            ];
        }

        $import = $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => $storedFilename,
            'columns'         => $mapping,
        ]);

        $import->assertRedirect();

        // A blank WW3 cell for ALPHA, JUAN must never become a 0 score row.
        $juan = Student::where('last_name', 'ALPHA')->first();
        $ww3Assessment = \App\Models\Assessment::where('subject_id', $subject->id)->where('name', 'WW3')->first();
        $this->assertNotNull($ww3Assessment);
        $this->assertDatabaseMissing('assessment_scores', [
            'student_id' => $juan->id, 'assessment_id' => $ww3Assessment->id,
        ]);

        // The last fixture student's ST1 score (32) exceeds its HPS (30) -- rejected, not clamped or silently accepted.
        $rejectedStudent = Student::where('last_name', 'GOLF')->first();
        $st1Assessment = \App\Models\Assessment::where('subject_id', $subject->id)->where('name', 'ST1')->first();
        $this->assertDatabaseMissing('assessment_scores', [
            'student_id' => $rejectedStudent->id, 'assessment_id' => $st1Assessment->id, 'score' => 32,
        ]);

        // A genuinely valid score DID get imported.
        $this->assertDatabaseHas('assessment_scores', [
            'student_id' => $juan->id, 'assessment_id' => \App\Models\Assessment::where('subject_id', $subject->id)->where('name', 'WW1')->first()->id,
            'score' => 86,
        ]);
    }

    /**
     * Re-uploading the exact same Grade 12 file must not duplicate
     * Assessment/AssessmentScore rows -- both go through Assessment::
     * updateOrCreate()/AssessmentScore::updateOrCreate() in import(),
     * the SAME idempotency mechanism the SSHS and flat-CSV paths already
     * rely on, unchanged by this pass.
     */
    public function test_reuploading_the_same_grade_12_file_does_not_duplicate_scores(): void
    {
        [$section, $subject] = $this->makeMatchingSectionAndSubject();
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section->update(['adviser_id' => $adviser->id]);
        $this->seedMatchingStudents($section);

        $doImport = function () use ($adviser, $subject) {
            $detect = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
                'subject_id'     => $subject->id,
                'grading_period' => 1,
                'file'           => $this->fixtureFile(),
            ]);
            $columns = $detect->viewData('columns');
            $mapping = array_map(fn($col) => [
                'name'      => $col['name'],
                'component' => $col['guessed_component'] ?? 'written_work',
                'exam_role' => $col['guessed_exam_role'] ?? null,
                'max_score' => (string) $col['file_max_score'],
            ], $columns);

            return $this->actingAs($adviser)->post('/adviser/assessments/import', [
                'subject_id'      => $subject->id,
                'grading_period'  => 1,
                'stored_filename' => $detect->viewData('storedFilename'),
                'columns'         => $mapping,
            ]);
        };

        $doImport()->assertRedirect();
        $countAfterFirst = \App\Models\AssessmentScore::count();

        $doImport()->assertRedirect();
        $countAfterSecond = \App\Models\AssessmentScore::count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'Re-uploading the same file must update existing rows, never duplicate them.');
    }
}
