<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Only the numeric suffix needs to be unique — the name alone
            // has just 5 possible values, so marking IT unique() exhausts
            // (throws OverflowException) the moment a single test creates
            // more than 5 sections via nested factory calls.
            'name'               => fake()->randomElement(['Narra', 'Molave', 'Acacia', 'Mahogany', 'Ipil']) . '-' . fake()->unique()->numberBetween(1, 99999),
            'grade_level'        => fake()->randomElement([11, 12]),
            'track_id'           => null,
            'specialization_id'  => null,
            'adviser_id'         => User::factory(),
            'school_year'        => '2026-2027',
        ];
    }
}
