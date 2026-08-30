<?php

namespace Database\Factories;

use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

class SpecializationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'track_id' => Track::factory(),
            'name'     => 'Science, Technology, Engineering and Mathematics',
            'code'     => 'STEM',
        ];
    }
}
