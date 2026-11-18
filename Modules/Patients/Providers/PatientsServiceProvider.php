<?php

namespace Modules\Patients\Providers;

use App\Platform\Tenancy\TenantMigrator;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Phase 3 — Patients module service provider.
 *
 * The base class loads the module's translations, views and optional config.
 * The module's tenant migrations deliberately live in the shared tenant
 * migration path (`database/migrations/tenant`), which is the only path the
 * trusted {@see TenantMigrator} applies to a tenant's
 * isolated database.
 */
class PatientsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Patients';

    protected string $nameLower = 'patients';
}
