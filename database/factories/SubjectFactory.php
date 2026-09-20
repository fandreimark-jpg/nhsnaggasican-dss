<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectFactory extends Factory
{
    /**
     * TERMS TAUGHT for a factory subject ("Subject applicability"
     * refactor). Every term by default — the same rule the migration
     * backfilled for existing subjects (a subject applies in every term
     * unless the Admin narrows it), so a bare Subject::factory()->create()
     * keeps resolving to every section in every term exactly as before
     * Terms Taught existed. A test that wants a Term-1-only subject says
     * so: Subject::factory()->taughtIn([1])->create() — its callback runs
     * after the default one and narrows the set.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (\App\Models\Subject $subject) {
            $subject->syncTerms(\App\Models\AcademicTerm::TERM_NUMBERS);
        });
    }

    public function taughtIn(array $terms): static
    {
        $terms = array_values(array_map('intval', $terms));

        return $this->afterCreating(fn(\App\Models\Subject $subject) => $subject->syncTerms($terms));
    }

    public function definition(): array
    {
        return [
            // The number keeps names unique; the word list itself is not
            // drawn uniquely (a unique() over six words overflowed on the
            // seventh factory subject, even with 'name' overridden).
            'name'              => fake()->randomElement([
                'General Mathematics', 'Effective Communication', 'Physical Science',
                'Personal Development', 'Earth Science', 'Statistics and Probability',
            ]) . ' ' . fake()->unique()->numberBetween(1, 99999),
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
