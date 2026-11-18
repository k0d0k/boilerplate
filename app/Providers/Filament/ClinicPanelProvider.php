<?php

namespace App\Providers\Filament;

use App\Filament\Clinic\Middleware\EnsureClinicTenantContext;
use App\Filament\Clinic\Pages\AccountProfile;
use App\Filament\Clinic\Pages\ChangePassword;
use App\Filament\Clinic\Pages\Dashboard;
use App\Filament\Clinic\Widgets\ClinicOverviewWidget;
use App\Filament\Clinic\Widgets\PractitionerWorkloadWidget;
use App\Filament\Shared\Widgets\CacheableWidgetConfiguration;
use App\Filament\Shared\Widgets\DemoSectionWidget;
use App\Filament\Shared\Widgets\HeroSectionWidget;
use App\Localization\Middleware\SetLocale;
use App\Platform\Filament\ModulePanelPlugin;
use App\Platform\Tenancy\Http\Middleware\InitializeTenantDatabase;
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
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ClinicPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('clinic')
            ->path('clinic')
            ->viteTheme('resources/css/filament/lba-cloud/theme.css')
            ->brandName(fn (): string => __('lba.panel.clinic'))
            ->darkMode(false)
            // Staff sign in through the unified `/login`; this panel's legacy
            // login URL stays routable but forwards there (no redirect loop).
            ->login(fn () => redirect()->route('login'))
            ->colors([
                // LBA design token: teal #087F80
                'primary' => Color::hex('#087F80'),
            ])
            // Premium shell: ~256px expanded sidebar, ~72px collapsed icon rail.
            ->sidebarWidth('16rem')
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups()
            ->navigationItems([
                NavigationItem::make('tailadmin-workspace')
                    ->label(fn (): string => __('principal.clinic.workspace'))
                    ->url(fn (): string => route('clinic.tailadmin.dashboard')),
            ])
            // Account self-service lives in the top-right account menu, not the
            // sidebar: "Profil Akun", "Ubah Kata Sandi" and "Keluar".
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
            // Every Clinic module (Patients, Appointments, LBA, Consultations,
            // Commerce) now ships real, route-backed navigation entries, so the
            // non-interactive "preview group" render hook was removed entirely:
            // keeping it would only duplicate the live Consultations / Commerce
            // entries with non-clickable labels (WS5 — no decorative nav).
            ->discoverResources(in: app_path('Filament/Clinic/Resources'), for: 'App\Filament\Clinic\Resources')
            ->discoverPages(in: app_path('Filament/Clinic/Pages'), for: 'App\Filament\Clinic\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Phase 1B-2B — verified tenant identity + switcher in the topbar,
            // immediately before the account menu. Rendered as a Livewire
            // component so switching is a CSRF-protected, server-verified POST.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => view('filament.clinic.tenant-switcher'),
            )
            // Phase 2B — verified branch identity + switcher, next to the tenant
            // switcher. Also a Livewire component, so branch switching is a
            // CSRF-protected, server-verified POST.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => view('filament.clinic.branch-switcher'),
            )
            ->discoverWidgets(in: app_path('Filament/Clinic/Widgets'), for: 'App\Filament\Clinic\Widgets')
            ->widgets([
                CacheableWidgetConfiguration::make(HeroSectionWidget::class, ['dataset' => 'clinic.hero']),
                ClinicOverviewWidget::class,
                // WS7.D — live practitioner workload / today's queue from the
                // verified tenant database (never demo statistics).
                PractitionerWorkloadWidget::class,
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'clinic.examinations', 'variant' => 'list', 'span' => 1]),
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'clinic.schedule', 'variant' => 'list', 'span' => 1]),
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'clinic.status', 'variant' => 'breakdown', 'span' => 1]),
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'clinic.workflow', 'variant' => 'breakdown', 'span' => 1]),
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'clinic.queue', 'variant' => 'list', 'span' => 1]),
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'clinic.token', 'variant' => 'metric', 'span' => 1]),
            ])
            // Phase 1B-4 — host every module allowed on the Clinic panel. No
            // module-specific logic lives here; modules register themselves.
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
            // Phase 3 — `Authenticate` and the Clinic tenant guard are replayed
            // on Livewire update requests (Livewire's persistent-middleware
            // mechanism rebuilds the originating panel route and re-runs the
            // allow-listed middleware), so a tenant-backed resource can never be
            // driven from outside the Clinic gateway.
            //
            // `InitializeTenantDatabase` is deliberately NOT persistent: the
            // update endpoint already receives it from the `web` group (see
            // bootstrap/app.php), which binds the verified tenant database for
            // the whole update request. A replayed run would release that
            // binding mid-request because the middleware frees the connection in
            // `finally`. Panel page loads still bind it from the panel stack.
            ->authMiddleware([
                Authenticate::class,
                EnsureClinicTenantContext::class,
            ], isPersistent: true)
            ->authMiddleware([
                InitializeTenantDatabase::class,
            ]);
    }
}
