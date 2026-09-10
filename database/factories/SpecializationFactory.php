<?php

namespace Database\Factories;

use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

class SpecializationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'track_id'   => Track::factory(),
            'name'       => 'Science, Technology, Engineering and Mathematics',
            'code'       => 'STEM',
            // "ECR alignment" work order, PART 3a — curriculum is NOT NULL
            // on this table; 'sshs' matches this factory's own default
            // STEM/code choice (a Strengthened SHS cluster name).
            'curriculum' => 'sshs',
        ];
    }
}
