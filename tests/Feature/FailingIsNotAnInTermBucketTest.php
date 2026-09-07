<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Workflow completion pass" TASK 2 — Failing is an OUTCOME (only
 * knowable after an official grade is encoded at the end of the term),
 * not a fourth In-Term Status bucket. getInTermStatusSummary() must
 * return exactly the three early-warning buckets it always has; Failing
 * is tracked separately by getFailingSummary() and rendered in its own
 * "3. Trend and outcomes" section of the dashboard, never inside the
 * "In-Term Status — Term N" group.
 */
class FailingIsNotAnInTermBucketTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_term_status_summary_returns_exactly_three_buckets_never_failing(): void
    {
        $summary = (new DashboardAnalyticsService())->getInTermStatusSummary();

        $keys = array_keys($summary);
        $this->assertContains('inTermOnTrack', $keys);
        $this->assertContains('inTermNeedsAttention', $keys);
        $this->assertContains('inTermAtRisk', $keys);
        $this->assertArrayNotHasKey('failingCount', $summary);
        $this->assertArrayNotHasKey('inTermFailing', $summary);
    }

    public function test_dashboard_page_never_places_the_failing_tile_inside_the_in_term_status_group(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 12]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['grade_level' => 12]);
        $adviser = User::factory()->create();
        AcademicTerm::ensureExistFor('2026-2027');

        Grade::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 60.0, 'is_verified' => true, 'is_provisional' => false,
        ]);

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $response->assertOk();
        $content = $response->getContent();

        $inTermHeadingPos = strpos($content, 'In-Term Status — Term');
        $outcomesHeadingPos = strpos($content, '3. Trend and outcomes');
        $failedThisTermPos = strpos($content, 'Failed this term');

        $this->assertNotFalse($inTermHeadingPos);
        $this->assertNotFalse($outcomesHeadingPos);
        $this->assertNotFalse($failedThisTermPos);

        // The Failing tile must render AFTER the "3. Trend and outcomes"
        // heading, and therefore after (not inside) the In-Term Status
        // group that comes before it.
        $this->assertGreaterThan($outcomesHeadingPos, $failedThisTermPos);
        $this->assertGreaterThan($inTermHeadingPos, $outcomesHeadingPos);
    }

    public function test_the_framing_line_appears_above_the_failing_tile(): void
    {
        $principal = User::factory()->principal()->create();
        Section::factory()->create(['school_year' => '2026-2027']);

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $response->assertOk();
        $response->assertSee('This is an outcome, not an early warning.', false);
    }
}
