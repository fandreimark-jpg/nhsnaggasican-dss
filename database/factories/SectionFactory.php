<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'               => fake()->unique()->randomElement(['Narra', 'Molave', 'Acacia', 'Mahogany', 'Ipil']) . '-' . fake()->unique()->numberBetween(1, 9999),
            'grade_level'        => fake()->randomElement([11, 12]),
            'track_id'           => null,
            'specialization_id'  => null,
            'adviser_id'         => User::factory(),
            'school_year'        => '2026-2027',
        ];
    }
}
