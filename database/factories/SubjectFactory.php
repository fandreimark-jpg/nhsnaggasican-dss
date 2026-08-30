<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'              => fake()->unique()->randomElement([
                'General Mathematics', 'Effective Communication', 'Physical Science',
                'Personal Development', 'Earth Science', 'Statistics and Probability',
            ]) . ' ' . fake()->unique()->numberBetween(1, 9999),
            'type'              => 'core',
            'grade_level'       => fake()->randomElement([11, 12]),
            'track_id'          => null,
            'specialization_id' => null,
        ];
    }
}
