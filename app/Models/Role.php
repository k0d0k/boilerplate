<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Role catalog entry (Phase 1B-2A).
 *
 * `key` matches App\Platform\Access\RoleKey. `scope` is platform|tenant|patient|
 * machine and `panel` is the single human panel the role belongs to (null for the
 * machine-only ai_service role, which must never hold human panel permissions).
 */
#[Fillable(['key', 'name', 'scope', 'panel', 'is_machine', 'description'])]
class Role extends Model
{
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_machine' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }

    /**
     * @return HasMany<PrincipalRoleAssignment, $this>
     */
    public function principalAssignments(): HasMany
    {
        return $this->hasMany(PrincipalRoleAssignment::class);
    }
}
