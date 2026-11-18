<?php

namespace App\Platform\Tenancy\Console\Concerns;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Resolves the `tenant` console argument to a central tenant record, by id or
 * slug. Provisioning and migration are controlled operations, so an unknown
 * tenant always aborts rather than creating arbitrary storage.
 */
trait FindsTenant
{
    protected function findTenant(Command $command, string $argument): ?Tenant
    {
        $tenant = Tenant::query()
            ->where('id', $argument)
            ->orWhere('slug', $argument)
            ->first();

        if ($tenant === null) {
            $command->error("Tenant [{$argument}] was not found.");
        }

        return $tenant;
    }
}
