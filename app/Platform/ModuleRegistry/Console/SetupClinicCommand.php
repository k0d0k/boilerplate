<?php

namespace App\Platform\ModuleRegistry\Console;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Onboarding\ClinicSetupResult;
use App\Platform\Onboarding\ClinicSetupService;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 3 — explicit, idempotent clinic setup for ONE partner.
 *
 * Provisions the tenant's isolated database, migrates it, registers readiness,
 * activates the permitted clinic modules and (optionally) invites a Clinic
 * Admin — all through {@see ClinicSetupService}, the same orchestration the
 * Principal "Setup Clinic" action uses. Running it twice is safe: an existing
 * database is reused, migrations are re-applied idempotently and nothing is
 * dropped or overwritten, so a retry can never duplicate a database or lose
 * data.
 *
 * On a cPanel host the tenant database is created through the UAPI adapter
 * (the Laravel runtime credential is never given global CREATE); elsewhere the
 * raw-SQL/SQLite adapter is used for local/dev.
 *
 * Local/testing convenience: `--fixture` creates a synthetic tenant, branch and
 * owner so the Clinic panel is usable end-to-end without touching real data.
 * The command refuses to run in production unless `--force` is given.
 */
class SetupClinicCommand extends Command
{
    protected $signature = 'lba:clinic:setup
        {--tenant= : Tenant ULID or slug to set up the clinic for}
        {--fixture : Create a synthetic tenant, branch and owner, then set it up}
        {--owner= : Existing user id or email to attach as the partner owner (with --fixture)}
        {--admin-email= : Email of the Clinic Admin to invite (omit to skip the invitation)}
        {--admin-name= : Name of the Clinic Admin to invite}
        {--force : Allow running in the production environment}';

    protected $description = 'Provision, migrate and activate the clinic (Patients, Appointments) for one partner';

    public function handle(ClinicSetupService $setup, ResolvesTenantDatabase $resolver): int
    {
        if ($this->getLaravel()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }

        $tenant = $this->option('fixture')
            ? $this->createFixtureTenant()
            : $this->findTenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        $result = $setup->setup(
            $tenant,
            (string) ($this->option('admin-email') ?? ''),
            (string) ($this->option('admin-name') ?? ''),
        );

        $this->reportSteps($result);

        if ($this->option('fixture') && $result->ready) {
            $this->buildFixtureContext($tenant, $resolver);
        }

        if (! $result->succeeded()) {
            $failed = $result->failedStep();

            if ($failed !== null) {
                $this->error("Clinic setup stopped at [{$failed->key}]: {$failed->detail}");
            }

            return self::FAILURE;
        }

        $this->info("Clinic setup complete for partner [{$tenant->getKey()}].");

        return self::SUCCESS;
    }

    private function findTenant(): ?Tenant
    {
        $argument = (string) ($this->option('tenant') ?? '');

        if ($argument === '') {
            $this->error('Provide --tenant=<id|slug> or --fixture.');

            return null;
        }

        $tenant = Tenant::query()
            ->where('id', $argument)
            ->orWhere('slug', $argument)
            ->first();

        if ($tenant === null) {
            $this->error("Tenant [{$argument}] was not found.");
        }

        return $tenant;
    }

    private function createFixtureTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Synthetic Clinic '.Str::upper(Str::random(4)),
            'slug' => 'synthetic-clinic-'.Str::lower(Str::random(6)),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
    }

    /**
     * After a successful fixture setup, add the branch and owner that make the
     * Clinic panel usable end-to-end.
     */
    private function buildFixtureContext(Tenant $tenant, ResolvesTenantDatabase $resolver): void
    {
        $tenantId = (string) $tenant->getKey();
        $resolver->configure($tenantId);

        $branch = TenantBranch::query()->create([
            'name' => 'Synthetic Main Branch',
            'code' => 'BR-'.Str::upper(Str::random(5)),
            'status' => TenantBranch::STATUS_ACTIVE,
        ]);

        $owner = $this->resolveOwner($tenantId, (string) $tenant->slug);

        $this->info("Synthetic fixture ready: partner [{$tenantId}], branch [{$branch->getKey()}], owner [{$owner->email}].");
    }

    /**
     * Resolve or create the fixture owner and bind them to the tenant.
     */
    private function resolveOwner(string $tenantId, string $slug): User
    {
        $ownerReference = (string) ($this->option('owner') ?? '');

        if ($ownerReference !== '') {
            $owner = User::query()
                ->where('id', $ownerReference)
                ->orWhere('email', $ownerReference)
                ->first();

            if ($owner === null) {
                $this->warn("Owner [{$ownerReference}] was not found; creating a synthetic owner instead.");
            } else {
                $this->attachOwnerMembership($owner, $tenantId);

                return $owner;
            }
        }

        $owner = User::query()->create([
            'name' => 'Synthetic Clinic Owner',
            'email' => 'owner+'.$slug.'@example.test',
            'password' => Hash::make('password'),
            'role' => RoleKey::TENANT_OWNER,
            'is_active' => true,
        ]);

        $this->warn('Created a synthetic non-production owner with the default password; change it or remove the fixture before any shared use.');

        $this->attachOwnerMembership($owner, $tenantId);

        return $owner;
    }

    private function attachOwnerMembership(User $owner, string $tenantId): void
    {
        $membership = TenantMembership::query()->firstOrCreate(
            ['user_id' => $owner->getKey(), 'tenant_id' => $tenantId],
            ['status' => TenantMembership::STATUS_ACTIVE],
        );

        $roleId = Role::query()->where('key', RoleKey::TENANT_OWNER)->value('id');

        if ($roleId !== null && ! $membership->roles()->whereKey($roleId)->exists()) {
            $membership->roles()->attach($roleId);
        }
    }

    private function reportSteps(ClinicSetupResult $result): void
    {
        foreach ($result->steps as $step) {
            $status = strtoupper($step->status);
            $this->line("  [{$status}] {$step->key}: {$step->detail}");
        }

        if ($result->activationUrl !== null) {
            $this->line('  Clinic Admin one-time activation link (shown once):');
            $this->line('  '.$result->activationUrl);
        }

        $this->line('  Database readiness: '.($result->ready ? 'READY' : 'NOT READY'));
    }
}
