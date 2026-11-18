<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 2B — Clinic panel branch management (server layer).
 *
 * Exercises the real HTTP surface: the branch resource boots and is authorized
 * per the verified tenant context, a freshly provisioned tenant can open its
 * first-branch setup (no branch-context deadlock), and the topbar branch
 * switcher is a server-verified, CSRF-protected switch that refuses any branch
 * the identity may not access.
 */
class ClinicBranchManagementTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    private function owner(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    private function context(): ManagesBranchContext
    {
        return app(ManagesBranchContext::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Real browser round-trip
    |--------------------------------------------------------------------------
    */

    private function renderPage(string $path): string
    {
        return $this->get($path)->assertOk()->getContent();
    }

    private function componentSnapshot(string $html, string $component): string
    {
        if (! preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The rendered page did not expose any Livewire snapshot.');
        }

        foreach ($matches[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                return $snapshot;
            }
        }

        $this->fail("The rendered page did not expose a [{$component}] component.");
    }

    /**
     * @param  list<mixed>  $params
     */
    private function callLivewire(string $html, string $component, string $method, array $params = []): TestResponse
    {
        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $this->componentSnapshot($html, $component),
                'calls' => [['method' => $method, 'params' => $params, 'path' => '']],
                'updates' => [],
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /*
    |--------------------------------------------------------------------------
    | Resource authorization & boot
    |--------------------------------------------------------------------------
    */

    public function test_the_branch_management_page_boots_for_a_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant, ['name' => 'Cabang Merdeka']);

        $this->actingAs($owner)
            ->get('/clinic/tenant-branches')
            ->assertOk()
            ->assertSee('Cabang Merdeka');
    }

    public function test_a_non_manager_is_denied_branch_management(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = User::factory()->role(RoleKey::RECEPTIONIST)->create();
        $this->giveMembership($staff, $tenant, [RoleKey::RECEPTIONIST]);
        $this->provisionTenantDatabase($tenant);

        $this->actingAs($staff)->get('/clinic/tenant-branches')->assertForbidden();
    }

    public function test_a_newly_provisioned_tenant_can_set_up_its_first_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        // No branches yet: the list is reachable (no branch-context deadlock).
        $this->actingAs($owner)->get('/clinic/tenant-branches')->assertOk();

        // ...and the first-branch create screen opens too.
        $this->actingAs($owner)->get('/clinic/tenant-branches/create')->assertOk();
    }

    public function test_the_clinic_panel_boots_with_its_theme_assets(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->actingAs($owner)
            ->get('/clinic')
            ->assertOk()
            ->assertSee('stylesheet', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Branch switcher (server-verified switch)
    |--------------------------------------------------------------------------
    */

    public function test_the_branch_switcher_rejects_a_fabricated_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->createBranch($tenant);

        $this->actingAs($owner);

        $this->callLivewire(
            $this->renderPage('/clinic'),
            'branch-switcher',
            'switchTo',
            [(string) Str::ulid()],
        )->assertForbidden();
    }

    public function test_the_branch_switcher_switches_to_a_verified_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $first = $this->createBranch($tenant, ['name' => 'Alpha']);
        $second = $this->createBranch($tenant, ['name' => 'Beta']);

        $this->actingAs($owner);

        $response = $this->callLivewire(
            $this->renderPage('/clinic'),
            'branch-switcher',
            'switchTo',
            [$second->getKey()],
        );

        $response->assertOk();
        $this->assertSame('/clinic', $response->json('components.0.effects.redirect'));

        // The verified selection is stored and revalidated.
        $this->tenantDatabases()->configure($tenant->getKey());
        $this->assertSame($second->getKey(), $this->context()->currentBranchId($owner));
        $this->assertNotSame($first->getKey(), $this->context()->currentBranchId($owner));
    }
}
