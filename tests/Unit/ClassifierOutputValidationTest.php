<?php

namespace Tests\Unit;

use App\Http\Controllers\Adviser\ReportController;
use Tests\TestCase;

/**
 * Pre-demo audit (2026-09-17), section 14 — what classify.py hands back
 * is validated as a whole before a single RiskResult row is written. A
 * malformed payload is a failed analysis (the adviser sees the existing
 * "risk analysis failed to generate" warning), never a partial save.
 */
class ClassifierOutputValidationTest extends TestCase
{
    private function controller(): ReportController
    {
        return app(ReportController::class);
    }

    private function good(int $id, array $overrides = []): array
    {
        return array_merge(
            ['student_id' => $id, 'average_grade' => 82.5, 'risk_level' => 'low', 'confidence' => 91.5],
            $overrides
        );
    }

    public function test_a_well_formed_result_set_passes(): void
    {
        $this->assertTrue($this->controller()->classifierOutputIsValid([$this->good(1), $this->good(2)], [1, 2]));
    }

    public function test_confidence_may_be_absent_or_null_but_never_out_of_range_or_non_numeric(): void
    {
        $c = $this->controller();
        $ok = $this->good(1);
        unset($ok['confidence']);
        $this->assertTrue($c->classifierOutputIsValid([$ok], [1]));
        $this->assertTrue($c->classifierOutputIsValid([$this->good(1, ['confidence' => null])], [1]));
        $this->assertTrue($c->classifierOutputIsValid([$this->good(1, ['confidence' => 0])], [1]));
        $this->assertTrue($c->classifierOutputIsValid([$this->good(1, ['confidence' => 100])], [1]));

        $this->assertFalse($c->classifierOutputIsValid([$this->good(1, ['confidence' => 100.01])], [1]));
        $this->assertFalse($c->classifierOutputIsValid([$this->good(1, ['confidence' => -1])], [1]));
        $this->assertFalse($c->classifierOutputIsValid([$this->good(1, ['confidence' => 'high'])], [1]));
    }

    public function test_structural_defects_are_rejected(): void
    {
        $c = $this->controller();
        $this->assertFalse($c->classifierOutputIsValid('not json', [1]), 'non-array');
        $this->assertFalse($c->classifierOutputIsValid([], [1]), 'missing student');
        $this->assertFalse($c->classifierOutputIsValid([$this->good(1), $this->good(1)], [1, 2]), 'duplicate student');
        $this->assertFalse($c->classifierOutputIsValid([$this->good(99)], [1]), 'unknown student');
        $this->assertFalse($c->classifierOutputIsValid([$this->good(1, ['risk_level' => 'critical'])], [1]), 'unknown risk level');
        $this->assertFalse($c->classifierOutputIsValid([$this->good(1, ['average_grade' => 'n/a'])], [1]), 'non-numeric average');
        $this->assertFalse($c->classifierOutputIsValid([['student_id' => 1]], [1]), 'missing keys');
    }
}
