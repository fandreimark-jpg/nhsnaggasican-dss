<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lastName  = fake()->lastName();
        $firstName = fake()->firstName();

        return [
            'name' => $firstName . ' ' . $lastName,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * 'role' is intentionally excluded from User::$fillable (see the model)
     * so it can never be mass-assigned from request input. Setting it here
     * via direct property assignment — same pattern UserController uses —
     * bypasses that guard safely, since this only runs in tests/seeding.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            $user->role ??= 'adviser';
        });
    }

    /**
     * Indicate that the user is an admin.
     * Uses afterMaking (not state()) for the same reason as configure()
     * above — 'role' isn't in $fillable, so state()'s attribute merge
     * would get silently dropped; direct property assignment bypasses that.
     */
    public function admin(): static
    {
        return $this->afterMaking(function (User $user) {
            $user->role = 'admin';
        });
    }

    /** Indicate that the user is a principal. Same reasoning as admin() above. */
    public function principal(): static
    {
        return $this->afterMaking(function (User $user) {
            $user->role = 'principal';
        });
    }

}
