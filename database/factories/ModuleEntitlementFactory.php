<?php

namespace Database\Factories;

use App\Platform\ModuleRegistry\EntitlementStatus;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleEntitlement>
 */
class ModuleEntitlementFactory extends Factory
{
    protected $model = ModuleEntitlement::class;

    /**
     * Defaults to `pending` (the fail-closed production default): a fresh
     * entitlement grants nothing until explicitly granted via {@see granted()}.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module_key' => 'demo-module',
            'scope' => ModuleScope::TENANT,
            'scope_key' => '',
            'status' => EntitlementStatus::PENDING,
            'subscription_required' => false,
            'subscription_reference' => null,
            'granted_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ];
    }

    public function forModule(string $moduleKey): static
    {
        return $this->state(fn (array $attributes) => [
            'module_key' => $moduleKey,
        ]);
    }

    public function forScope(string $scope, string $scopeKey = ''): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => $scope,
            'scope_key' => $scopeKey,
        ]);
    }

    public function granted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EntitlementStatus::GRANTED,
            'granted_at' => now(),
            'expires_at' => null,
            'revoked_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EntitlementStatus::REVOKED,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EntitlementStatus::GRANTED,
            'granted_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function requiringSubscription(string $reference = 'synthetic-subscription'): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_required' => true,
            'subscription_reference' => $reference,
        ]);
    }
}
