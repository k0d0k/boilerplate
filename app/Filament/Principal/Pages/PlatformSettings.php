<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\PrincipalAccessAdministration;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * WS4.5 — Principal platform settings and RBAC administration.
 *
 * Surfaces the platform's real configuration (supported locales, per-brand
 * default language, payment-provider posture) as a READ-ONLY overview, and
 * administers PLATFORM governance roles over the existing RBAC tables via
 * {@see PrincipalAccessAdministration}. Nothing here weakens the deny-by-default
 * authorization or the tenant isolation: the surface requires
 * `platform.rbac.manage`, only platform-assignable roles may be granted, and the
 * model-layer RoleAssignmentGuard still rejects any cross-scope assignment.
 */
class PlatformSettings extends Page
{
    use AuthorizesPrincipalResources;

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 9;

    /**
     * Global platform settings are served canonically by the TailAdmin surface
     * (`principal.tailadmin.settings.*`), so this interim Filament page keeps
     * its route but no longer adds a duplicate sidebar entry.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'filament.principal.pages.platform-settings';

    /** Identity chosen for a platform role grant. */
    public ?string $grantUserId = null;

    /** Platform role chosen for a grant. */
    public ?string $grantRoleKey = null;

    public static function canAccess(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.principal.settings');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.settings.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.settings.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.settings.subtitle');
    }

    // ------------------------------------------------ Platform configuration

    /**
     * Supported locales, labelled in their own language.
     *
     * @return array<string, string>
     */
    public function locales(): array
    {
        $labels = (array) config('localization.labels', []);
        $supported = (array) config('localization.supported', []);

        $options = [];

        foreach ($supported as $locale) {
            $options[$locale] = $labels[$locale] ?? $locale;
        }

        return $options;
    }

    public function defaultLocale(): string
    {
        return (string) config('localization.default');
    }

    /**
     * Starter brand default locale, keyed by brand slug. A brand without an
     * entry intentionally has no default.
     *
     * @return array<string, string>
     */
    public function brandDefaults(): array
    {
        return (array) config('localization.brand_defaults', []);
    }

    /**
     * Non-secret payment-provider posture. Credentials are never read or shown.
     *
     * @return list<array<string, mixed>>
     */
    public function gateways(): array
    {
        return PaymentGateway::query()
            ->orderBy('provider')
            ->get()
            ->map(fn (PaymentGateway $gateway): array => [
                'label' => (string) $gateway->label,
                'provider' => (string) $gateway->provider,
                'mode' => (string) $gateway->mode,
                'enabled' => (bool) $gateway->enabled,
                'verified' => $gateway->isVerified(),
            ])
            ->all();
    }

    // ------------------------------------------------------- RBAC catalogue

    /**
     * Platform roles => permission keys, for the read-only role matrix.
     *
     * @return array<string, list<string>>
     */
    public function platformRoleMatrix(): array
    {
        $catalog = RbacCatalog::rolePermissions();

        $matrix = [];

        foreach (RoleKey::PRINCIPAL_ROLES as $roleKey) {
            $matrix[$roleKey] = $catalog[$roleKey] ?? [];
        }

        return $matrix;
    }

    public function permissionLabel(string $permission): string
    {
        return __("lba.permissions.{$permission}");
    }

    public function roleLabel(string $roleKey): string
    {
        return __("lba.roles.{$roleKey}");
    }

    public function assignmentStatusLabel(string $status): string
    {
        return __("lba.settings.status_{$status}");
    }

    // --------------------------------------------------- Identity management

    /**
     * Identities holding at least one principal role assignment.
     *
     * @return Collection<int, User>
     */
    public function identities(): Collection
    {
        return app(PrincipalAccessAdministration::class)->identities();
    }

    /**
     * @return array<string, string>
     */
    public function principalRoleOptions(): array
    {
        $options = [];

        foreach (RoleKey::PRINCIPAL_ROLES as $roleKey) {
            $options[$roleKey] = $this->roleLabel($roleKey);
        }

        return $options;
    }

    /**
     * Active identities eligible to receive a platform role.
     *
     * @return array<string, string>
     */
    public function candidateUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                (string) $user->getKey() => "{$user->name} — {$user->email}",
            ])
            ->all();
    }

    public function activeRoleKeys(User $user): array
    {
        return app(PrincipalAccessAdministration::class)->activeRoleKeys($user);
    }

    public function grantRole(): void
    {
        $this->authorizeManagement();

        $user = $this->grantUserId === null ? null : User::query()->find($this->grantUserId);

        abort_if($user === null, 404);

        $granted = app(PrincipalAccessAdministration::class)
            ->assignRole($user, (string) $this->grantRoleKey, $this->actor());

        Notification::make()
            ->{$granted ? 'success' : 'danger'}()
            ->title($granted ? __('lba.settings.granted') : __('lba.settings.grant_denied'))
            ->send();

        $this->grantUserId = null;
        $this->grantRoleKey = null;
    }

    public function revokeRole(string $userId, string $roleKey): void
    {
        $this->authorizeManagement();

        $user = User::query()->find($userId);

        abort_if($user === null, 404);

        $revoked = app(PrincipalAccessAdministration::class)
            ->revokeRole($user, $roleKey, $this->actor());

        Notification::make()
            ->{$revoked ? 'success' : 'danger'}()
            ->title($revoked ? __('lba.settings.revoked') : __('lba.settings.revoke_denied'))
            ->send();
    }

    private function authorizeManagement(): void
    {
        abort_unless(static::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE), 403);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
