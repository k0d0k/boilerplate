<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission catalog entry (Phase 1B-2A).
 *
 * `key` matches App\Platform\Access\PermissionKey. `scope` (platform|tenant|
 * patient) decides which assignment source may grant it.
 */
#[Fillable(['key', 'scope', 'description'])]
class Permission extends Model
{
    use HasFactory, HasUlids;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withTimestamps();
    }
}
