<?php

namespace Modules\Appointments\Providers;

use App\Platform\Tenancy\TenantMigrator;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Phase 3 — Appointments module service provider.
 *
 * The tenant tables for this module live in the shared tenant migration path
 * (`database/migrations/tenant`) because that path is the ONLY one applied to a
 * tenant database by {@see TenantMigrator}; the module
 * therefore ships no migrations of its own.
 */
class AppointmentsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Appointments';

    protected string $nameLower = 'appointments';
}
