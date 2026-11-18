<?php

namespace App\Platform\Tenancy\Concerns;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Exceptions\TenantContextMissingException;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a multi-statement write on the VERIFIED tenant database (Phase 4 fix).
 *
 * A bare `DB::transaction()` opens the transaction on the DEFAULT (central)
 * platform connection, so a tenant-owned clinical write would commit — or fail
 * to roll back — independently of the transaction that was supposed to guard
 * it. This resolves the SAME connection the tenant models use and FAILS CLOSED
 * without a verified tenant context, so a rollback always rolls back the
 * tenant rows and a partial clinical write can never survive a failure.
 */
trait RunsVerifiedTenantTransactions
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function tenantTransaction(Closure $callback, int $attempts = 1): mixed
    {
        $databases = app(ResolvesTenantDatabase::class);

        if ($databases->current() === null) {
            throw TenantContextMissingException::noActiveTenant();
        }

        return DB::connection($databases->connectionName())->transaction($callback, $attempts);
    }
}
