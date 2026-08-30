<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TrackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Academic Track',
            'code' => 'ACAD',
        ];
    }
}
