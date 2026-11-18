<?php

namespace App\Platform\ModuleRegistry\Models;

use App\Platform\ModuleRegistry\ModuleHealth;
use App\Platform\ModuleRegistry\ModuleStatus;
use Database\Factories\RegisteredModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Central module catalog row (Phase 1B-4) — see the `module_registry` table.
 *
 * Holds module metadata and the platform-wide master switch only. A row here
 * is required for the module to be usable: when it is absent the module fails
 * closed (not installed / not enabled / not healthy). When present it is
 * authoritative for installed / health / status.
 */
#[Fillable([
    'module_key',
    'name',
    'version',
    'min_platform_version',
    'allowed_panels',
    'required_permissions',
    'required_dependencies',
    'supported_locales',
    'scope_compatibility',
    'entitlement_requirement',
    'is_installed',
    'health',
    'status',
    'metadata',
])]
class RegisteredModule extends Model
{
    /** @use HasFactory<RegisteredModuleFactory> */
    use HasFactory, HasUlids;

    protected $table = 'module_registry';

    /**
     * The models live outside `App\Models`, so the default factory-name
     * resolver would look for a non-existent nested class. Point it at the
     * real factory explicitly.
     */
    protected static function newFactory(): RegisteredModuleFactory
    {
        return RegisteredModuleFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_panels' => 'array',
            'required_permissions' => 'array',
            'required_dependencies' => 'array',
            'supported_locales' => 'array',
            'scope_compatibility' => 'array',
            'is_installed' => 'boolean',
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
