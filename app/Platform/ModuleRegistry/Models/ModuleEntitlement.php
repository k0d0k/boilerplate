<?php

namespace App\Platform\ModuleRegistry\Models;

use App\Platform\ModuleRegistry\EntitlementStatus;
use Database\Factories\ModuleEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-scope module entitlement grant (Phase 1B-4).
 *
 * The explicit, server-verified right for one brand / tenant / branch scope to
 * use one module. No row — or a row that is not `granted` / is expired /
 * revoked — means "not entitled" (deny by default).
 */
#[Fillable([
    'module_key',
    'scope',
    'scope_key',
    'status',
    'subscription_required',
    'subscription_reference',
    'granted_at',
    'expires_at',
    'revoked_at',
    'metadata',
])]
class ModuleEntitlement extends Model
{
    /** @use HasFactory<ModuleEntitlementFactory> */
    use HasFactory, HasUlids;

    /**
     * The models live outside `App\Models`, so the default factory-name
     * resolver would look for a non-existent nested class. Point it at the
     * real factory explicitly.
     */
    protected static function newFactory(): ModuleEntitlementFactory
    {
        return ModuleEntitlementFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscription_required' => 'boolean',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isGranted(): bool
    {
        if ($this->status !== EntitlementStatus::GRANTED || $this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
