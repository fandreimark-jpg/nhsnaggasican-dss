<?php

namespace Database\Factories;

use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lrn'         => fake()->unique()->numerify('############'), // exactly 12 digits
            'last_name'   => fake()->lastName(),
            'first_name'  => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'section_id'  => Section::factory(),
            'gender'      => fake()->randomElement(['male', 'female']),
        ];
    }
}
