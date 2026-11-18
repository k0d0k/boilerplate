<?php

namespace Database\Factories;

use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\ModuleHealth;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\ModuleRegistry\ModuleStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleInstallation>
 */
class ModuleInstallationFactory extends Factory
{
    protected $model = ModuleInstallation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module_key' => 'demo-module',
            'scope' => ModuleScope::TENANT,
            'scope_key' => '',
            'is_installed' => false,
            'status' => ModuleStatus::DISABLED,
            'health' => ModuleHealth::UNKNOWN,
            'activated_at' => null,
            'disabled_at' => null,
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

    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_installed' => true,
            'status' => ModuleStatus::ENABLED,
            'health' => ModuleHealth::HEALTHY,
            'activated_at' => now(),
            'disabled_at' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_installed' => true,
            'status' => ModuleStatus::DISABLED,
            'disabled_at' => now(),
        ]);
    }

    public function unhealthy(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_installed' => true,
            'status' => ModuleStatus::ENABLED,
            'health' => ModuleHealth::UNHEALTHY,
        ]);
    }
}
