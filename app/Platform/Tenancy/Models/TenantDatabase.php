<?php

namespace App\Platform\Tenancy\Models;

use App\Models\Tenant;
use Database\Factories\TenantDatabaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central registry row tracking one tenant's isolated clinical database
 * (Phase 2A). This is a CENTRAL record: it stores lifecycle state only, never
 * clinical data, and is the source of truth for what has been provisioned and
 * migrated.
 */
#[Fillable(['tenant_id', 'database_name', 'configured_database_name', 'driver', 'status', 'last_migrated_at', 'metadata'])]
class TenantDatabase extends CentralModel
{
    /** @use HasFactory<TenantDatabaseFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONED = 'provisioned';

    public const STATUS_MIGRATED = 'migrated';

    public const STATUS_DROPPED = 'dropped';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_migrated_at' => 'datetime',
            'metadata' => 'array',
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
    protected static function newFactory(): TenantDatabaseFactory
    {
        return TenantDatabaseFactory::new();
    }
}
