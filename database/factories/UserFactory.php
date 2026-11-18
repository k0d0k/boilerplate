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
     * Synthetic identities default to no role (denied on every panel) and active.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => null,
            'is_active' => true,
        ];
    }

    /**
     * Assign an explicit provisional role (see App\Platform\Access\PanelAccessPolicy).
     */
    public function role(string $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
        ]);
    }

    /**
     * Set an explicit language preference (Phase 1B-3).
     */
    public function locale(string $locale): static
    {
        return $this->state(fn (array $attributes) => [
            'locale' => $locale,
        ]);
    }

    /**
     * Mark the identity as an invited, credential-less account that must set
     * its own password once before it can sign in (WS4 P0 staff invitation).
     */
    public function mustSetPassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_set_password' => true,
        ]);
    }

    /**
     * Suspend the identity so panel access must fail closed.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
