<?php

namespace Tests\Unit;

use App\Models\Grade;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The FAILING layer" TASK 1, Hard Constraint 4 — a provisional grade
 * came from a fallback transmutation scheme, not the subject's real one,
 * so it is not an official grade and must never be treated as Failing,
 * regardless of its numeric value.
 */
class ProvisionalGradeIsNeverFailingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provisional_grade_of_70_is_not_failing(): void
    {
        $grade = Grade::factory()->create([
            'grade' => 70.0, 'is_verified' => true, 'is_provisional' => true,
        ]);

        $this->assertFalse(InTermStatusService::isFailing($grade));
    }

    public function test_a_provisional_grade_well_below_74_is_still_not_failing(): void
    {
        $grade = Grade::factory()->create([
            'grade' => 60.0, 'is_verified' => true, 'is_provisional' => true,
        ]);

        $this->assertFalse(InTermStatusService::isFailing($grade));
    }
}
