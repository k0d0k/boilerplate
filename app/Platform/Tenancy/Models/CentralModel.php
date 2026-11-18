<?php

namespace App\Platform\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for models that live in the CENTRAL platform database (Phase 2A).
 *
 * Central models (identity, brands, tenants, memberships, modules, the tenant
 * database registry) must never resolve to a tenant clinical connection, so the
 * connection is pinned explicitly rather than inherited from runtime state.
 */
abstract class CentralModel extends Model
{
    public function getConnectionName(): ?string
    {
        return (string) config('tenancy.central_connection');
    }
}
