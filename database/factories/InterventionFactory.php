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
}
