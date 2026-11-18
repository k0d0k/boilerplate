<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 *
 * A tenant built here represents a FULLY PROVISIONED tenant: its isolated
 * clinical database is registered centrally and marked migrated, mirroring the
 * state every production tenant reaches via `lba:tenant:provision --migrate`.
 * This matters because the Clinic gateway fails closed — only a verified,
 * migrated tenant database may reach clinical modules — so a bare tenant with
 * no registry row is correctly denied. Use {@see withoutDatabase()} to build a
 * not-yet-provisioned tenant (e.g. to assert the readiness denial).
 */
class TenantFactory extends Factory
{
    /** Whether the created tenant's isolated database is registered + migrated. */
    protected bool $provisionsDatabase = true;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => Tenant::STATUS_ACTIVE,
            'metadata' => null,
        ];
    }

    /**
     * Keep the provision flag across the clones Laravel's factory creates for
     * every `state()`/`for()`/relationship call, so the running `create()`
     * instance always knows whether a registry row is due.
     */
    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->provisionsDatabase = $this->provisionsDatabase;

        return $instance;
    }

    /**
     * Register the tenant's isolated database centrally once the tenant exists.
     * The gate lives here — not in an `afterCreating` callback — because factory
     * clones share no instance state with the callbacks they register, and
     * `store()` runs exactly once per logical create call.
     *
     * @param  Collection<int, Tenant>  $results
     */
    protected function store(Collection $results): void
    {
        parent::store($results);

        if (! $this->provisionsDatabase) {
            return;
        }

        $results->each(function (Tenant $tenant): void {
            $names = app(TenantDatabaseName::class);

            TenantDatabase::query()->create([
                'tenant_id' => $tenant->getKey(),
                // Record the REAL physical name the current configuration
                // derives from the tenant's ULID (cPanel account-prefixed, or a
                // per-tenant SQLite file), exactly as a genuine provisioning run
                // would. A synthetic name would look like a prefix change to the
                // provisioning guard and make a legitimate retry fail closed.
                'database_name' => $names->for((string) $tenant->getKey()),
                'driver' => $names->driver(),
                'status' => TenantDatabase::STATUS_MIGRATED,
                'last_migrated_at' => now(),
            ]);
        });
    }

    /**
     * Build a tenant whose isolated database is not registered — the readiness
     * gate must fail closed for it until provisioning is recorded.
     */
    public function withoutDatabase(): static
    {
        $instance = $this->newInstance();
        $instance->provisionsDatabase = false;

        return $instance;
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Tenant::STATUS_SUSPENDED,
        ]);
    }
}
