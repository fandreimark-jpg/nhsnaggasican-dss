<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GradeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id'     => Student::factory(),
            'subject_id'     => Subject::factory(),
            'section_id'     => Section::factory(),
            'encoded_by'     => User::factory(),
            'grading_period' => 1,
            'grade'          => 85.0,
            'school_year'    => '2026-2027',
            'is_verified'    => false,
            'is_provisional' => false,
        ];
    }
}
