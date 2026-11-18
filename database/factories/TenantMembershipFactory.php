<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantMembership>
 */
class TenantMembershipFactory extends Factory
{
    /**
     * Defaults to `invited` (the production default): a freshly created
     * membership grants nothing until explicitly activated via {@see active()}.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => Tenant::factory(),
            'status' => TenantMembership::STATUS_INVITED,
            'revoked_at' => null,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->getKey(),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TenantMembership::STATUS_ACTIVE,
            'revoked_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TenantMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TenantMembership::STATUS_SUSPENDED,
        ]);
    }
}
