<?php

namespace App\Providers\Filament;

use App\Filament\Principal\Pages\AccountProfile;
use App\Filament\Principal\Pages\ChangePassword;
use App\Filament\Principal\Pages\Dashboard;
use App\Filament\Principal\Widgets\AiCreditUsageChart;
use App\Filament\Principal\Widgets\PlatformInsightsWidget;
use App\Filament\Principal\Widgets\PlatformOverviewWidget;
use App\Filament\Principal\Widgets\SubscriptionRevenueChart;
use App\Filament\Shared\Widgets\CacheableWidgetConfiguration;
use App\Filament\Shared\Widgets\HeroSectionWidget;
use App\Localization\Middleware\SetLocale;
use App\Models\Brand;
use App\Models\Tenant;
use App\Platform\Access\PermissionKey;
use App\Platform\Crud\BrandCrudDefinition;
use App\Platform\Filament\ModulePanelPlugin;
use App\Platform\Settings\PlatformSettings;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PrincipalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('principal')
            ->path('principal')
            ->viteTheme('resources/css/filament/lba-cloud/theme.css')
            // The header shows the platform's effective System Name (Global
            // Settings), falling back to the shipped name for installs that
            // never changed it.
            ->brandName(fn (): string => app(PlatformSettings::class)->systemName())
            ->darkMode(false)
            // Staff sign in through the unified `/login`; this panel's legacy
            // login URL stays routable but forwards there (no redirect loop).
            ->login(fn () => redirect()->route('login'))
            ->colors([
                // LBA design tokens: teal #087F80 (primary) and navy #14263D.
                'primary' => Color::hex('#087F80'),
                'navy' => Color::hex('#14263D'),
                // The four dashboard KPI accents. Registering them here makes
                // `AssetManager` emit the `.fi-color-*` custom properties inline,
                // so the accent stays available even without a compiled theme.
                'blue' => Color::Blue,
                'teal' => Color::Teal,
                'purple' => Color::Purple,
                'amber' => Color::Amber,
            ])
            // Premium shell: ~256px expanded sidebar, ~72px collapsed icon rail.
            ->sidebarWidth('16rem')
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups()
            ->navigationItems([
                // Menu, groups and order mirror docs/PANELS_NAVIGATION.md
                // ("Principal Panel — TailAdmin target"). The array order IS the
                // rendered order (`Panel::getNavigationItems()` returns it as
                // declared), so it stays in lockstep with the shared TailAdmin
                // sidebar in `principal.layouts.sidebar`.
                //
                // One canonical governance entry: brand management and the
                // partner (tenant) registry are the two tabs of a single
                // "Brand & Mitra" surface, so no duplicate brand menu is shown.
                NavigationItem::make('tailadmin-brands')
                    ->label(fn (): string => __('lba.nav.principal.brands'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.brands.index'))
                    ->sort(1)
                    ->visible(fn (): bool => Gate::allows('viewAny', Brand::class)),
                // "Klinik & Cabang" is the governance catalogue of tenant
                // metadata and branch counts (docs/PANELS_NAVIGATION.md) — never
                // clinical records. It is the single canonical entry for the
                // partner registry, so it replaces the interim
                // brands-falls-back-to-tenants link the menu used to carry.
                NavigationItem::make('tailadmin-tenants')
                    ->label(fn (): string => __('lba.nav.principal.clinics'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.tenants.index'))
                    ->sort(2)
                    ->visible(fn (): bool => Gate::allows('viewAny', Tenant::class)),
                // The migrated governance surfaces below are served by TailAdmin
                // and exposed here as the single canonical entry per feature, so
                // the Principal panel never shows both the interim Filament
                // screen and its TailAdmin replacement. Visibility is gated on
                // the very same capability the underlying TailAdmin route
                // re-derives server side.
                NavigationItem::make('tailadmin-subscriptions')
                    ->label(fn (): string => __('lba.nav.principal.subscriptions'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.subscriptions.index'))
                    ->sort(3)
                    ->visible(fn (): bool => Gate::any([
                        PermissionKey::PLATFORM_PLAN_MANAGE,
                        PermissionKey::PLATFORM_SUBSCRIPTION_VIEW,
                    ])),
                NavigationItem::make('tailadmin-ai-credits')
                    ->label(fn (): string => __('lba.nav.principal.ai_usage'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.ai-credits.index'))
                    ->sort(4)
                    ->visible(fn (): bool => Gate::any([
                        PermissionKey::PLATFORM_BILLING_MANAGE,
                        PermissionKey::PLATFORM_ANALYTICS_VIEW,
                    ])),
                NavigationItem::make('tailadmin-modules')
                    ->label(fn (): string => __('lba.nav.principal.module_manager'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.modules.index'))
                    ->sort(5)
                    ->visible(fn (): bool => Gate::allows(PermissionKey::PLATFORM_MODULE_MANAGE)),
                NavigationItem::make('tailadmin-entitlements')
                    ->label(fn (): string => __('lba.nav.principal.feature_access'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.entitlements.index'))
                    ->sort(6)
                    ->visible(fn (): bool => Gate::allows(PermissionKey::PLATFORM_ENTITLEMENT_MANAGE)),
                NavigationItem::make('tailadmin-payment-providers')
                    ->label(fn (): string => __('lba.nav.principal.payment_providers'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.payment-providers.index'))
                    ->sort(7)
                    ->visible(fn (): bool => Gate::allows(PermissionKey::PLATFORM_PAYMENT_MANAGE)),
                // WS4.3 / WS4.4 — the read-only governance surfaces: non-clinical
                // business analytics and the redacted platform audit trail. Both
                // keep the same canonical entry the TailAdmin shell sidebar
                // renders, gated on the capability the underlying route
                // re-derives server side.
                NavigationItem::make('tailadmin-analytics')
                    ->label(fn (): string => __('lba.nav.principal.analytics'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.analytics.index'))
                    ->sort(8)
                    ->visible(fn (): bool => Gate::allows(PermissionKey::PLATFORM_ANALYTICS_VIEW)),
                NavigationItem::make('tailadmin-audit')
                    ->label(fn (): string => __('lba.nav.principal.audit'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.audit.index'))
                    ->sort(9)
                    ->visible(fn (): bool => Gate::allows(PermissionKey::PLATFORM_AUDIT_VIEW)),
                // "Pengaturan Global" is the last governance entry, matching the
                // documented order. Restrict to Principal Super Admin
                // (platform.rbac.manage), matching the access control on the
                // underlying route.
                NavigationItem::make('global-settings')
                    ->label(fn (): string => __('principal.settings.title'))
                    ->group(fn (): string => __('lba.nav.principal.group_governance'))
                    ->url(fn (): string => route('principal.tailadmin.settings.index'))
                    ->sort(10)
                    ->visible(fn (): bool => Gate::allows(PermissionKey::PLATFORM_RBAC_MANAGE)),
                NavigationItem::make('crud-generator')
                    ->label(fn (): string => __('principal.crud.title'))
                    ->group(fn (): string => __('principal.crud.group'))
                    ->url(fn (): string => route('principal.tailadmin.crud.index'))
                    ->visible(fn (): bool => Gate::allows(BrandCrudDefinition::ABILITY)),
            ])
            // Account self-service lives in the top-right account menu, not the
            // sidebar: "Profil Akun", "Ubah Kata Sandi" and "Keluar" (the
            // framework's default logout item, relabelled).
            ->userMenuItems([
                'account' => fn (Action $action): Action => $action
                    ->label(__('lba.account.nav'))
                    ->url(fn (): string => AccountProfile::getUrl())
                    ->icon(Heroicon::OutlinedUserCircle),
                'change-password' => Action::make('changePassword')
                    ->label(__('lba.password_change.nav'))
                    ->url(fn (): string => ChangePassword::getUrl())
                    ->icon(Heroicon::OutlinedKey),
                'logout' => fn (Action $action): Action => $action
                    ->label(__('principal.shell.logout')),
            ])
            // All Principal governance modules have shipped as real resources/
            // pages (Brand, Tenant, Module Manager, Trusted Modules, Feature
            // Access, Analytics, Audit Log, Platform Settings), so this panel no
            // longer renders the non-interactive "preview" navigation group that
            // clinic/patient still use for their unshipped roadmap modules.
            ->discoverResources(in: app_path('Filament/Principal/Resources'), for: 'App\Filament\Principal\Resources')
            ->discoverPages(in: app_path('Filament/Principal/Pages'), for: 'App\Filament\Principal\Pages')
            ->pages([
                Dashboard::class,
            ])
            // WS9.E — a bounded, auditable "open tenant" support context renders
            // a persistent banner (with the "Kembali ke Principal" action) at the
            // top of every Principal page while it is active. The component
            // renders nothing when no support context is open.
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (): View => view('filament.principal.support-banner'),
            )
            ->discoverWidgets(in: app_path('Filament/Principal/Widgets'), for: 'App\Filament\Principal\Widgets')
            ->widgets([
                CacheableWidgetConfiguration::make(HeroSectionWidget::class, ['dataset' => 'principal.hero']),
                PlatformOverviewWidget::class,
                SubscriptionRevenueChart::class,
                AiCreditUsageChart::class,
                CacheableWidgetConfiguration::make(PlatformInsightsWidget::class, ['section' => 'activity', 'span' => 1]),
                CacheableWidgetConfiguration::make(PlatformInsightsWidget::class, ['section' => 'health', 'span' => 1]),
            ])
            // Phase 1B-4 — host every module allowed on the Principal panel.
            ->plugins([
                app(ModulePanelPlugin::class),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Phase 1B-3 — resolve + apply the active locale on panel routes.
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
