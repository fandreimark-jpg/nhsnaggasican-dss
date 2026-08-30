<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssessmentScoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'student_id'    => Student::factory(),
            'score'         => 18,
        ];
    }
}
