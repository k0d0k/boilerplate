<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\SupportTenantSession;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Tenancy\Models\TenantDatabase;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * WS9.E — read-only "open tenant" support view.
 *
 * Unlocked ONLY while a bounded support context opened from the Tenant list (or
 * a Brand's tenant list) is active. It presents the support tenant's
 * OPERATIONAL posture — lifecycle status, isolated-database readiness, brands,
 * membership breakdown and subscription/licence — composed exclusively from
 * CENTRAL registry rows.
 *
 * It deliberately never binds or reads a tenant clinical database, so there is
 * no path to a medical record, an LBA image or a private tenant document. The
 * panel stays read-only (no mutation action is exposed) and the Super Admin
 * keeps their own identity throughout.
 */
class TenantSupport extends Page
{
    use AuthorizesPrincipalResources;

    protected static ?string $slug = 'tenant-support';

    protected static ?int $navigationSort = 3;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected string $view = 'filament.principal.pages.tenant-support';

    public static function canAccess(): bool
    {
        $actor = static::principalUser();

        if ($actor === null || ! static::principalCan(PermissionKey::PLATFORM_TENANT_MANAGE)) {
            return false;
        }

        return app(SupportTenantSession::class)->isActive($actor);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.support.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.support.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.support.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.support.subtitle');
    }

    public function tenant(): ?Tenant
    {
        return app(SupportTenantSession::class)->currentTenant(static::principalUser());
    }

    public function expiresAt(): ?string
    {
        return app(SupportTenantSession::class)->expiresAt(static::principalUser())?->format('H:i');
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function overview(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return [];
        }

        $status = (string) $tenant->status;
        $databaseStatus = $tenant->database?->status ?? TenantDatabase::STATUS_PENDING;

        return [
            ['label' => __('lba.tenants.name'), 'value' => (string) $tenant->name],
            ['label' => __('lba.tenants.slug'), 'value' => (string) $tenant->slug],
            ['label' => __('lba.tenants.status'), 'value' => __("lba.tenants.status_{$status}")],
            ['label' => __('lba.tenants.db_status'), 'value' => __("lba.tenants.db_status_{$databaseStatus}")],
        ];
    }

    /**
     * @return list<array{name: string, primary: bool}>
     */
    public function brands(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return [];
        }

        return $tenant->brands()
            ->orderBy('name')
            ->get()
            ->map(static fn ($brand): array => [
                'name' => (string) $brand->name,
                'primary' => (bool) $brand->pivot->is_primary,
            ])
            ->all();
    }

    /**
     * Membership counts grouped by lifecycle status (never identity detail).
     *
     * @return array<string, int>
     */
    public function membershipCounts(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return [];
        }

        $counts = TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return array_map(static fn ($count): int => (int) $count, $counts);
    }

    /**
     * The tenant's most relevant subscription/licence, or null.
     *
     * @return array{status: string, active: bool, period_end: ?string}|null
     */
    public function subscription(): ?array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return null;
        }

        $subscription = $tenant->subscriptions()
            ->orderByDesc('created_at')
            ->first();

        if (! $subscription instanceof TenantSubscription) {
            return null;
        }

        return [
            'status' => __("lba.subscriptions.sub_status_{$subscription->status}"),
            'active' => $subscription->isActive(),
            'period_end' => $subscription->current_period_end?->format('Y-m-d'),
        ];
    }

    public function closeSupport(): void
    {
        app(SupportTenantSession::class)->close(static::principalUser());

        $this->redirect(Dashboard::getUrl(panel: 'principal'));
    }
}
