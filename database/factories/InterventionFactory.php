<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InterventionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id'            => Student::factory(),
            'subject_id'            => null,
            'risk_result_id'        => null,
            'recommended_type'      => 'remediation',
            'recommendation_reason' => 'Failing subject(s) detected.',
            'status'                => 'recommended',
            'principal_notes'       => null,
            // "Master pass" PART 1 — every intervention this codebase
            // creates is Principal-created; no factory state ever sets
            // 'system' (see Intervention::ORIGINS' docblock).
            'origin'                => 'principal',
            // Inactive by default: 'created_by' is just an audit-trail FK
            // — its identity doesn't matter to most tests, but an ACTIVE
            // principal here would compete for the same
            // role_singleton_key UNIQUE slot as whichever principal a
            // test separately creates via User::factory()->principal()
            // for actingAs(). Tests that DO care who created it should
            // override this explicitly.
            'created_by'            => User::factory()->principal()->inactive(),
        ];
    }

    /**
     * "Correctness and interface pass" TASK 2 — every intervention starts
     * 'recommended' with no decided_by (see Intervention::awaitingDecision()),
     * which now blocks acknowledgement/delivery. Tests exercising anything
     * past that point need a genuinely decided record, not the bare
     * default — this state stamps exactly what
     * Principal\InterventionController::update() itself stamps.
     */
    public function decided(string $status = 'approved'): static
    {
        return $this->state(fn () => [
            'status'     => $status,
            'decided_by' => User::factory()->principal()->inactive(),
            'decided_at' => now(),
        ]);
    }
}
