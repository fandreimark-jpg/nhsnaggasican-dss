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
            // A factory default is a deliberate, visible test-setup
            // choice, not the production "silently default to Core"
            // anti-pattern "subject classification and grading weights
            // cleanup" removed elsewhere (see SubjectGroupWeight::resolve()
            // and classificationError()) -- core_academic is genuinely
            // correct for the paired type default above, so a bare
            // Subject::factory()->create() keeps resolving grades exactly
            // as before that cleanup, for any test that doesn't care about
            // classification and didn't override either field.
            'subject_group'     => 'core_academic',
            'track_id'          => null,
            'specialization_id' => null,
        ];
    }
}
