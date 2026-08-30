<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssessmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject_id'       => Subject::factory(),
            'section_id'       => Section::factory(),
            'grading_period'   => 1,
            'school_year'      => '2026-2027',
            'name'             => 'Quiz 1',
            'assessment_type'  => 'Quiz',
            'component'        => 'written_work',
            'max_score'        => 20,
            'import_batch_id'  => null,
            'uploaded_by'      => User::factory(),
        ];
    }
}
