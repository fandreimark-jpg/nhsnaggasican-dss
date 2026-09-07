<?php

namespace Tests\Unit;

use App\Models\Grade;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The FAILING layer" TASK 1 — Failing is a formal determination that
 * only exists once an Adviser has verified an official grade. A row with
 * is_verified = false is not an official grade yet, even if `grade`
 * happens to be populated (e.g. a stale/manually-encoded value never
 * confirmed through GradingEngine).
 */
class UnverifiedGradeIsNeverFailingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unverified_grade_is_not_failing_even_with_a_failing_value(): void
    {
        $grade = Grade::factory()->create([
            'grade' => 60.0, 'is_verified' => false, 'is_provisional' => false,
        ]);

        $this->assertFalse(InTermStatusService::isFailing($grade));
    }
}
