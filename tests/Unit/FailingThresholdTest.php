<?php

namespace Tests\Unit;

use App\Models\Grade;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The FAILING layer" TASK 1 — the formal Failing determination is
 * defined in exactly one place: InTermStatusService::isFailing().
 * DepEd's failing mark is 74 and below on the REPORTED (official) grade.
 */
class FailingThresholdTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedGrade(float $grade): Grade
    {
        return Grade::factory()->create([
            'grade' => $grade, 'is_verified' => true, 'is_provisional' => false,
        ]);
    }

    public function test_a_verified_non_provisional_grade_of_exactly_74_is_failing(): void
    {
        $this->assertTrue(InTermStatusService::isFailing($this->verifiedGrade(74.0)));
    }

    public function test_a_grade_of_75_is_not_failing(): void
    {
        $this->assertFalse(InTermStatusService::isFailing($this->verifiedGrade(75.0)));
    }

    public function test_a_grade_of_74_point_99_is_not_failing(): void
    {
        // The rule is on the reported (integer) grade — 74.99 rounds up to
        // 75 as a reported grade and must not be treated as failing.
        $this->assertFalse(InTermStatusService::isFailing($this->verifiedGrade(74.99)));
    }

    public function test_a_grade_well_below_74_is_failing(): void
    {
        $this->assertTrue(InTermStatusService::isFailing($this->verifiedGrade(60.0)));
    }

    public function test_a_null_grade_object_is_not_failing(): void
    {
        $this->assertFalse(InTermStatusService::isFailing(null));
    }
}
