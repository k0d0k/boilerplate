<?php

namespace App\Platform\ModuleRegistry\Models;

use App\Platform\ModuleRegistry\ModuleHealth;
use App\Platform\ModuleRegistry\ModuleStatus;
use Database\Factories\ModuleInstallationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-scope module installation state (Phase 1B-4).
 *
 * Binds one module to one brand / tenant / branch scope. A scope is only usable
 * once an explicit row exists for it: when no row is present the module fails
 * closed at that scope (nothing is inherited from a broader level). A row can
 * independently enable, disable or mark a module unhealthy without affecting
 * any other scope.
 */
#[Fillable([
    'module_key',
    'scope',
    'scope_key',
    'is_installed',
    'status',
    'health',
    'activated_at',
    'disabled_at',
    'metadata',
])]
class ModuleInstallation extends Model
{
    /** @use HasFactory<ModuleInstallationFactory> */
    use HasFactory, HasUlids;

    /**
     * The models live outside `App\Models`, so the default factory-name
     * resolver would look for a non-existent nested class. Point it at the
     * real factory explicitly.
     */
    protected static function newFactory(): ModuleInstallationFactory
    {
        return ModuleInstallationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_installed' => 'boolean',
            'activated_at' => 'datetime',
            'disabled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isEnabled(): bool
    {
        return $this->status === ModuleStatus::ENABLED;
    }

    public function isHealthy(): bool
    {
        return $this->health === ModuleHealth::HEALTHY;
    }
}
