<?php

namespace App\Platform\Tenancy\Jobs\Middleware;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Closure;

/**
 * Queue job middleware that binds and releases the tenant database around a
 * single job (Phase 2A).
 *
 * This closes the "wrong-tenant queued worker" threat: the job runs against its
 * own tenant's isolated database, and the connection is always released
 * afterwards so the next job — possibly for another tenant — can never inherit
 * it.
 */
final class TenantDatabaseContext
{
    public function __construct(private readonly ?string $tenantId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $databases = app(ResolvesTenantDatabase::class);

        try {
            if ($this->tenantId !== null) {
                $databases->configure($this->tenantId);
            }

            return $next($job);
        } finally {
            $databases->forget();
        }
    }
}
