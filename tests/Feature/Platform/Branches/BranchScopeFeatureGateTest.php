<?php

namespace Tests\Feature\Platform\Branches;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\FeatureGate\Contracts\EvaluatesModuleAccess;
use App\Platform\FeatureGate\FeatureGateReason;
use App\Platform\FeatureGate\ModuleAccessRequest;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleManifest;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 2B — FeatureGate uses the VERIFIED branch context.
 *
 * A branch-scoped module is only resolved when the branch id in the context is
 * an active branch of the verified tenant the identity may access. A fabricated
 * id, a foreign tenant's branch, a suspended branch, or a grant issued for
 * another branch/tenant never authorizes access.
 */
class BranchScopeFeatureGateTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'demo.branch';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    private function gate(): EvaluatesModuleAccess
    {
        return $this->app->make(EvaluatesModuleAccess::class);
    }

    private function request(User $user, AccessContext $context, string $moduleKey = self::MODULE): ModuleAccessRequest
    {
        return new ModuleAccessRequest(
            moduleKey: $moduleKey,
            panelId: PanelAccessPolicy::PANEL_CLINIC,
            user: $user,
            context: $context,
        );
    }

    private function clinicUser(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    private function registerBranchModule(): void
    {
        $this->app->make(RegistersModules::class)->register(new ModuleManifest(
            key: self::MODULE,
            name: 'Branch Demo',
            version: '1.0.0',
            minPlatformVersion: '3.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
            scopeCompatibility: [ModuleScope::BRANCH],
        ));
    }

    private function installBranchModule(string $key, string $branchId, bool $granted = true): void
    {
        if (! RegisteredModule::query()->where('module_key', $key)->exists()) {
            RegisteredModule::factory()->forKey($key)->enabled()->create();
        }

        ModuleInstallation::factory()->forModule($key)->forScope(ModuleScope::BRANCH, $branchId)->enabled()->create();

        if ($granted) {
            ModuleEntitlement::factory()->forModule($key)->forScope(ModuleScope::BRANCH, $branchId)->granted()->create();
        }
    }

    public function test_a_branch_scoped_module_is_allowed_only_with_a_verified_branch_context(): void
    {
        $this->registerBranchModule();

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $this->installBranchModule(self::MODULE, $branch->getKey());

        // No branch id at all: the gate refuses to invent one.
        $this->assertSame(
            FeatureGateReason::NO_VERIFIED_SCOPE,
            $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())))->reason,
        );

        // A fabricated branch id is not authorization.
        $this->assertSame(
            FeatureGateReason::NO_VERIFIED_SCOPE,
            $this->gate()->evaluate(
                $this->request($user, AccessContext::forTenant($tenant->getKey(), (string) Str::ulid())),
            )->reason,
        );

        // The explicitly verified user -> tenant -> branch chain: allowed.
        $result = $this->gate()->evaluate(
            $this->request($user, AccessContext::forTenant($tenant->getKey(), $branch->getKey())),
        );

        $this->assertTrue($result->allowed);
        $this->assertSame(ModuleScope::BRANCH, $result->scope?->scope);
        $this->assertSame($branch->getKey(), $result->scope?->scopeKey);
    }

    public function test_a_branch_entitlement_never_authorizes_another_branch(): void
    {
        $this->registerBranchModule();

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);
        $this->provisionTenantDatabase($tenant);

        $entitled = $this->createBranch($tenant, ['name' => 'Alpha']);
        $other = $this->createBranch($tenant, ['name' => 'Beta']);

        // Both branches are installed; only Alpha is entitled.
        $this->installBranchModule(self::MODULE, $entitled->getKey());
        $this->installBranchModule(self::MODULE, $other->getKey(), granted: false);

        $this->assertTrue(
            $this->gate()->evaluate(
                $this->request($user, AccessContext::forTenant($tenant->getKey(), $entitled->getKey())),
            )->allowed,
        );

        // The sibling branch is verified and installed too, but no grant was
        // issued for it — the Alpha entitlement does not leak across branches.
        $this->assertSame(
            FeatureGateReason::NOT_ENTITLED,
            $this->gate()->evaluate(
                $this->request($user, AccessContext::forTenant($tenant->getKey(), $other->getKey())),
            )->reason,
        );
    }

    public function test_a_branch_entitlement_never_authorizes_another_tenant(): void
    {
        $this->registerBranchModule();

        $tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
        $userA = $this->clinicUser($tenantA);

        $this->provisionTenantDatabase($tenantA);
        $this->provisionTenantDatabase($tenantB);

        $branchA = $this->createBranch($tenantA);
        $branchB = $this->createBranch($tenantB);

        // A genuine grant exists for Tenant B's branch — it must not leak.
        $this->installBranchModule(self::MODULE, $branchB->getKey());

        $this->tenantDatabases()->configure($tenantA->getKey());

        $this->assertSame(
            FeatureGateReason::NO_VERIFIED_SCOPE,
            $this->gate()->evaluate(
                $this->request($userA, AccessContext::forTenant($tenantA->getKey(), $branchB->getKey())),
            )->reason,
        );

        // Even *claiming* Tenant B's branch in the context cannot leak the
        // grant: the identity is not a member of Tenant B, so the gate denies it
        // before any scope is considered.
        $this->assertSame(
            FeatureGateReason::UNAUTHORIZED,
            $this->gate()->evaluate(
                $this->request($userA, AccessContext::forTenant($tenantB->getKey(), $branchB->getKey())),
            )->reason,
        );

        // Sanity: the identity's own branch is unaffected.
        $this->assertNotNull($branchA->getKey());
    }

    public function test_a_suspended_branch_never_entitles_module_access(): void
    {
        $this->registerBranchModule();

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $this->installBranchModule(self::MODULE, $branch->getKey());

        $branch->forceFill(['status' => TenantBranch::STATUS_SUSPENDED])->save();

        $this->assertSame(
            FeatureGateReason::NO_VERIFIED_SCOPE,
            $this->gate()->evaluate(
                $this->request($user, AccessContext::forTenant($tenant->getKey(), $branch->getKey())),
            )->reason,
        );
    }
}
