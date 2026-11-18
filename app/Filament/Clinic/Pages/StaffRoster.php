<?php

namespace App\Filament\Clinic\Pages;

use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\UserCategory;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * WS6.E — "Pengguna & Staf" roster for the Clinic panel.
 *
 * A Clinic administrator sees ONLY the staff groups of the tenant they are
 * currently verified in, grouped by the five understandable user categories.
 * It is deliberately READ-ONLY: role provisioning stays a Principal
 * (`platform.*`) capability, so nothing here can escalate a role or reach
 * another tenant. Access is re-derived server-side from the verified tenant
 * context plus the tenant-scoped `tenant.profile.manage` capability (held by
 * clinic administrators, not by ordinary staff), so a suspended membership or a
 * stale/foreign tenant id yields no staff at all.
 */
class StaffRoster extends Page
{
    protected static ?string $slug = 'pengguna-staf';

    protected static ?int $navigationSort = 90;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected string $view = 'filament.clinic.pages.staff-roster';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return false;
        }

        return app(AuthorizesTenantMembership::class)
            ->can($user, $tenantId, PermissionKey::TENANT_PROFILE_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.user_categories.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.user_categories.roster_title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.user_categories.roster_subtitle');
    }

    /**
     * The current tenant's memberships, grouped by user category.
     *
     * Every membership belongs to the verified tenant only — a cross-tenant id
     * is never accepted. Members whose roles map to a platform or unknown
     * category are omitted, so this screen can never surface Principal
     * provisioning.
     *
     * @return array<string, array{label: string, description: string, safety: string, members: list<array{name: string, email: string, status: string, roles: string}>}>
     */
    public function getGroups(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return [];
        }

        $memberships = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->with(['user', 'roles'])
            ->get();

        $groups = [];

        foreach (UserCategory::CLINIC_CATEGORIES as $category) {
            $members = [];

            foreach ($memberships as $membership) {
                $categoryOfMembership = UserCategory::categoriesForRoles(
                    $membership->roles->pluck('key')->all(),
                );

                if (! in_array($category, $categoryOfMembership, true)) {
                    continue;
                }

                $members[] = [
                    'name' => (string) ($membership->user?->name ?? '—'),
                    'email' => (string) ($membership->user?->email ?? ''),
                    'status' => __("lba.tenants.member_status_{$membership->status}"),
                    'roles' => $membership->roles
                        ->pluck('key')
                        ->map(fn (string $key): string => __("lba.roles.{$key}"))
                        ->implode(', '),
                ];
            }

            $groups[$category] = [
                'label' => UserCategory::label($category),
                'description' => UserCategory::description($category),
                'safety' => UserCategory::safety($category),
                'members' => $members,
            ];
        }

        return $groups;
    }
}
