<?php

namespace Tests\Unit;

use App\Models\Subject;
use App\Services\AssessmentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 3a of "dashboard structure and upload safeguards" —
 * AssessmentUploadService::detectFilenameSubjectMismatch() in isolation.
 * See AssessmentUploadMismatchNoticesTest for the end-to-end Verify/
 * Preview screen behavior.
 */
class AssessmentFilenameSubjectMismatchTest extends TestCase
{
    use RefreshDatabase;

    private AssessmentUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssessmentUploadService();
    }

    /** The task's own worked example. */
    public function test_filename_naming_a_different_subject_is_detected(): void
    {
        $businessMath = Subject::factory()->create(['name' => 'Business Mathematics']);
        $oralComm = Subject::factory()->create(['name' => 'Oral Communication']);
        $sectionSubjects = collect([$businessMath, $oralComm]);

        $result = $this->service->detectFilenameSubjectMismatch(
            'assessment_oral_comm_term1.xlsx',
            $businessMath,
            $sectionSubjects
        );

        $this->assertNotNull($result);
        $this->assertSame($oralComm->id, $result->id);
    }

    public function test_a_correctly_named_file_produces_no_notice(): void
    {
        $businessMath = Subject::factory()->create(['name' => 'Business Mathematics']);
        $oralComm = Subject::factory()->create(['name' => 'Oral Communication']);
        $sectionSubjects = collect([$businessMath, $oralComm]);

        $result = $this->service->detectFilenameSubjectMismatch(
            'business_math_term1_scores.xlsx',
            $businessMath,
            $sectionSubjects
        );

        $this->assertNull($result);
    }

    public function test_a_filename_with_no_recognisable_token_produces_no_notice(): void
    {
        $businessMath = Subject::factory()->create(['name' => 'Business Mathematics']);
        $oralComm = Subject::factory()->create(['name' => 'Oral Communication']);
        $sectionSubjects = collect([$businessMath, $oralComm]);

        $result = $this->service->detectFilenameSubjectMismatch(
            'q1_2026.xlsx',
            $businessMath,
            $sectionSubjects
        );

        $this->assertNull($result);
    }

    /** A word shared with the SELECTED subject doesn't count — it doesn't distinguish anything. */
    public function test_a_shared_word_with_the_selected_subject_does_not_count_as_a_distinguishing_match(): void
    {
        $general = Subject::factory()->create(['name' => 'General Mathematics']);
        $business = Subject::factory()->create(['name' => 'Business Mathematics']);
        $sectionSubjects = collect([$general, $business]);

        // "mathematics" appears in both — not a distinguishing signal on its own.
        $result = $this->service->detectFilenameSubjectMismatch(
            'mathematics_term1.xlsx',
            $general,
            $sectionSubjects
        );

        $this->assertNull($result);
    }

    /** More than one other subject matching distinctly is ambiguous — conservative means no notice. */
    public function test_ambiguous_matches_across_multiple_other_subjects_produce_no_notice(): void
    {
        $businessMath = Subject::factory()->create(['name' => 'Business Mathematics']);
        $oralComm = Subject::factory()->create(['name' => 'Oral Communication']);
        $earthScience = Subject::factory()->create(['name' => 'Earth Science']);
        $sectionSubjects = collect([$businessMath, $oralComm, $earthScience]);

        $result = $this->service->detectFilenameSubjectMismatch(
            'oral_earth_term1.xlsx',
            $businessMath,
            $sectionSubjects
        );

        $this->assertNull($result);
    }

    /** "econ" (filename) is a >=4-letter PREFIX of "economics" (subject word), not an exact match — still recognised. */
    public function test_an_abbreviation_prefix_of_a_subject_word_is_recognised(): void
    {
        $businessMath = Subject::factory()->create(['name' => 'Business Mathematics']);
        $economics = Subject::factory()->create(['name' => 'Economics']);
        $sectionSubjects = collect([$businessMath, $economics]);

        $result = $this->service->detectFilenameSubjectMismatch(
            'econ_review.xlsx',
            $businessMath,
            $sectionSubjects
        );

        $this->assertNotNull($result);
        $this->assertSame($economics->id, $result->id);
    }
}
