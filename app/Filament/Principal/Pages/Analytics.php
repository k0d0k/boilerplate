<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Widgets\AiCreditUsageChart;
use App\Filament\Principal\Widgets\PlatformOverviewWidget;
use App\Filament\Principal\Widgets\SubscriptionRevenueChart;
use App\Platform\Access\PermissionKey;
use App\Platform\Analytics\PlatformAnalytics;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * WS4.3 — Principal business analytics & revenue reporting.
 *
 * A read-only governance surface that reports REAL platform figures computed by
 * {@see PlatformAnalytics} from central tables only. It never opens a tenant
 * clinical connection, so no patient, examination, image or finding is reachable
 * here. Viewing requires `platform.analytics.view`; the page performs no
 * mutations.
 */
class Analytics extends Page
{
    use AuthorizesPrincipalResources;

    protected static ?string $slug = 'analytics';

    protected static ?int $navigationSort = 7;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected string $view = 'filament.principal.pages.analytics';

    public static function canAccess(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_ANALYTICS_VIEW);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.principal.analytics');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.analytics.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.analytics.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.analytics.subtitle');
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            PlatformOverviewWidget::class,
            SubscriptionRevenueChart::class,
            AiCreditUsageChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, int>
     */
    public function partners(): array
    {
        return app(PlatformAnalytics::class)->partners();
    }

    /**
     * @return array<string, int>
     */
    public function subscriptions(): array
    {
        return app(PlatformAnalytics::class)->subscriptions();
    }

    /**
     * @return array<string, int>
     */
    public function tenantDatabases(): array
    {
        return app(PlatformAnalytics::class)->tenantDatabases();
    }

    /**
     * @return array<string, int>
     */
    public function aiCredits(): array
    {
        return app(PlatformAnalytics::class)->aiCredits();
    }

    /**
     * @return array<string, float>
     */
    public function monthlyRecurringRevenue(): array
    {
        return app(PlatformAnalytics::class)->monthlyRecurringRevenue();
    }

    /**
     * @return list<array{plan: string, currency: string, subscriptions: int, seats: int, mrr: float}>
     */
    public function revenueByPlan(): array
    {
        return app(PlatformAnalytics::class)->revenueByPlan();
    }
}
