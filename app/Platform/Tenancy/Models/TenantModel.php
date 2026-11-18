<?php

namespace App\Platform\Tenancy\Models;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Exceptions\TenantContextMissingException;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for models owned by an isolated tenant database (Phase 2A).
 *
 * Tenant-owned models resolve the active tenant connection at query time and
 * FAIL CLOSED: without a verified tenant context there is no connection to
 * resolve, so the query is refused rather than silently hitting the central
 * database or another tenant's data.
 */
abstract class TenantModel extends Model
{
    public function getConnectionName(): ?string
    {
        $databases = app(ResolvesTenantDatabase::class);

        if ($databases->current() === null) {
            throw TenantContextMissingException::noActiveTenant();
        }

        return $databases->connectionName();
    }
}
