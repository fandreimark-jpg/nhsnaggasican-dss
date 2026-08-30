<?php

namespace Tests\Unit;

use App\Http\Controllers\Adviser\ReportController;
use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Covers the two pieces of logic added on top of the plain ML classifier:
 *
 * 1. applyFailingSubjectOverride() — a high overall average should never
 *    hide a real failing subject.
 * 2. computeTrend() — comparing a student's average across terms should
 *    correctly report improving / declining / stable / null.
 *
 * These are pure functions (no DB, no HTTP), so they're tested directly
 * against the controller methods without needing factories or migrations.
 */
class RiskClassificationTest extends TestCase
{
    // =============================================
    // applyFailingSubjectOverride()
    // =============================================

    public function test_no_failing_subjects_keeps_the_ml_result(): void
    {
        $controller = new ReportController();

        $this->assertSame('low', $controller->applyFailingSubjectOverride('low', 0));
        $this->assertSame('moderate', $controller->applyFailingSubjectOverride('moderate', 0));
        $this->assertSame('high', $controller->applyFailingSubjectOverride('high', 0));
    }

    public function test_one_failing_subject_floors_to_moderate(): void
    {
        $controller = new ReportController();

        // The exact scenario the prof described: grades like
        // 100, 100, 100, 100, 100, 100, 70 average to ~95.7,
        // which the ML model reads as "low" — but 1 failing
        // subject means it can never be reported as "low".
        $this->assertSame('moderate', $controller->applyFailingSubjectOverride('low', 1));

        // Already "moderate" or worse — no change needed.
        $this->assertSame('moderate', $controller->applyFailingSubjectOverride('moderate', 1));
        $this->assertSame('high', $controller->applyFailingSubjectOverride('high', 1));
    }

    public function test_two_or_more_failing_subjects_floors_to_high(): void
    {
        $controller = new ReportController();

        $this->assertSame('high', $controller->applyFailingSubjectOverride('low', 2));
        $this->assertSame('high', $controller->applyFailingSubjectOverride('moderate', 2));
        $this->assertSame('high', $controller->applyFailingSubjectOverride('high', 2));

        // More than 2 failing subjects — still just "high" (there's no
        // level worse than high, so this confirms it doesn't error out).
        $this->assertSame('high', $controller->applyFailingSubjectOverride('low', 5));
    }

    public function test_override_never_downgrades_a_more_severe_ml_result(): void
    {
        $controller = new ReportController();

        // 1 failing subject → floor is "moderate", but if the ML model
        // already said "high" (e.g. a very low average), the override
        // must not soften it back down to "moderate".
        $this->assertSame('high', $controller->applyFailingSubjectOverride('high', 1));
    }

    // =============================================
    // computeTrend()
    // =============================================

    private function history(array $averages): Collection
    {
        return collect($averages)->map(fn($avg) => (object) ['average_grade' => $avg]);
    }

    public function test_trend_is_null_with_fewer_than_two_terms(): void
    {
        $controller = new DashboardController();

        $this->assertNull($controller->computeTrend($this->history([])));
        $this->assertNull($controller->computeTrend($this->history([85.0])));
    }

    public function test_trend_detects_improving(): void
    {
        $controller = new DashboardController();

        // +4 points, above the ±1 noise threshold
        $this->assertSame('improving', $controller->computeTrend($this->history([80.0, 84.0])));
    }

    public function test_trend_detects_declining(): void
    {
        $controller = new DashboardController();

        // -6 points, above the ±1 noise threshold
        $this->assertSame('declining', $controller->computeTrend($this->history([88.0, 82.0])));
    }

    public function test_trend_is_stable_within_one_point(): void
    {
        $controller = new DashboardController();

        $this->assertSame('stable', $controller->computeTrend($this->history([85.0, 85.8])));
        $this->assertSame('stable', $controller->computeTrend($this->history([85.0, 84.2])));
        $this->assertSame('stable', $controller->computeTrend($this->history([85.0, 85.0])));
    }

    public function test_trend_uses_only_the_two_most_recent_terms(): void
    {
        $controller = new DashboardController();

        // Term 1 -> Term 2 was a big drop, but Term 2 -> Term 3 is stable.
        // The trend should reflect the MOST RECENT change, not the
        // oldest one — otherwise a student who recovered would still
        // show as "declining".
        $this->assertSame('stable', $controller->computeTrend($this->history([95.0, 70.0, 70.5])));
    }

    // =============================================
    // computeConsecutiveDecline()
    // =============================================

    public function test_consecutive_decline_is_false_with_fewer_than_three_terms(): void
    {
        $controller = new DashboardController();

        $this->assertFalse($controller->computeConsecutiveDecline($this->history([])));
        $this->assertFalse($controller->computeConsecutiveDecline($this->history([85.0])));
        $this->assertFalse($controller->computeConsecutiveDecline($this->history([85.0, 80.0])));
    }

    public function test_consecutive_decline_is_true_for_two_drops_in_a_row(): void
    {
        $controller = new DashboardController();

        // 90 -> 84 (drop) -> 78 (drop again) — the exact "quietly sliding
        // downward while still technically Moderate" pattern the prof
        // wants caught early.
        $this->assertTrue($controller->computeConsecutiveDecline($this->history([90.0, 84.0, 78.0])));
    }

    public function test_consecutive_decline_is_false_if_the_student_recovered(): void
    {
        $controller = new DashboardController();

        // Dropped once, then went back up — should NOT be flagged as
        // a consecutive decline, since the most recent movement is
        // actually improving.
        $this->assertFalse($controller->computeConsecutiveDecline($this->history([90.0, 78.0, 85.0])));
    }

    public function test_consecutive_decline_is_false_when_only_the_older_drop_qualifies(): void
    {
        $controller = new DashboardController();

        // First drop is real (90 -> 84), but the second change is within
        // the ±1 stable threshold, so this is "one decline then leveled
        // off" — not two declines in a row.
        $this->assertFalse($controller->computeConsecutiveDecline($this->history([90.0, 84.0, 83.5])));
    }
}