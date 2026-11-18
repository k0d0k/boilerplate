<?php

namespace Tests\Feature\Platform\Access;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ResolvesRoles;
use App\Platform\Access\Contracts\ValidatesTenantScope;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;
use App\Platform\Access\TenantScopeValidator;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1B-2A authorization foundation — deny by default.
 *
 * Covers the mandatory negative cases from docs/ROLE_PERMISSION_MATRIX.md for
 * central identity, membership, role and permission resolution. Cross-tenant
 * *database* isolation is explicitly out of scope (Phase 2); this suite only
 * proves the central authorization layer never grants more than intended.
 */
class AuthorizationFoundationTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    private ChecksPermissions $permissions;

    private ResolvesRoles $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->permissions = app(ChecksPermissions::class);
        $this->roles = app(ResolvesRoles::class);
    }

    public function test_a_user_with_multiple_memberships_resolves_roles_per_tenant(): void
    {
        $user = User::factory()->create();
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $this->giveMembership($user, $tenantA, [RoleKey::REVIEWER]);
        $this->giveMembership($user, $tenantB, [RoleKey::LBA_OPERATOR]);

        $this->assertSame([RoleKey::REVIEWER], $this->roles->tenantRoleKeys($user, $tenantA->getKey()));
        $this->assertSame([RoleKey::LBA_OPERATOR], $this->roles->tenantRoleKeys($user, $tenantB->getKey()));
        $this->assertCount(2, $this->roles->activeTenantIds($user));
    }

    public function test_a_role_in_tenant_a_never_authorizes_tenant_b(): void
    {
        $user = User::factory()->create();
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $this->giveMembership($user, $tenantA, [RoleKey::REVIEWER]);
        $this->giveMembership($user, $tenantB, [RoleKey::LBA_OPERATOR]);

        $this->assertTrue($this->permissions->allows(
            $user,
            PermissionKey::REPORT_APPROVE,
            AccessContext::forTenant($tenantA->getKey()),
        ));

        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::REPORT_APPROVE,
            AccessContext::forTenant($tenantB->getKey()),
        ));

        $memberships = app(AuthorizesTenantMembership::class);
        $this->assertTrue($memberships->can($user, $tenantA->getKey(), PermissionKey::REPORT_APPROVE));
        $this->assertFalse($memberships->can($user, $tenantB->getKey(), PermissionKey::REPORT_APPROVE));
    }

    public function test_revoked_membership_is_denied(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::REVIEWER], TenantMembership::STATUS_REVOKED);

        $this->assertSame([], $this->roles->tenantRoleKeys($user, $tenant->getKey()));
        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::REPORT_APPROVE,
            AccessContext::forTenant($tenant->getKey()),
        ));
        $this->assertFalse(app(AuthorizesTenantMembership::class)->isMemberOf($user, $tenant->getKey()));
    }

    public function test_suspended_membership_is_denied(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER], TenantMembership::STATUS_SUSPENDED);

        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
    }

    public function test_suspended_tenant_denies_even_an_active_membership(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->suspended()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->assertSame([], $this->roles->activeTenantIds($user));
        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
    }

    public function test_suspended_identity_is_denied_everywhere(): void
    {
        $user = User::factory()->suspended()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->grantPrincipalRole($user, RoleKey::PRINCIPAL_SUPER_ADMIN);

        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
        $this->assertFalse($this->permissions->allows($user, PermissionKey::PLATFORM_BRAND_MANAGE));
        $this->assertFalse(app(AuthorizesPrincipal::class)->isPrincipal($user));
    }

    public function test_unknown_role_assignment_is_denied(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant); // no roles attached

        $this->assertSame([], $this->roles->tenantRoleKeys($user, $tenant->getKey()));
        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
    }

    public function test_unknown_permission_key_is_denied(): void
    {
        $user = User::factory()->create();
        $this->grantPrincipalRole($user, RoleKey::PRINCIPAL_SUPER_ADMIN);

        $this->assertFalse($this->permissions->allows($user, 'made.up.permission'));
    }

    public function test_permission_is_denied_without_an_active_membership(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
        $this->assertFalse($this->permissions->allows($user, PermissionKey::EXAM_READ));
    }

    public function test_ambiguous_multi_membership_without_context_fails_closed(): void
    {
        $user = User::factory()->create();
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->giveMembership($user, $tenantA, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($user, $tenantB, [RoleKey::TENANT_OWNER]);

        // No verified selected context -> never pick a tenant arbitrarily.
        $this->assertFalse($this->permissions->allows($user, PermissionKey::EXAM_READ));

        // With an explicit, verified context the decision is deterministic.
        $this->assertTrue($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenantA->getKey()),
        ));
    }

    public function test_single_membership_resolves_without_an_explicit_context(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->assertTrue($this->permissions->allows($user, PermissionKey::EXAM_READ));
    }

    public function test_principal_cannot_obtain_clinical_permissions(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->grantPrincipalRole($user, RoleKey::PRINCIPAL_SUPER_ADMIN);

        // No membership: clinical access denied even with an explicit context.
        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
        $this->assertFalse($this->permissions->allows($user, PermissionKey::PATIENT_PROFILE_READ));

        // Direct platform authorizer also refuses clinical permissions.
        $this->assertFalse(app(AuthorizesPrincipal::class)->can($user, PermissionKey::EXAM_READ));
    }

    public function test_principal_role_grants_only_platform_permissions(): void
    {
        $user = User::factory()->create();
        $this->grantPrincipalRole($user, RoleKey::PRINCIPAL_SUPER_ADMIN);

        $principal = app(AuthorizesPrincipal::class);

        $this->assertTrue($principal->isPrincipal($user));
        $this->assertTrue($principal->can($user, PermissionKey::PLATFORM_BRAND_MANAGE));
        $this->assertTrue($this->permissions->allows($user, PermissionKey::PLATFORM_TENANT_MANAGE));
    }

    public function test_platform_permission_is_denied_for_a_tenant_only_identity(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->assertFalse($this->permissions->allows($user, PermissionKey::PLATFORM_BRAND_MANAGE));
        $this->assertFalse(app(AuthorizesPrincipal::class)->isPrincipal($user));
    }

    public function test_operator_cannot_self_approve_its_own_exam(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::LBA_OPERATOR]);

        $this->assertTrue($this->permissions->allows(
            $user,
            PermissionKey::EXAM_CREATE,
            AccessContext::forTenant($tenant->getKey()),
        ));
        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::REPORT_APPROVE,
            AccessContext::forTenant($tenant->getKey()),
        ));
    }

    public function test_patient_role_receives_only_self_service_permissions(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        $context = AccessContext::forTenant($tenant->getKey());

        $this->assertTrue($this->permissions->allows($user, PermissionKey::REPORT_READ_PUBLISHED, $context));
        $this->assertTrue($this->permissions->allows($user, PermissionKey::BOOKING_OWN, $context));
        $this->assertFalse($this->permissions->allows($user, PermissionKey::EXAM_READ, $context));
        $this->assertFalse($this->permissions->allows($user, PermissionKey::REPORT_APPROVE, $context));
    }

    public function test_scope_validator_rejects_foreign_and_malformed_tenant_ids(): void
    {
        $validator = app(ValidatesTenantScope::class);
        $user = User::factory()->create();
        $own = Tenant::factory()->create();
        $foreign = Tenant::factory()->create();
        $this->giveMembership($user, $own, [RoleKey::TENANT_OWNER]);

        $this->assertTrue($validator->isValidFor($user, $own->getKey()));
        $this->assertFalse($validator->isValidFor($user, $foreign->getKey()));
        $this->assertFalse($validator->isValidFor($user, 'not-a-ulid'));
        $this->assertFalse($validator->isValidFor($user, ''));
        $this->assertFalse($validator->isValidFor($user, null));
    }

    public function test_service_provider_resolves_the_scope_validator_from_the_contract(): void
    {
        $this->assertInstanceOf(TenantScopeValidator::class, app(ValidatesTenantScope::class));
    }

    public function test_duplicate_membership_for_the_same_user_and_tenant_is_rejected(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->expectException(QueryException::class);

        TenantMembership::factory()->forUser($user)->forTenant($tenant)->create();
    }
}
