<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Patient\Middleware\EnsurePatientTenantContext;
use App\Filament\Patient\Pages\Dashboard;
use App\Filament\Patient\Widgets\PatientOverviewWidget;
use App\Filament\Shared\Widgets\CacheableWidgetConfiguration;
use App\Filament\Shared\Widgets\DemoSectionWidget;
use App\Filament\Shared\Widgets\HeroSectionWidget;
use App\Localization\Middleware\SetLocale;
use App\Platform\Filament\ModulePanelPlugin;
use App\Platform\Tenancy\Http\Middleware\InitializeTenantDatabase;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PatientPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('patient')
            ->path('patient')
            ->viteTheme('resources/css/filament/lba-cloud/theme.css')
            ->brandName(fn (): string => __('lba.panel.patient'))
            ->darkMode(false)
            ->login(Login::class)
            ->colors([
                // LBA design token: teal #087F80
                'primary' => Color::hex('#087F80'),
            ])
            // Premium shell: ~256px expanded sidebar, ~72px collapsed icon rail.
            // On mobile Filament renders this sidebar as an off-canvas drawer.
            ->sidebarWidth('16rem')
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups()
            ->discoverResources(in: app_path('Filament/Patient/Resources'), for: 'App\Filament\Patient\Resources')
            ->discoverPages(in: app_path('Filament/Patient/Pages'), for: 'App\Filament\Patient\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Patient/Widgets'), for: 'App\Filament\Patient\Widgets')
            ->widgets([
                CacheableWidgetConfiguration::make(HeroSectionWidget::class, ['dataset' => 'patient.hero']),
                PatientOverviewWidget::class,
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'patient.appointment', 'variant' => 'metric', 'span' => 1]),
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'patient.reports', 'variant' => 'list', 'span' => 1]),
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'patient.consultation', 'variant' => 'cards', 'span' => 1]),
                CacheableWidgetConfiguration::make(DemoSectionWidget::class, ['dataset' => 'patient.products', 'variant' => 'cards', 'span' => 1]),
            ])
            // Phase 1B-4 — host every module allowed on the Patient panel.
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
            // Phase 8A — `Authenticate` and the patient tenant guard are
            // replayed on Livewire update requests (Livewire's persistent-
            // middleware mechanism rebuilds the originating panel route and
            // re-runs the allow-listed middleware), so a tenant-backed patient
            // resource can never be driven from outside the Patient gateway.
            //
            // `InitializeTenantDatabase` is deliberately NOT persistent: the
            // update endpoint already receives it from the `web` group (see
            // bootstrap/app.php), which binds the verified tenant database for
            // the whole update request. A replayed run would release that
            // binding mid-request because the middleware frees the connection in
            // `finally`. Panel page loads still bind it from the panel stack.
            ->authMiddleware([
                Authenticate::class,
                EnsurePatientTenantContext::class,
            ], isPersistent: true)
            ->authMiddleware([
                InitializeTenantDatabase::class,
            ]);
    }
}
