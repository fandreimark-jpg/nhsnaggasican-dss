<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * "mimes: bug" fix — the real official DepEd SSHS E-Class Record's actual
 * bytes sniff as application/octet-stream, not a recognised spreadsheet
 * MIME type (confirmed directly: Validator::make(['file' => <real
 * fixture>], ['file' => 'mimes:xlsx,xls'])->passes() === false). Every
 * prior ECR test called EcrReaderService/EcrProfileDetector directly or
 * went through dss:ecr-dry-run — none of them ever pushed the REAL file
 * through the HTTP `mimes:` validation layer on Adviser\
 * AssessmentController::detect(), so this survived undetected through all
 * of Part 5. This test exists specifically to close that gap: it is an
 * HTTP request against the real route, with the real checked-in fixture,
 * not a service-layer or CLI call.
 */
class EcrHttpUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_real_ecr_template_uploads_successfully_through_the_http_detect_route(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor($section->school_year);

        $file = new UploadedFile(
            base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx'),
            'SSHS-E-Class-Record-SY-2026-2027.xlsx',
            null, // let the real extension drive validation, not a fake mime
            null,
            true
        );

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $file,
        ]);

        // Must NOT be rejected by file validation. It legitimately has no
        // assessment columns to detect (the checked-in fixture is a genuinely
        // blank template), so the controller's own "no columns found" branch
        // is the expected outcome here -- the point of this test is that the
        // request reaches that branch at all, instead of failing validation
        // before EcrProfileDetector/EcrReaderService are ever reached.
        $response->assertSessionDoesntHaveErrors('file');
        $response->assertRedirect('/adviser/assessments?period=1&subject_id=' . $subject->id);
        $response->assertSessionHas('error', 'No assessment columns were found in that file. Expected: lrn, last_name, first_name, then one column per assessment item.');
    }
}
