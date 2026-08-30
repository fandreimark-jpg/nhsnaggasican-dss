<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssessmentUploadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'section_id'         => Section::factory(),
            'subject_id'         => Subject::factory(),
            'uploaded_by'        => User::factory(),
            'grading_period'     => 1,
            'school_year'        => '2026-2027',
            'original_filename'  => 'assessment.csv',
            'column_mapping'     => ['Quiz 1' => 'written_work'],
            'status'             => 'imported',
        ];
    }
}
