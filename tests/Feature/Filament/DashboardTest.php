<?php

namespace Tests\Feature\Filament;

use App\Filament\Clinic\Resources\TenantBranchResource;
use App\Filament\Clinic\Widgets\ClinicOverviewWidget;
use App\Filament\Principal\Resources\AiWalletResource;
use App\Filament\Principal\Resources\AuditLogResource;
use App\Filament\Principal\Resources\BrandResource;
use App\Filament\Principal\Resources\PaymentGatewayResource;
use App\Filament\Principal\Resources\SubscriptionPlanResource;
use App\Filament\Principal\Resources\TenantResource;
use App\Filament\Principal\Widgets\PlatformOverviewWidget;
use App\Filament\Shared\Data\LbaDemoData;
use App\Filament\Shared\Navigation\LbaPreviewNavigation;
use App\Filament\Shared\Widgets\HeroSectionWidget;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Commerce\Filament\Resources\InvoiceResource;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Filament\Resources\PaymentTransactionResource;
use Modules\Commerce\Filament\Resources\ProductCategoryResource;
use Modules\Commerce\Filament\Resources\ProductResource;
use Modules\Commerce\Filament\Resources\StockLevelResource;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1A-3 premium dashboard tests.
 *
 * Dashboards are preview surfaces: they must render for the authorised identity,
 * expose the synthetic-data disclaimer, keep the premium shell configuration and
 * never surface clinical content inside the Principal (governance) panel.
 */
class DashboardTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    private const PANEL_PATHS = ['principal', 'clinic', 'patient'];

    /** @return array<string, string> */
    private function titles(): array
    {
        return [
            'principal' => 'Ringkasan Platform',
            'clinic' => 'Dasbor Klinik',
            'patient' => 'Beranda Pasien',
        ];
    }

    /** @return array<string, string> */
    private function roles(): array
    {
        return [
            'principal' => 'principal_super_admin',
            'clinic' => 'tenant_owner',
            'patient' => 'patient_customer',
        ];
    }

    /**
     * A synthetic identity that may reach the given panel's dashboard.
     *
     * Phase 1B-2B (STEP 5): the provisional role still routes a Clinic identity
     * to the panel, but the dashboard now requires a verified active tenant
     * context, so the Clinic identity is given one synthetic active membership.
     *
     * Phase 8A: the Patient panel now enforces the same server-side gateway — a
     * patient identity is always scoped to a partner clinic through an active
     * membership — so it receives one synthetic active membership too.
     */
    private function userForPanel(string $path): User
    {
        $user = User::factory()->role($this->roles()[$path])->create();

        if (in_array($path, ['clinic', 'patient'], true)) {
            $this->giveMembership($user, Tenant::factory()->create());
        }

        return $user;
    }

    public function test_each_authorized_identity_sees_its_premium_dashboard(): void
    {
        foreach ($this->titles() as $path => $title) {
            $user = $this->userForPanel($path);

            $this->actingAs($user)
                ->get('/'.$path)
                ->assertOk()
                ->assertSee($title);
        }
    }

    public function test_each_dashboard_renders_its_registered_widget_composition(): void
    {
        // Filament hydrates dashboard widgets lazily, so the initial payload
        // carries the widget placeholders. Asserting on them proves each panel
        // composes the shared shell plus its own panel-scoped widgets.
        foreach ($this->titles() as $path => $title) {
            $user = $this->userForPanel($path);

            $response = $this->actingAs($user)->get('/'.$path)->assertOk();

            $widgets = $this->normalizedWidgets($path);
            $this->assertNotEmpty($widgets);

            foreach ($widgets as $widget) {
                $response->assertSee(class_basename($widget));
            }

            // Every panel shares the premium hero widget.
            $response->assertSee(class_basename(HeroSectionWidget::class));
        }
    }

    public function test_shared_hero_widget_renders_synthetic_dataset_content(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        Livewire::test(HeroSectionWidget::class, ['dataset' => 'principal.hero'])
            ->assertSee('Ringkasan Platform')
            ->assertSee(LbaDemoData::syntheticNotice());
    }

    public function test_principal_kpi_widget_renders_live_governance_figures(): void
    {
        // WS4.3 replaced the synthetic KPI strip with live central governance
        // figures. A bare Livewire mount resolves every figure from the (empty)
        // central tables, so the labels render with honest zero values.
        Livewire::test(PlatformOverviewWidget::class)
            ->assertSee('Mitra')
            ->assertSee('Basis Data Siap')
            ->assertSee('Langganan Aktif')
            ->assertSee('Kredit AI Terpakai');
    }

    public function test_clinic_kpi_widget_renders_live_labels_with_an_honest_empty_state(): void
    {
        // Phase 3 replaced the synthetic clinic counters with live indicators.
        // A bare Livewire mount has no verified tenant context, so every
        // indicator renders the honest empty state — never a fabricated value.
        Livewire::test(ClinicOverviewWidget::class)
            ->assertSee('Total Pasien')
            ->assertSee('Janji Temu Hari Ini')
            ->assertSee('Janji Temu Mendatang')
            ->assertSee('Pasien Check-in')
            ->assertSee('—');
    }

    public function test_principal_dashboard_never_surfaces_clinical_content(): void
    {
        $principal = User::factory()->role('principal_super_admin')->create();

        // Strings that exist only in the clinic clinical datasets.
        $response = $this->actingAs($principal)->get('/principal')->assertOk();

        $response->assertDontSee('LBA-2026-00841');
        $response->assertDontSee('Antrean Peninjau');
        $response->assertDontSee('Pratinjau Saldo Token AI');
    }

    public function test_principal_widgets_only_consume_principal_scoped_datasets(): void
    {
        // Guarantees the Principal (governance) panel can only ever read the
        // non-clinical `principal.*` demo datasets, never clinic/patient data.
        foreach (Filament::getPanel('principal')->getWidgets() as $widget) {
            if (! $widget instanceof WidgetConfiguration) {
                continue;
            }

            $dataset = $widget->getProperties()['dataset'] ?? null;

            if ($dataset === null) {
                continue;
            }

            $this->assertStringStartsWith(
                'principal.',
                $dataset,
                "Principal widget may not consume the non-principal dataset [{$dataset}].",
            );
        }
    }

    public function test_principal_widgets_are_scoped_to_principal_or_shared_namespaces(): void
    {
        foreach ($this->normalizedWidgets('principal') as $widget) {
            $this->assertMatchesRegularExpression(
                '/^App\\\\Filament\\\\(Principal|Shared)\\\\Widgets\\\\/',
                $widget,
                "Principal panel must not register the foreign widget [{$widget}].",
            );
        }

        foreach ($this->normalizedWidgets('clinic') as $widget) {
            $this->assertMatchesRegularExpression(
                '/^App\\\\Filament\\\\(Clinic|Shared)\\\\Widgets\\\\/',
                $widget,
                "Clinic panel must not register the foreign widget [{$widget}].",
            );
        }

        foreach ($this->normalizedWidgets('patient') as $widget) {
            $this->assertMatchesRegularExpression(
                '/^App\\\\Filament\\\\(Patient|Shared)\\\\Widgets\\\\/',
                $widget,
                "Patient panel must not register the foreign widget [{$widget}].",
            );
        }
    }

    public function test_only_the_shipped_business_resources_are_registered(): void
    {
        // Dashboards stay preview surfaces, but the Clinic panel ships the
        // branch-management, patient, appointment and LBA examination
        // resources (Phases 3-4), plus the Workstream 2 consultation workspace,
        // the Workstream 3 Commerce resources and the Workstream 9 knowledge
        // collection/document resources. The Principal governance panel ships
        // the Phase 7D brand/tenant resources alongside the Workstream 3
        // payment-gateway configuration and the Workstream 4 subscription-plan
        // catalogue; no clinical resource is registered on the governance panel.
        $clinicResources = array_values(Filament::getPanel('clinic')->getResources());

        $this->assertEqualsCanonicalizing(
            [
                TenantBranchResource::class,
                PatientResource::class,
                AppointmentResource::class,
                ExaminationResource::class,
                ConsultationResource::class,
                PractitionerResource::class,
                ProductCategoryResource::class,
                ProductResource::class,
                StockLevelResource::class,
                OrderResource::class,
                InvoiceResource::class,
                PaymentTransactionResource::class,
                KnowledgeCollectionResource::class,
                KnowledgeDocumentResource::class,
            ],
            $clinicResources,
            'The Clinic panel may only ship the branch, patient, practitioner, appointment, examination, consultation and commerce resources.',
        );

        $this->assertSame(
            [],
            Filament::getPanel('patient')->getResources(),
            'The Patient panel must not register any resource yet.',
        );

        $this->assertEqualsCanonicalizing(
            [BrandResource::class, TenantResource::class, PaymentGatewayResource::class, SubscriptionPlanResource::class, AiWalletResource::class, AuditLogResource::class],
            array_values(Filament::getPanel('principal')->getResources()),
            'The Principal panel may only ship the governance, gateway, subscription plan, AI wallet and audit log resources.',
        );
    }

    public function test_premium_sidebar_shell_configuration_is_applied_to_every_panel(): void
    {
        foreach (self::PANEL_PATHS as $path) {
            $panel = Filament::getPanel($path);

            // ~256px expanded, ~72px collapsed icon rail, collapsible + topbar.
            $this->assertSame('16rem', $panel->getSidebarWidth());
            $this->assertSame('4.5rem', $panel->getCollapsedSidebarWidth());
            $this->assertTrue($panel->isSidebarCollapsibleOnDesktop());
            $this->assertTrue($panel->hasCollapsibleNavigationGroups());
            $this->assertTrue($panel->hasTopbar());
        }
    }

    public function test_every_panel_registers_the_lba_teal_primary_colour(): void
    {
        foreach (self::PANEL_PATHS as $path) {
            $this->assertArrayHasKey('primary', Filament::getPanel($path)->getColors());
        }

        // Principal additionally registers the navy governance token.
        $this->assertArrayHasKey('navy', Filament::getPanel('principal')->getColors());
    }

    public function test_unimplemented_modules_are_never_emitted_as_fake_links(): void
    {
        // Phase 1A-4: the documented-but-unimplemented modules are rendered as
        // accessible, non-interactive preview labels through a sidebar render
        // hook. They are never registered as Filament navigation and never
        // emitted as `href="#"` fake links.
        //
        // The Principal transition links point to shipped TailAdmin routes.
        // The Clinic and Patient roadmap labels remain non-interactive.
        //
        // NOTE: the preview-group *label* is asserted in the dedicated
        // per-panel tests below. Filament registers unscoped render hooks once
        // per app instance (the first panel booted wins), so the label can only
        // be verified when a single panel is booted per test method.
        foreach (self::PANEL_PATHS as $path) {
            $this->actingAs($this->userForPanel($path))
                ->get('/'.$path)
                ->assertOk()
                // ...but no fake, clickable anchor is rendered anywhere.
                ->assertDontSee('href="#"', false);

            $expectedLinks = match ($path) {
                // The governance group exposes ONE canonical entry per feature,
                // in the order docs/PANELS_NAVIGATION.md documents: the single
                // "Brand & Mitra" surface, subscriptions, AI credits, the
                // TailAdmin-served Module Manager, entitlements, payment
                // providers, business analytics, system audit and finally
                // "Pengaturan Global"; the Principal-only CRUD generator closes
                // the menu. (The interim Filament screens keep their routes but
                // no longer register a sidebar entry.)
                'principal' => [
                    route('principal.tailadmin.brands.index'),
                    route('principal.tailadmin.tenants.index'),
                    route('principal.tailadmin.subscriptions.index'),
                    route('principal.tailadmin.ai-credits.index'),
                    route('principal.tailadmin.modules.index'),
                    route('principal.tailadmin.entitlements.index'),
                    route('principal.tailadmin.payment-providers.index'),
                    route('principal.tailadmin.analytics.index'),
                    route('principal.tailadmin.audit.index'),
                    route('principal.tailadmin.settings.index'),
                    route('principal.tailadmin.crud.index'),
                ],
                'clinic' => [route('clinic.tailadmin.dashboard')],
                default => [],
            };

            $this->assertSame($expectedLinks, array_map(
                fn (NavigationItem $item): string => $item->getUrl(),
                Filament::getPanel($path)->getNavigationItems(),
            ));
        }
    }

    public function test_principal_panel_renders_no_preview_group(): void
    {
        // WS4.5 replaced the last Principal preview menu (Platform Settings)
        // with a real page, so the governance panel documents no roadmap
        // modules and renders no preview group at all.
        $this->actingAs($this->userForPanel('principal'))
            ->get('/principal')
            ->assertOk()
            ->assertDontSee(LbaPreviewNavigation::previewGroupLabel());
    }

    public function test_clinic_panel_renders_no_preview_group(): void
    {
        // Every Clinic module (Patients, Appointments, LBA, Consultations,
        // Commerce) now ships real, route-backed pages, so the operational
        // panel documents no roadmap modules and renders no preview group at
        // all.
        $this->actingAs($this->userForPanel('clinic'))
            ->get('/clinic')
            ->assertOk()
            ->assertDontSee(LbaPreviewNavigation::previewGroupLabel());
    }

    public function test_patient_panel_has_no_roadmap_preview_group(): void
    {
        $this->actingAs($this->userForPanel('patient'))
            ->get('/patient')
            ->assertOk()
            ->assertDontSee(LbaPreviewNavigation::previewGroupLabel());
    }

    public function test_admin_panel_route_does_not_exist(): void
    {
        $this->get('/admin')->assertNotFound();
        $this->get('/admin/login')->assertNotFound();
    }

    /**
     * @return array<class-string>
     */
    private function normalizedWidgets(string $panel): array
    {
        return array_values(array_map(
            fn (string|WidgetConfiguration $widget): string => $widget instanceof WidgetConfiguration
                ? $widget->widget
                : $widget,
            Filament::getPanel($panel)->getWidgets(),
        ));
    }
}
