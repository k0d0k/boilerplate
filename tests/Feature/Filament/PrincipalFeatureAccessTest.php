<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Pages\FeatureAccess;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\EntitlementStatus;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * Phase 7D — Principal Feature Access (server layer).
 *
 * Drives ONLY the existing platform machinery: module ENTITLEMENTS (the same
 * `module_entitlements` grants the FeatureGate reads) and FEATURE PERMISSIONS
 * expressed through the existing RBAC roles on tenant memberships. No parallel
 * permission system is introduced.
 */
class PrincipalFeatureAccessTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    private const MODULE = 'demo.showcase';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    // ------------------------------------------------------- Access control

    public function test_the_feature_access_screen_boots_for_an_authorized_principal(): void
    {
        Tenant::factory()->create(['name' => 'Klinik Sejahtera']);

        $this->actingAs($this->principal())
            ->get('/principal/feature-access')
            ->assertOk()
            ->assertSee('Klinik Sejahtera');
    }

    public function test_a_principal_without_the_entitlement_permission_is_denied(): void
    {
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);

        $this->actingAs($finance)->get('/principal/feature-access')->assertForbidden();
        $this->assertFalse(FeatureAccess::canAccess());
    }

    public function test_a_denied_principal_cannot_grant_an_entitlement_even_if_reached(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installedModule();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));

        $page = new FeatureAccess;
        $page->tenantId = (string) $tenant->getKey();

        $this->assertThrows(
            fn () => $page->grant(self::MODULE),
            HttpException::class,
        );

        $this->assertDatabaseMissing('module_entitlements', [
            'module_key' => self::MODULE,
            'scope_key' => $tenant->getKey(),
        ]);
    }

    // ------------------------------------------------------------ Entitlements

    public function test_an_authorized_principal_can_grant_and_revoke_an_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installedModule();
        $actor = $this->principal();

        $this->actingAs($actor);

        $page = new FeatureAccess;
        $page->tenantId = (string) $tenant->getKey();
        $page->grant(self::MODULE);

        $entitlement = ModuleEntitlement::query()
            ->where('module_key', self::MODULE)
            ->where('scope', ModuleScope::TENANT)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(EntitlementStatus::GRANTED, $entitlement->status);
        $this->assertTrue($entitlement->isGranted());

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => self::MODULE,
            'action' => 'grant',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'actor_id' => $actor->getKey(),
        ]);

        $page->revoke(self::MODULE);

        $this->assertSame(EntitlementStatus::REVOKED, $entitlement->refresh()->status);
        $this->assertFalse($entitlement->isGranted());

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => self::MODULE,
            'action' => 'revoke',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'actor_id' => $actor->getKey(),
        ]);
    }

    // --------------------------------------------------------- Feature roles

    public function test_member_roles_are_synced_through_the_existing_rbac_roles(): void
    {
        $tenant = Tenant::factory()->create();
        $member = User::factory()->create();
        $membership = $this->giveMembership($member, $tenant);

        $this->actingAs($this->principal());

        $page = new FeatureAccess;
        $page->tenantId = (string) $tenant->getKey();
        $page->memberRoles = [
            (string) $membership->getKey() => [RoleKey::TENANT_OWNER, RoleKey::RECEPTIONIST],
        ];
        $page->saveRoles((string) $membership->getKey());

        $this->assertEqualsCanonicalizing(
            [RoleKey::TENANT_OWNER, RoleKey::RECEPTIONIST],
            $membership->refresh()->roles()->pluck('key')->all(),
        );
    }

    public function test_member_roles_cannot_be_saved_across_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $membership = $this->giveMembership(User::factory()->create(), $otherTenant);

        $this->actingAs($this->principal());

        $page = new FeatureAccess;
        $page->tenantId = (string) $tenant->getKey();
        $page->memberRoles = [
            (string) $membership->getKey() => [RoleKey::TENANT_OWNER],
        ];

        $this->assertThrows(
            fn () => $page->saveRoles((string) $membership->getKey()),
            HttpException::class,
        );

        $this->assertCount(0, $membership->refresh()->roles);
    }

    private function installedModule(string $key = self::MODULE): RegisteredModule
    {
        return RegisteredModule::factory()->forKey($key)->enabled()->create();
    }
}
