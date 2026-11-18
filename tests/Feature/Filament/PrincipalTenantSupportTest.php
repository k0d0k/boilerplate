<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Pages\TenantSupport;
use App\Filament\Principal\Resources\BrandResource\RelationManagers\TenantsRelationManager;
use App\Filament\Principal\Resources\TenantResource\Pages\ListTenants;
use App\Models\Brand;
use App\Models\Tenant;
use App\Platform\Access\SupportTenantSession;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS9.E — the "open tenant" support view boundary (server layer).
 *
 * A Super Admin opens a bounded, auditable, read-only context over ONE active
 * tenant from the Tenant list (or a Brand's tenant list) and returns with a
 * single "Kembali ke Principal" action. These tests pin the page boundary: the
 * view is unreachable without a live context, shows only the opened tenant, is
 * locked by expiry, and is released on return.
 */
class PrincipalTenantSupportTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, DrivesPrincipalPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    public function test_the_support_view_is_forbidden_without_an_active_context(): void
    {
        $this->actingAs($this->principal())
            ->get('/principal/tenant-support')
            ->assertForbidden();
    }

    public function test_opening_from_the_tenant_list_starts_the_bounded_read_only_view(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Sejahtera']);

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants');
        $this->assertStringContainsString(__('lba.support.open'), $html);

        $this->postLivewireCalls($html, ListTenants::class, [
            ['method' => 'mountAction', 'params' => ['openTenant', [], ['table' => true, 'recordKey' => $tenant->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->get('/principal/tenant-support')
            ->assertOk()
            ->assertSee('Klinik Sejahtera')
            ->assertSee(__('lba.support.back_to_principal'))
            ->assertSee(__('lba.support.notice'));
    }

    public function test_opening_from_a_brands_tenant_list_starts_the_bounded_read_only_view(): void
    {
        $brand = Brand::factory()->create();
        $tenant = Tenant::factory()->create(['name' => 'Klinik Brand Sejahtera']);
        $brand->tenants()->attach($tenant->getKey(), ['is_primary' => true]);

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/brands/'.$brand->getKey().'/edit');

        $this->callRelationManager($html, TenantsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['openTenant', [], ['table' => true, 'recordKey' => $tenant->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        // Either entry point opens the SAME bounded, read-only context.
        $this->get('/principal/tenant-support')
            ->assertOk()
            ->assertSee('Klinik Brand Sejahtera')
            ->assertSee(__('lba.support.back_to_principal'));
    }

    public function test_the_view_only_shows_the_opened_tenant(): void
    {
        $actor = $this->principal();
        $opened = Tenant::factory()->create(['name' => 'Klinik Terbuka']);
        $other = Tenant::factory()->create(['name' => 'Klinik Lain']);

        app(SupportTenantSession::class)->open($actor, $opened->getKey());

        $this->actingAs($actor)
            ->get('/principal/tenant-support')
            ->assertOk()
            ->assertSee('Klinik Terbuka')
            ->assertDontSee($other->getKey());
    }

    public function test_an_expired_context_locks_the_view_again(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create(['name' => 'Klinik Sejahtera']);

        app(SupportTenantSession::class)->open($actor, $tenant->getKey(), ttlMinutes: 30);

        $this->actingAs($actor)->get('/principal/tenant-support')->assertOk();

        $this->travel(31)->minutes();

        $this->actingAs($actor)->get('/principal/tenant-support')->assertForbidden();
    }

    public function test_returning_to_the_principal_releases_the_context(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create(['name' => 'Klinik Sejahtera']);

        app(SupportTenantSession::class)->open($actor, $tenant->getKey());

        $this->actingAs($actor);

        $html = $this->renderPage('/principal/tenant-support');

        $this->postLivewireCalls($html, TenantSupport::class, [
            ['method' => 'closeSupport', 'params' => []],
        ])->assertOk();

        $this->get('/principal/tenant-support')->assertForbidden();
    }
}
