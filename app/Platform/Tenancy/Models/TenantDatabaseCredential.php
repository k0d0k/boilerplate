<?php

namespace App\Platform\Tenancy\Models;

use App\Models\Tenant;
use Database\Factories\TenantDatabaseCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-tenant MySQL credential used in MANUAL database mode (Phase 3).
 *
 * One central row per tenant records the restricted MySQL user the runtime
 * tenant connection authenticates as, plus its password. This is CENTRAL,
 * non-clinical metadata: it never holds patient data, and the host/port are
 * read from server configuration rather than stored here.
 *
 * The password is stored ENCRYPTED through the `encrypted` cast, is write-only
 * on the Principal surface and is never returned in a response or a view.
 */
#[Fillable(['tenant_id', 'username', 'password'])]
class TenantDatabaseCredential extends CentralModel
{
    /** @use HasFactory<TenantDatabaseCredentialFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Pin the factory so name resolution works from a non-default namespace.
     */
    protected static function newFactory(): TenantDatabaseCredentialFactory
    {
        return TenantDatabaseCredentialFactory::new();
    }
}
