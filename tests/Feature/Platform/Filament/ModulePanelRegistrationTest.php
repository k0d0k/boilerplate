<?php

namespace Tests\Feature\Platform\Filament;

use App\Filament\Clinic\Middleware\EnsureClinicTenantContext;
use App\Filament\Clinic\Resources\TenantBranchResource;
use App\Filament\Principal\Resources\AiWalletResource;
use App\Filament\Principal\Resources\AuditLogResource;
use App\Filament\Principal\Resources\BrandResource;
use App\Filament\Principal\Resources\PaymentGatewayResource;
use App\Filament\Principal\Resources\SubscriptionPlanResource;
use App\Filament\Principal\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\RoleKey;
use App\Platform\FeatureGate\Contracts\EvaluatesModuleAccess;
use App\Platform\FeatureGate\Contracts\GrantsModuleEntitlements;
use App\Platform\FeatureGate\Contracts\VerifiesModuleSubscriptions;
use App\Platform\FeatureGate\EntitlementGrant;
use App\Platform\FeatureGate\Exceptions\ModuleAccessDeniedException;
use App\Platform\FeatureGate\FeatureGateReason;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Commerce\Filament\Resources\InvoiceResource;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Filament\Resources\PaymentTransactionResource;
use Modules\Commerce\Filament\Resources\ProductCategoryResource;
use Modules\Commerce\Filament\Resources\ProductResource;
use Modules\Commerce\Filament\Resources\StockLevelResource;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\DemoShowcase\Filament\Pages\DemoShowcasePage;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1B-4 module-to-panel registration + enforcement.
 *
 * Proves the demonstration module lands in exactly one panel, that its page is
 * gated on the real HTTP route *and* on the Livewire surface, and that the
 * approved panels, login and tenant switcher are unchanged.
 */
class ModulePanelRegistrationTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
    }

    // ------------------------------------------------------------- Helpers

    private function clinicUser(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    /**
     * Bind explicit, synthetic, test-scoped entitlement + subscription
     * providers. Returns the entitlement provider so a test may revoke the
     * grant mid-flight.
     */
    private function installModule(string $tenantId): void
    {
        RegisteredModule::factory()->forKey('demo.showcase')->enabled()->create();

        ModuleInstallation::factory()
            ->forModule('demo.showcase')
            ->forScope(ModuleScope::TENANT, $tenantId)
            ->enabled()
            ->create();
    }

    /**
     * Bind explicit, synthetic, test-scoped entitlement + subscription
     * providers. Returns the entitlement provider so a test may revoke the
     * grant mid-flight.
     */
    private function bindSynthetic(string $scopeKey, bool $subscriptionRequired = false, ?string $reference = null): object
    {
        $entitlements = new class('demo.showcase', $scopeKey, $subscriptionRequired, $reference) implements GrantsModuleEntitlements
        {
            public bool $granted = true;

            public function __construct(
                private readonly string $moduleKey,
                private readonly string $scopeKey,
                private readonly bool $subscriptionRequired,
                private readonly ?string $reference,
            ) {}

            public function entitlementFor(string $moduleKey, ModuleScopeKey $scope): ?EntitlementGrant
            {
                if ($moduleKey !== $this->moduleKey || $scope->scope !== ModuleScope::TENANT || $scope->scopeKey !== $this->scopeKey) {
                    return null;
                }

                return new EntitlementGrant(
                    granted: $this->granted,
                    subscriptionRequired: $this->subscriptionRequired,
                    subscriptionReference: $this->reference,
                );
            }
        };

        $subscriptions = new class($reference) implements VerifiesModuleSubscriptions
        {
            public function __construct(private readonly ?string $reference) {}

            public function isActive(string $moduleKey, ModuleScopeKey $scope, ?string $reference): bool
            {
                // A null reference is never an active subscription: an
                // unimplemented verifier must fail closed.
                return $reference !== null
                    && $moduleKey === 'demo.showcase'
                    && $reference === $this->reference;
            }
        };

        $this->app->instance(GrantsModuleEntitlements::class, $entitlements);
        $this->app->instance(VerifiesModuleSubscriptions::class, $subscriptions);
        $this->app->forgetInstance(EvaluatesModuleAccess::class);

        return $entitlements;
    }

    // ---------------------------------------------------- Registration (case 3)

    public function test_the_demonstration_module_registers_only_into_the_clinic_panel(): void
    {
        $clinicPages = Filament::getPanel(PanelAccessPolicy::PANEL_CLINIC)->getPages();

        $this->assertContains(DemoShowcasePage::class, $clinicPages);

        foreach ([PanelAccessPolicy::PANEL_PATIENT, PanelAccessPolicy::PANEL_PRINCIPAL] as $panelId) {
            $this->assertNotContains(
                DemoShowcasePage::class,
                Filament::getPanel($panelId)->getPages(),
                "The module page must not register into the [{$panelId}] panel.",
            );
        }
    }

    public function test_the_module_route_is_registered_only_on_the_approved_panel(): void
    {
        $this->assertTrue(Route::has('filament.clinic.pages.demo-showcase'));
        $this->assertFalse(Route::has('filament.patient.pages.demo-showcase'));
        $this->assertFalse(Route::has('filament.principal.pages.demo-showcase'));
    }

    public function test_the_module_page_is_hidden_from_navigation(): void
    {
        $this->assertFalse(DemoShowcasePage::shouldRegisterNavigation());
    }

    public function test_the_module_route_carries_the_feature_gate_and_tenant_context_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('filament.clinic.pages.demo-showcase');

        $this->assertInstanceOf(RoutingRoute::class, $route);

        $middleware = $route->gatherMiddleware();
        $serialized = implode('|', array_map(static fn ($m) => is_string($m) ? $m : '', $middleware));

        $this->assertStringContainsString(EnsureModuleAccess::class.':demo.showcase', $serialized);
        $this->assertStringContainsString(EnsureClinicTenantContext::class, $serialized);
    }

    // -------------------------------------------------- HTTP enforcement (case 12)

    public function test_the_module_page_is_denied_without_a_verified_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        // No entitlement provider grants anything for this tenant.
        $this->actingAs($user)->get('/clinic/demo-showcase')->assertForbidden();
    }

    public function test_the_module_page_is_reachable_only_with_a_verified_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule($tenant->getKey());
        $this->bindSynthetic($tenant->getKey());

        $this->actingAs($user)
            ->get('/clinic/demo-showcase')
            ->assertOk()
            ->assertSee(__('demomodule::demomodule.page.section_heading'))
            ->assertSee(__('demomodule::demomodule.page.action'));
    }

    public function test_a_module_requiring_an_unimplemented_subscription_is_denied_over_http(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule($tenant->getKey());
        $this->bindSynthetic($tenant->getKey(), subscriptionRequired: true, reference: null);

        $this->actingAs($user)->get('/clinic/demo-showcase')->assertForbidden();
    }

    // ------------------------------------------------ Livewire enforcement (case 12)

    /**
     * The page's mount gate — Filament's `mountCanAuthorizeAccess()` calling
     * `canAccess()` — refuses to render without a verified grant. Livewire's
     * test harness surfaces the Filament `abort(403)` as an HTTP response
     * rather than a thrown exception.
     */
    public function test_the_module_page_refuses_to_mount_without_a_grant(): void
    {
        Filament::setCurrentPanel(Filament::getPanel(PanelAccessPolicy::PANEL_CLINIC));

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->actingAs($user);

        Livewire::test(DemoShowcasePage::class)->assertForbidden();
    }

    public function test_the_module_page_renders_on_the_livewire_surface_when_entitled(): void
    {
        Filament::setCurrentPanel(Filament::getPanel(PanelAccessPolicy::PANEL_CLINIC));

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule($tenant->getKey());
        $this->bindSynthetic($tenant->getKey());

        $this->actingAs($user);

        Livewire::test(DemoShowcasePage::class)
            ->assertOk()
            ->assertSee(__('demomodule::demomodule.page.section_heading'));
    }

    /**
     * The hydration gate predicate. Every Livewire hydration runs Filament's
     * `hydrateCanAuthorizeAccess()` → `canAccess()`, so a revoked grant stops
     * the component immediately — not just on the next full page load.
     */
    public function test_the_module_page_hydration_gate_flips_the_moment_the_grant_is_revoked(): void
    {
        Filament::setCurrentPanel(Filament::getPanel(PanelAccessPolicy::PANEL_CLINIC));

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule($tenant->getKey());
        $entitlements = $this->bindSynthetic($tenant->getKey());

        $this->actingAs($user);

        $this->assertTrue(DemoShowcasePage::canAccess());

        $entitlements->granted = false;

        $this->assertFalse(DemoShowcasePage::canAccess());

        // A fresh mount is refused as well.
        Livewire::test(DemoShowcasePage::class)->assertForbidden();
    }

    /**
     * The protected Livewire action re-evaluates the FeatureGate itself before
     * doing any work, so it fails closed even if it is somehow reached.
     */
    public function test_the_protected_livewire_action_enforces_the_feature_gate(): void
    {
        Filament::setCurrentPanel(Filament::getPanel(PanelAccessPolicy::PANEL_CLINIC));

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule($tenant->getKey());
        $entitlements = $this->bindSynthetic($tenant->getKey());

        $this->actingAs($user);

        $page = new DemoShowcasePage;

        $page->performProtectedAction();

        $this->assertSame(1, $page->protectedActionsPerformed);

        $entitlements->granted = false;

        try {
            $page->performProtectedAction();

            $this->fail('A revoked entitlement must deny the Livewire action.');
        } catch (ModuleAccessDeniedException $exception) {
            $this->assertSame(FeatureGateReason::NOT_ENTITLED, $exception->result->reason);
        }

        // The denied action must not have advanced any protected work.
        $this->assertSame(1, $page->protectedActionsPerformed);
    }

    // -------------------------------------------------------- No regression (case 14)

    public function test_the_three_approved_panels_are_unchanged(): void
    {
        $ids = array_keys(Filament::getPanels());
        sort($ids);

        $this->assertSame(['clinic', 'patient', 'principal'], $ids);
    }

    public function test_only_the_shipped_business_resources_are_registered(): void
    {
        // Phase 3 ships the Clinic patient registry and appointment book
        // alongside branch management; Phase 4 adds the LBA examination
        // resource; Workstream 2 adds the clinic consultation workspace,
        // Workstream 3 adds the Commerce resources and Workstream 9 adds the
        // knowledge collection/document resources. No clinical resource is
        // registered on the governance panel.
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
            'The Clinic panel may only ship the branch, patient, practitioner, appointment, examination, consultation, commerce and knowledge resources.',
        );

        $this->assertSame([], Filament::getPanel('patient')->getResources());

        // Phase 7D adds the Principal governance resources (brands, tenants);
        // Workstream 3 adds the payment-gateway configuration; Workstream 4
        // adds the subscription-plan catalogue, the AI credit-wallet surface
        // and the read-only audit log.
        $this->assertEqualsCanonicalizing(
            [
                BrandResource::class,
                TenantResource::class,
                PaymentGatewayResource::class,
                SubscriptionPlanResource::class,
                AiWalletResource::class,
                AuditLogResource::class,
            ],
            array_values(Filament::getPanel('principal')->getResources()),
            'The Principal panel may only ship the governance, gateway, subscription plan, AI wallet and audit log resources.',
        );
    }

    public function test_the_unified_login_route_still_exists(): void
    {
        $this->assertTrue(Route::has('login'));
        $this->assertFalse(Route::has('filament.admin.auth.login'));
    }
}
