<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Resources\BrandResource;
use App\Filament\Principal\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Principal\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Principal\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Principal\Resources\BrandResource\RelationManagers\TenantsRelationManager;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * Phase 7D — Principal brand management (server layer).
 *
 * A brand is a commercial identity only (ARCH-05): it is never a tenant and it
 * holds no clinical data. These tests exercise the real Filament surface — the
 * HTTP boot, the Livewire CRUD/table actions and the tenant↔brand relation
 * manager — and assert the authorization boundary against the real authorizer,
 * never the rendered UI.
 *
 * Interaction is driven through the genuine Livewire round-trip (see
 * {@see DrivesPrincipalPanel}), because Filament 5's `Livewire::test()` helper
 * cannot perform follow-up calls against this application's full-page
 * components.
 */
class PrincipalBrandManagementTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, DrivesPrincipalPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    // ------------------------------------------------------- Access control

    public function test_the_brand_registry_boots_for_an_authorized_principal(): void
    {
        Brand::factory()->create(['name' => 'Aurora Diagnostics']);

        $this->actingAs($this->principal())
            ->get('/principal/brands')
            ->assertOk()
            ->assertSee('Aurora Diagnostics');
    }

    public function test_a_principal_without_the_brand_permission_is_denied(): void
    {
        // principal_finance may reach the Principal panel but holds no
        // `platform.brand.manage`, so the resource must refuse it.
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);

        $this->actingAs($finance)->get('/principal/brands')->assertForbidden();
    }

    public function test_a_clinic_identity_cannot_reach_the_brand_registry(): void
    {
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->actingAs($owner)->get('/principal/brands')->assertForbidden();
    }

    // ----------------------------------------------------------------- CRUD

    public function test_a_principal_can_create_a_brand_with_its_report_identity(): void
    {
        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/brands/create');

        $this->postLivewireCalls($html, CreateBrand::class, [
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'data.name' => 'Nusantara Imaging',
                'data.slug' => 'nusantara-imaging',
                'data.status' => Brand::STATUS_ACTIVE,
                'data.report_display_name' => 'Nusantara Imaging Group',
                'data.report_language' => 'ms-MY',
            ], 'data']],
            ['method' => 'create', 'params' => []],
        ])->assertOk();

        $brand = Brand::query()->where('slug', 'nusantara-imaging')->firstOrFail();

        $this->assertSame('Nusantara Imaging Group', $brand->report_display_name);
        $this->assertSame('ms-MY', $brand->report_language);
        // The immutable report identity snapshots the configured display name.
        $this->assertSame('Nusantara Imaging Group', $brand->reportIdentity()['display_name']);
    }

    public function test_a_principal_can_update_a_brands_report_identity(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/brands/'.$brand->getKey().'/edit');

        $this->postLivewireCalls($html, EditBrand::class, [
            ['method' => 'fillFormDataForTesting', 'params' => [['data.report_display_name' => 'Renamed Brand'], 'data']],
            ['method' => 'save', 'params' => []],
        ])->assertOk();

        $this->assertSame('Renamed Brand', $brand->refresh()->report_display_name);
    }

    // ------------------------------------------------------------- Lifecycle

    public function test_an_authorized_principal_can_suspend_and_reactivate_a_brand(): void
    {
        $brand = Brand::factory()->create(['status' => Brand::STATUS_ACTIVE]);

        $this->actingAs($this->principal());

        $this->postLivewireCalls($this->renderPage('/principal/brands'), ListBrands::class, [
            ['method' => 'mountAction', 'params' => ['suspend', [], ['table' => true, 'recordKey' => $brand->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertSame(Brand::STATUS_SUSPENDED, $brand->refresh()->status);

        // Re-render: suspension swaps the row action from `suspend` to `activate`.
        $this->postLivewireCalls($this->renderPage('/principal/brands'), ListBrands::class, [
            ['method' => 'mountAction', 'params' => ['activate', [], ['table' => true, 'recordKey' => $brand->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertSame(Brand::STATUS_ACTIVE, $brand->refresh()->status);
    }

    public function test_brands_are_never_deletable(): void
    {
        $brand = Brand::factory()->create();

        // Suspension is the non-destructive lifecycle action; a brand is never
        // destroyed so historical reports keep resolving their identity.
        $this->assertFalse(BrandResource::canDelete($brand));
        $this->assertFalse(BrandResource::canDeleteAny());
    }

    // ------------------------------------------------- Tenant↔brand relation

    public function test_attaching_a_tenant_marks_the_brand_primary_on_the_pivot(): void
    {
        $brand = Brand::factory()->create();
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/brands/'.$brand->getKey().'/edit');

        $this->callRelationManager($html, TenantsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['attach', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.recordId' => $tenant->getKey(),
                'mountedActions.0.data.is_primary' => true,
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertDatabaseHas('tenant_brands', [
            'brand_id' => $brand->getKey(),
            'tenant_id' => $tenant->getKey(),
            'is_primary' => true,
        ]);
    }

    public function test_marking_a_brand_primary_clears_the_tenants_other_primary_brand(): void
    {
        $tenant = Tenant::factory()->create();
        $first = Brand::factory()->create();
        $second = Brand::factory()->create();

        $first->tenants()->attach($tenant->getKey(), ['is_primary' => true]);
        $second->tenants()->attach($tenant->getKey(), ['is_primary' => false]);

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/brands/'.$second->getKey().'/edit');

        $this->callRelationManager($html, TenantsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['makePrimary', [], ['table' => true, 'recordKey' => $tenant->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        // Exactly one primary brand remains, and it is the newly chosen one.
        $this->assertSame(
            1,
            (int) DB::table('tenant_brands')->where('tenant_id', $tenant->getKey())->where('is_primary', true)->count(),
        );
        $this->assertTrue((bool) DB::table('tenant_brands')
            ->where('brand_id', $second->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->value('is_primary'));
    }
}
