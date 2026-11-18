<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\TenantDatabaseCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDatabaseCredential>
 */
class TenantDatabaseCredentialFactory extends Factory
{
    protected $model = TenantDatabaseCredential::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'username' => 'lba_'.fake()->unique()->numerify('######'),
            'password' => fake()->password(16),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }
}
