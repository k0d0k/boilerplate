<?php

namespace Tests\Feature\Platform\Access;

use App\Models\Tenant;
use App\Platform\Access\RoleKey;
use App\Platform\Access\SupportTenantSession;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * WS9.E — bounded "open tenant" support context.
 *
 * The Super Admin keeps their own identity and only receives a single,
 * time-boxed, auditable read-only context over ONE active tenant. These tests
 * pin the security guarantees: permission is required to open, the context is
 * bound to the actor, it expires, it is revoked with the permission, cross-
 * tenant access is impossible, and every open/close is audited.
 */
class SupportTenantSessionTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function supportSession(): SupportTenantSession
    {
        return app(SupportTenantSession::class);
    }

    // --------------------------------------------------------------- Opening

    public function test_a_principal_with_permission_opens_a_bounded_context_for_an_active_tenant(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create();

        $this->assertTrue($this->supportSession()->open($actor, $tenant->getKey()));
        $this->assertTrue($this->supportSession()->isActive($actor));
        $this->assertSame($tenant->getKey(), $this->supportSession()->currentTenantId($actor));
        $this->assertNotNull($this->supportSession()->expiresAt($actor));

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => SupportTenantSession::MODULE_KEY,
            'action' => SupportTenantSession::ACTION_OPENED,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope_key' => $tenant->getKey(),
            'actor_id' => $actor->getKey(),
        ]);
    }

    public function test_opening_without_the_platform_permission_fails_closed_and_is_audited(): void
    {
        // brand_manager reaches the Principal panel but holds no
        // `platform.tenant.manage`.
        $actor = $this->principal(RoleKey::BRAND_MANAGER);
        $tenant = Tenant::factory()->create();

        $this->assertFalse($this->supportSession()->open($actor, $tenant->getKey()));
        $this->assertFalse($this->supportSession()->isActive($actor));

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => SupportTenantSession::ACTION_OPENED,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'permission_denied',
        ]);
    }

    public function test_a_suspended_tenant_cannot_be_opened(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->suspended()->create();

        $this->assertFalse($this->supportSession()->open($actor, $tenant->getKey()));
        $this->assertFalse($this->supportSession()->isActive($actor));

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => SupportTenantSession::ACTION_OPENED,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'tenant_not_active',
        ]);
    }

    #[DataProvider('unknownTenantIds')]
    public function test_unknown_tenant_ids_are_rejected(string $tenantId): void
    {
        $actor = $this->principal();

        $this->assertFalse($this->supportSession()->open($actor, $tenantId));
        $this->assertFalse($this->supportSession()->isActive($actor));
    }

    /** @return array<string, array{0: string}> */
    public static function unknownTenantIds(): array
    {
        return [
            'malformed' => ['not-a-ulid'],
            'well formed but unknown' => [(string) Str::ulid()],
        ];
    }

    // -------------------------------------------------------- Single tenant

    public function test_opening_another_tenant_replaces_the_previous_context(): void
    {
        $actor = $this->principal();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->assertTrue($this->supportSession()->open($actor, $a->getKey()));
        $this->assertSame($a->getKey(), $this->supportSession()->currentTenantId($actor));

        $this->assertTrue($this->supportSession()->open($actor, $b->getKey()));
        $this->assertSame($b->getKey(), $this->supportSession()->currentTenantId($actor));
    }

    // ------------------------------------------------------------- Expiry

    public function test_the_context_expires_after_its_ttl_and_is_forgotten(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create();

        $this->supportSession()->open($actor, $tenant->getKey(), ttlMinutes: 30);
        $this->assertTrue($this->supportSession()->isActive($actor));

        $this->travel(31)->minutes();

        $this->assertFalse($this->supportSession()->isActive($actor));
        $this->assertNull($this->supportSession()->currentTenantId($actor));
        $this->assertNull(app('session')->driver()->get(SupportTenantSession::SESSION_KEY));
    }

    // --------------------------------------------------- Permission revocation

    public function test_losing_the_platform_permission_ends_the_context(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create();

        $this->supportSession()->open($actor, $tenant->getKey());
        $this->assertTrue($this->supportSession()->isActive($actor));

        // Revoke the principal role assignment.
        $actor->principalRoleAssignments()->update(['status' => 'revoked']);

        $this->assertFalse($this->supportSession()->isActive($actor));
        $this->assertNull($this->supportSession()->currentTenantId($actor));
    }

    // ------------------------------------------------------- Actor binding

    public function test_another_identity_cannot_reuse_the_context(): void
    {
        $actor = $this->principal();
        $intruder = $this->principal();
        $tenant = Tenant::factory()->create();

        $this->supportSession()->open($actor, $tenant->getKey());

        $this->assertTrue($this->supportSession()->isActive($actor));
        $this->assertFalse($this->supportSession()->isActive($intruder));
        $this->assertNull($this->supportSession()->currentTenantId($intruder));
    }

    // --------------------------------------------------- Tenant lifecycle

    public function test_suspending_the_tenant_ends_the_context(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create();

        $this->supportSession()->open($actor, $tenant->getKey());
        $this->assertTrue($this->supportSession()->isActive($actor));

        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->assertFalse($this->supportSession()->isActive($actor));
    }

    // ------------------------------------------------------------- Closing

    public function test_closing_forgets_the_context_and_is_audited(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create();

        $this->supportSession()->open($actor, $tenant->getKey());
        $this->supportSession()->close($actor);

        $this->assertFalse($this->supportSession()->isActive($actor));

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => SupportTenantSession::ACTION_CLOSED,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'actor_id' => $actor->getKey(),
        ]);
    }

    public function test_closing_without_a_context_is_a_no_op(): void
    {
        $actor = $this->principal();

        $this->supportSession()->close($actor);
        $this->supportSession()->close($actor);

        $this->assertFalse($this->supportSession()->isActive($actor));
        $this->assertDatabaseCount('module_audit_logs', 0);
    }

    public function test_a_suspended_identity_has_no_context(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create();

        $this->supportSession()->open($actor, $tenant->getKey());

        $actor->update(['is_active' => false]);

        $this->assertFalse($this->supportSession()->isActive($actor->refresh()));
    }
}
