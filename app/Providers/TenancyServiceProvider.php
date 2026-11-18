<?php

namespace App\Providers;

use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\Console\DropTenantDatabaseCommand;
use App\Platform\Tenancy\Console\MigrateRegisteredTenantDatabasesCommand;
use App\Platform\Tenancy\Console\MigrateTenantDatabaseCommand;
use App\Platform\Tenancy\Console\ProvisionTenantDatabaseCommand;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use App\Platform\Tenancy\Cpanel\CpanelClient;
use App\Platform\Tenancy\Cpanel\CpanelTenantDatabaseProvisioner;
use App\Platform\Tenancy\Manual\ManualTenantDatabaseProvisioner;
use App\Platform\Tenancy\OverrideAwareTenantDatabaseProvisioner;
use App\Platform\Tenancy\TenantDatabaseManager;
use App\Platform\Tenancy\TenantDatabaseName;
use App\Platform\Tenancy\TenantDatabaseProvisioner;
use App\Platform\Tenancy\TenantMigrator;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 2A multi-tenant database foundation bindings.
 *
 * Binds the tenancy contracts to their implementations and registers the
 * controlled console commands that provision, migrate and drop tenant
 * databases. The connection manager is a singleton so the active tenant
 * connection is consistent for the whole request/job lifecycle.
 *
 * Phase 3 selects the provisioning implementation from configuration: the
 * cPanel UAPI adapter when `tenancy.cpanel.enabled` is true (so provisioning
 * privileges never live with the Laravel runtime credential), otherwise the
 * raw-SQL adapter used for local/dev MySQL and SQLite.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ResolvesTenantDatabase::class, TenantDatabaseManager::class);
        $this->app->singleton(RunsTenantMigrations::class, TenantMigrator::class);

        // The cPanel client always reads the EFFECTIVE configuration (persisted
        // Global Settings overlaid on `.env`), so the settings screen's read-only
        // connection probe and the provisioner agree on one source of truth for
        // web requests, Artisan commands and queue workers alike. Bound (not
        // singleton) so a long-running worker re-reads a changed setting.
        $this->app->bind(
            CpanelClient::class,
            static fn (): CpanelClient => CpanelClient::fromConfig(),
        );

        $this->app->singleton(ProvisionsTenantDatabases::class, function ($app): ProvisionsTenantDatabases {
            // Read the EFFECTIVE configuration (persisted Global Settings
            // overlaid on the `.env` block) so web requests, Artisan commands
            // and queue workers all provision against the same settings.
            $settings = $app->make(PlatformSettings::class);
            $names = $app->make(TenantDatabaseName::class);

            // MANUAL mode: the database is created by hand in cPanel and the
            // application never calls the cPanel UAPI. The manual adapter runs
            // no privileged DDL — it only verifies the expected database is
            // reachable, so no UAPI credential is involved.
            $manual = new ManualTenantDatabaseProvisioner(
                $names,
                $app->make(ResolvesTenantDatabase::class),
            );

            if ($settings->databaseMode() === PlatformSettings::MODE_MANUAL) {
                return $manual;
            }

            $default = (bool) ($settings->effectiveCpanel()['enabled'] ?? false)
                ? new CpanelTenantDatabaseProvisioner($names, CpanelClient::fromConfig())
                : new TenantDatabaseProvisioner($names);

            // Staging `.env` override: the ONE tenant named by
            // `TENANT_DB_TENANT_ID` has a database created by hand, so it must
            // never be created — nor provisioned through the cPanel UAPI — by
            // the application. Every other tenant keeps the mode's default
            // adapter unchanged.
            return new OverrideAwareTenantDatabaseProvisioner($default, $manual, $names);
        });
    }

    public function boot(): void
    {
        $this->commands([
            ProvisionTenantDatabaseCommand::class,
            MigrateTenantDatabaseCommand::class,
            MigrateRegisteredTenantDatabasesCommand::class,
            DropTenantDatabaseCommand::class,
        ]);
    }
}
