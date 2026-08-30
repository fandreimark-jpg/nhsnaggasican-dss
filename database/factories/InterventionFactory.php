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
            'created_by'            => User::factory()->principal(),
        ];
    }
}
