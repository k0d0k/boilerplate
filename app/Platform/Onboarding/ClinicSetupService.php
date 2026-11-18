<?php

namespace App\Platform\Onboarding;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Access\StaffInvitationOutcome;
use App\Platform\Access\StaffInviter;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleAdministration;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseConflict;
use App\Platform\Tenancy\TenantDatabaseName;
use App\Platform\Tenancy\TenantDatabaseReadiness;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Phase 3 — one-click Clinic setup orchestration.
 *
 * The single server-side procedure behind BOTH the Principal "Setup Clinic"
 * action and the `lba:clinic:setup` console command. It composes the existing,
 * independently-safe services in the exact order the product requires:
 *
 *   1. provision the isolated tenant database (cPanel or raw-SQL adapter);
 *   2. (privileges are granted ON THAT DATABASE ONLY by the provisioner);
 *   3. run the isolated tenant migrations;
 *   4. register the database READINESS row centrally (`tenant_databases`);
 *   5. install + activate the permitted clinic modules;
 *   6. invite the Clinic Admin securely (single-use activation link).
 *
 * Every step is IDEMPOTENT and RETRY-SAFE: provisioning detects an existing
 * database and reuses it (never re-creates, never drops), migrations are applied
 * by the framework migrator, module activation is an `updateOrCreate` and the
 * invitation is a single-use proof. A retry therefore can never duplicate a
 * database or lose data. The whole run holds a per-tenant lock so two
 * concurrent setup requests cannot interleave.
 *
 * The multi-tenant architecture is unchanged: the central database stays the
 * registry and each tenant keeps its own isolated clinical database.
 */
final class ClinicSetupService
{
    /**
     * The clinic modules the platform permits a new clinic to run. Kept
     * explicit (rather than "enable everything") so setup never grants a module
     * the product has not approved for a clinic.
     *
     * @var list<string>
     */
    public const DEFAULT_MODULES = ['patients', 'appointments'];

    /**
     * Audit action recorded for the per-tenant module activation, matching the
     * clinic-setup vocabulary already visible on the Principal audit surface.
     */
    private const MODULE_AUDIT_ACTION = 'activate';

    /** Seconds a setup run may hold the per-tenant lock before it expires. */
    private const LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly ProvisionsTenantDatabases $provisioner,
        private readonly RunsTenantMigrations $migrator,
        private readonly TenantDatabaseName $names,
        private readonly TenantDatabaseConflict $conflicts,
        private readonly TenantDatabaseReadiness $readiness,
        private readonly ModuleAdministration $modules,
        private readonly StaffInviter $inviter,
    ) {}

    /**
     * Run (or safely retry) the full clinic setup for one tenant.
     *
     * @param  list<string>  $moduleKeys  the permitted modules to activate
     */
    public function setup(
        Tenant $tenant,
        ?string $adminEmail = null,
        ?string $adminName = null,
        array $moduleKeys = self::DEFAULT_MODULES,
        ?User $actor = null,
    ): ClinicSetupResult {
        $tenantId = (string) $tenant->getKey();

        // Protect against concurrent setup requests: the lock is non-blocking,
        // so a second request is told to retry rather than racing.
        $lock = Cache::lock('clinic-setup:'.$tenantId, self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return ClinicSetupResult::busy($tenantId);
        }

        try {
            return $this->run($tenant, $adminEmail, $adminName, $moduleKeys, $actor);
        } finally {
            $lock->release();
        }
    }

    /**
     * Run (or safely retry) ONLY the isolated database step for one tenant.
     *
     * This is the server-side action behind the Partner detail screen's
     * "Set Up Database" / "Retry" buttons. It shares the SAME per-tenant lock,
     * provisioner, migrator, readiness registry and non-destructive failure
     * handling as {@see setup()}, so a database-only retry can never run
     * alongside a full setup, never re-creates or drops a database and always
     * converges the missing step.
     */
    public function provisionDatabase(Tenant $tenant, ?User $actor = null): ClinicSetupResult
    {
        $tenantId = (string) $tenant->getKey();

        $lock = Cache::lock('clinic-setup:'.$tenantId, self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return ClinicSetupResult::busy($tenantId);
        }

        try {
            $step = $this->provisionDatabaseStep($tenantId, $actor);

            return ClinicSetupResult::completed(
                $tenantId,
                [$step],
                $this->readiness->isReady($tenantId),
                null,
                $step->isFailed() ? $step->detail : null,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $moduleKeys
     */
    private function run(
        Tenant $tenant,
        ?string $adminEmail,
        ?string $adminName,
        array $moduleKeys,
        ?User $actor,
    ): ClinicSetupResult {
        $tenantId = (string) $tenant->getKey();
        $steps = [];

        $database = $this->provisionDatabaseStep($tenantId, $actor);
        $steps[] = $database;

        if ($database->isFailed()) {
            return ClinicSetupResult::completed($tenantId, $steps, false, null, $database->detail);
        }

        $modules = $this->activateModules($tenantId, $moduleKeys, $actor);
        $steps[] = $modules;

        if ($modules->isFailed()) {
            return ClinicSetupResult::completed($tenantId, $steps, $this->readiness->isReady($tenantId), null, $modules->detail);
        }

        [$admin, $activationUrl] = $this->inviteAdmin($tenant, $adminEmail, $adminName, $actor);
        $steps[] = $admin;

        return ClinicSetupResult::completed(
            $tenantId,
            $steps,
            $this->readiness->isReady($tenantId),
            $activationUrl,
            $admin->isFailed() ? $admin->detail : null,
        );
    }

    /**
     * Provision the isolated database, migrate it and register central
     * readiness. Nothing is dropped: a failure records a `failed` state that a
     * retry converges back to `migrated`.
     */
    private function provisionDatabaseStep(string $tenantId, ?User $actor): ClinicSetupStep
    {
        try {
            // The name comes from the SAME resolver the runtime connection and
            // "Uji Koneksi" use, so a manual staging override (TENANT_DB) is
            // honoured consistently — and an unusable one (bad identifier, or a
            // name that is the CENTRAL database) is rejected here, before any
            // database is touched.
            $target = $this->names->for($tenantId);
            $conflict = $this->conflicts->for($tenantId);
        } catch (Throwable $e) {
            // Invalid configuration: report and STOP. The registry row is left
            // untouched — the name itself is what is wrong, and re-deriving it
            // to record a failure would raise the same error again.
            $this->audit('provision_database', ModuleAuditLog::RESULT_DENIED, $e->getMessage(), $tenantId, $actor);

            return ClinicSetupStep::failed(ClinicSetupResult::STEP_DATABASE, $e->getMessage());
        }

        // Never create, migrate or re-point onto a name that conflicts with the
        // central registry (central database, another tenant's database, or a
        // different name than the one already recorded): fail safely and PRESERVE
        // the existing database (and its data) instead. The operator inspects the
        // database and the registry before continuing.
        if ($conflict !== null) {
            $this->audit('provision_database', ModuleAuditLog::RESULT_DENIED, $conflict, $tenantId, $actor);

            return ClinicSetupStep::failed(ClinicSetupResult::STEP_DATABASE, $conflict);
        }

        try {
            // Idempotent: detects and reuses an existing database, then grants
            // the restricted runtime user privileges on THAT database only.
            $this->provisioner->provision($tenantId);
            $this->migrator->migrate($tenantId);

            TenantDatabase::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'database_name' => $target,
                    'driver' => $this->names->driver(),
                    'status' => TenantDatabase::STATUS_MIGRATED,
                    'last_migrated_at' => now(),
                ],
            );

            if (! $this->readiness->isReady($tenantId)) {
                throw new RuntimeException('The tenant database readiness row was not registered.');
            }

            $this->audit('provision_database', ModuleAuditLog::RESULT_ALLOWED, null, $tenantId, $actor);

            return ClinicSetupStep::ok(ClinicSetupResult::STEP_DATABASE, 'Database provisioned, migrated and registered as ready.');
        } catch (Throwable $e) {
            $this->markDatabaseFailed($tenantId);
            $this->audit('provision_database', ModuleAuditLog::RESULT_DENIED, $e->getMessage(), $tenantId, $actor);

            return ClinicSetupStep::failed(ClinicSetupResult::STEP_DATABASE, $e->getMessage());
        }
    }

    /**
     * Synchronise the trusted catalog and activate each permitted module for
     * this tenant through the gate-aware administration write path.
     *
     * @param  list<string>  $moduleKeys
     */
    private function activateModules(string $tenantId, array $moduleKeys, ?User $actor): ClinicSetupStep
    {
        try {
            $this->modules->syncCatalog();
        } catch (Throwable $e) {
            return ClinicSetupStep::failed(ClinicSetupResult::STEP_MODULES, $e->getMessage());
        }

        $denied = [];

        foreach ($moduleKeys as $moduleKey) {
            // enable() refuses an unknown, wrong-scope or dependency-unsatisfied
            // module, so nothing invalid is ever written.
            if (! $this->modules->enable($moduleKey, $tenantId, $actor, self::MODULE_AUDIT_ACTION)) {
                $denied[] = $moduleKey;
            }
        }

        if ($denied !== []) {
            return ClinicSetupStep::failed(
                ClinicSetupResult::STEP_MODULES,
                'Denied: '.implode(', ', $denied),
            );
        }

        return ClinicSetupStep::ok(
            ClinicSetupResult::STEP_MODULES,
            count($moduleKeys).' module(s) activated.',
        );
    }

    /**
     * Invite the Clinic Admin securely. Skipped (not a failure) when no email
     * is supplied, so a database-only setup run still succeeds.
     *
     * @return array{0: ClinicSetupStep, 1: ?string}
     */
    private function inviteAdmin(Tenant $tenant, ?string $adminEmail, ?string $adminName, ?User $actor): array
    {
        $email = trim((string) $adminEmail);

        if ($email === '') {
            return [ClinicSetupStep::skipped(ClinicSetupResult::STEP_ADMIN, 'No Clinic Admin email supplied.'), null];
        }

        $outcome = $this->inviter->invite(
            $tenant,
            $email,
            trim((string) $adminName) !== '' ? (string) $adminName : $email,
            [RoleKey::TENANT_ADMIN],
            $actor,
        );

        return match ($outcome->status) {
            StaffInvitationOutcome::STATUS_INVITED => [
                ClinicSetupStep::ok(ClinicSetupResult::STEP_ADMIN, 'Clinic Admin invited.'),
                $outcome->activationUrl,
            ],
            StaffInvitationOutcome::STATUS_ALREADY_MEMBER => [
                ClinicSetupStep::ok(ClinicSetupResult::STEP_ADMIN, 'Clinic Admin is already a member.'),
                null,
            ],
            StaffInvitationOutcome::STATUS_PRINCIPAL_FORBIDDEN => [
                ClinicSetupStep::failed(ClinicSetupResult::STEP_ADMIN, 'Platform administrator accounts cannot be a Clinic Admin.'),
                null,
            ],
            default => [
                ClinicSetupStep::failed(ClinicSetupResult::STEP_ADMIN, 'The Clinic Admin invitation could not be issued.'),
                null,
            ],
        };
    }

    /**
     * Record a non-destructive `failed` readiness state. The isolated database
     * and its data are never touched; a retry re-runs provisioning idempotently.
     */
    private function markDatabaseFailed(string $tenantId): void
    {
        TenantDatabase::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'database_name' => $this->names->for($tenantId),
                'driver' => $this->names->driver(),
                'status' => TenantDatabase::STATUS_FAILED,
            ],
        );
    }

    /**
     * Append a redacted provisioning audit record (no clinical payload, no
     * credential — only the action, result, reason and opaque tenant reference).
     */
    private function audit(string $action, string $result, ?string $reason, string $tenantId, ?User $actor): void
    {
        ModuleAuditLog::query()->create([
            'module_key' => 'platform.tenancy',
            'action' => $action,
            'result' => $result,
            'reason' => $reason === null ? null : Str::substr($reason, 0, 255),
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => $tenantId,
            'actor_id' => $actor?->getKey(),
            'metadata' => null,
        ]);
    }
}
