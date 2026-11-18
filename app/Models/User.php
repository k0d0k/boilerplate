<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Platform\Access\PanelAccessPolicy;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'description', 'photo_path', 'address', 'password', 'must_set_password', 'role', 'is_active', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The account's own profile photo, served through the authenticated,
     * private account-photo endpoint. Null lets the panels fall back to the
     * account's initials — a photo is never exposed as a public URL.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->hasPhoto() ? route('account.photo') : null;
    }

    public function hasPhoto(): bool
    {
        return filled($this->photo_path);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_set_password' => 'boolean',
        ];
    }

    /**
     * Provisional, deny-by-default panel gate (Phase 1A-2).
     *
     * Access is decided server-side on every request, never by URL alone:
     *  - suspended/unknown identities are denied everywhere;
     *  - a role only unlocks its single mapped panel.
     *
     * The full membership/entitlement model arrives in Phase 1B/2.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return PanelAccessPolicy::roleCanAccessPanel($this->role, $panel->getId());
    }

    /**
     * Tenant memberships (Phase 1B-2A). Authorization fails closed unless a
     * membership is active; see App\Platform\Access\TenantMembershipAuthorizer.
     *
     * @return HasMany<TenantMembership, $this>
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * Principal / platform role assignments (Phase 1B-2A).
     *
     * @return HasMany<PrincipalRoleAssignment, $this>
     */
    public function principalRoleAssignments(): HasMany
    {
        return $this->hasMany(PrincipalRoleAssignment::class);
    }
}
