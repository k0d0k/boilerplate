<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\TenantDatabase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDatabase>
 */
class TenantDatabaseFactory extends Factory
{
    protected $model = TenantDatabase::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'database_name' => 'lba_tenant_'.fake()->unique()->numerify('####################'),
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_PENDING,
            'metadata' => null,
        ];
    }

    public function provisioned(): static
    {
        return $this->state(fn () => ['status' => TenantDatabase::STATUS_PROVISIONED]);
    }

    public function migrated(): static
    {
        return $this->state(fn () => [
            'status' => TenantDatabase::STATUS_MIGRATED,
            'last_migrated_at' => now(),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }
}
