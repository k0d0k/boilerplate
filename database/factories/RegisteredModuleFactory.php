<?php

namespace Database\Factories;

use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleHealth;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\ModuleRegistry\ModuleStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RegisteredModule>
 */
class RegisteredModuleFactory extends Factory
{
    protected $model = RegisteredModule::class;

    /**
     * Defaults to the fail-closed state: not installed, unknown health and
     * disabled. Tests must opt in via {@see installed()} / {@see enabled()}.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'module_key' => Str::kebab($name).'-'.Str::lower(Str::random(6)),
            'name' => Str::headline($name),
            'version' => '0.1.0',
            'min_platform_version' => '1.0.0',
            'allowed_panels' => ['clinic'],
            'required_permissions' => [],
            'required_dependencies' => [],
            'supported_locales' => ['id', 'ms-MY', 'en'],
            'scope_compatibility' => [ModuleScope::TENANT],
            'entitlement_requirement' => 'entitlement',
            'is_installed' => false,
            'health' => ModuleHealth::UNKNOWN,
            'status' => ModuleStatus::DISABLED,
            'metadata' => null,
        ];
    }

    public function forKey(string $key): static
    {
        return $this->state(fn (array $attributes) => [
            'module_key' => $key,
        ]);
    }

    public function installed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_installed' => true,
        ]);
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_installed' => true,
            'health' => ModuleHealth::HEALTHY,
            'status' => ModuleStatus::ENABLED,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_installed' => true,
            'status' => ModuleStatus::DISABLED,
        ]);
    }

    public function unhealthy(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_installed' => true,
            'health' => ModuleHealth::UNHEALTHY,
            'status' => ModuleStatus::ENABLED,
        ]);
    }

    public function degraded(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_installed' => true,
            'health' => ModuleHealth::DEGRADED,
            'status' => ModuleStatus::ENABLED,
        ]);
    }

    /**
     * @param  list<string>  $panels
     */
    public function forPanels(array $panels): static
    {
        return $this->state(fn (array $attributes) => [
            'allowed_panels' => $panels,
        ]);
    }

    public function requiringSubscription(): static
    {
        return $this->state(fn (array $attributes) => [
            'entitlement_requirement' => 'subscription',
        ]);
    }
}
