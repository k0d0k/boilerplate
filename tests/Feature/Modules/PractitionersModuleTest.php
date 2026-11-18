<?php

namespace Tests\Feature\Modules;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Models\PractitionerAuditLog;
use Modules\Practitioners\Models\PractitionerBranchAuthorization;
use Modules\Practitioners\Models\PractitionerCredential;
use Modules\Practitioners\Services\PractitionerRegistry;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS7.A — Practitioners module (focused essentials).
 *
 * Exercises the registry lifecycle, explicit (never automatic) verification,
 * separation of duties, the booking gate, credential masking, branch
 * authorization, tenant isolation, mass-assignment protection, RBAC negatives,
 * FeatureGate activation and page rendering. Only synthetic identities are used.
 */
class PractitionersModuleTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'practitioners';

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

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    /**
     * A clinical member: an active consultant of the tenant. Being listed here
     * only makes the user registrable — it never verifies qualifications.
     *
     * @param  list<string>  $roleKeys
     */
    private function clinical(Tenant $tenant, array $roleKeys = [RoleKey::CONSULTANT]): User
    {
        $user = User::factory()->role($roleKeys[0])->create();
        $this->giveMembership($user, $tenant, $roleKeys);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function activate(Tenant $tenant): void
    {
        RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    private function registry(): PractitionerRegistry
    {
        return app(PractitionerRegistry::class);
    }

    // ------------------------------------------------------------ Registry

    public function test_registering_creates_a_pending_practitioner_and_audits_it(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Synthetic One',
            'title' => 'Dr.',
            'specialities' => ['LBA'],
        ]);

        $this->assertTrue($practitioner->isPending());
        $this->assertSame('Dr Synthetic One', $practitioner->display_name);
        $this->assertSame((string) $clinical->getKey(), $practitioner->user_id);

        $this->assertDatabaseHas('practitioners', ['id' => $practitioner->getKey(), 'status' => Practitioner::STATUS_PENDING], 'tenant');
        $this->assertDatabaseHas('practitioner_audit_logs', [
            'practitioner_id' => $practitioner->getKey(),
            'action' => PractitionerAuditLog::ACTION_REGISTERED,
        ], 'tenant');
    }

    public function test_registration_is_refused_for_a_non_clinical_member(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $this->expectException(PractitionerException::class);

        $this->registry()->register($owner, (string) $receptionist->getKey(), [
            'display_name' => 'Not A Clinician',
        ]);
    }

    public function test_the_same_user_cannot_be_registered_twice(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'First']);

        $this->expectException(PractitionerException::class);

        $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Second']);
    }

    // -------------------------------------------------------- Verification

    public function test_verification_is_explicit_audited_and_grants_bookability(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Dr Verify']);

        // Never auto-verified on registration.
        $this->assertFalse($this->registry()->isEligibleForBooking($practitioner));

        $verified = $this->registry()->verify($owner, $practitioner, now()->addYear());

        $this->assertTrue($verified->isVerified());
        $this->assertNotNull($verified->verified_at);
        $this->assertSame((string) $owner->getKey(), $verified->verified_by);
        $this->assertTrue($this->registry()->isEligibleForBooking($verified));

        $this->assertDatabaseHas('practitioner_audit_logs', [
            'practitioner_id' => $practitioner->getKey(),
            'action' => PractitionerAuditLog::ACTION_VERIFIED,
            'actor_id' => (string) $owner->getKey(),
        ], 'tenant');
    }

    public function test_a_practitioner_cannot_verify_their_own_record(): void
    {
        $tenant = Tenant::factory()->create();
        // The actor is BOTH a clinical member (registrable) and a tenant admin
        // (holds the verify permission), so only the separation-of-duties guard
        // can refuse the self-verification.
        $actor = $this->clinical($tenant, [RoleKey::CONSULTANT, RoleKey::TENANT_ADMIN]);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($actor, (string) $actor->getKey(), ['display_name' => 'Self']);

        $this->expectException(PractitionerException::class);

        $this->registry()->verify($actor, $practitioner);
    }

    public function test_a_role_without_verify_permission_cannot_verify(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Dr Guarded']);

        $this->expectException(PractitionerException::class);

        $this->registry()->verify($receptionist, $practitioner);
    }

    // ------------------------------------------------------------ Booking gate

    public function test_suspending_blocks_booking_but_keeps_history(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Dr Suspend']);
        $this->registry()->verify($owner, $practitioner);

        $this->registry()->suspend($owner, $practitioner);

        $this->assertTrue($practitioner->fresh()->isSuspended());
        $this->assertFalse($this->registry()->isEligibleForBooking($practitioner->fresh()));

        // Non-destructive: the record (and its history) remains.
        $this->assertDatabaseHas('practitioners', ['id' => $practitioner->getKey()], 'tenant');
        $this->assertDatabaseHas('practitioner_audit_logs', [
            'practitioner_id' => $practitioner->getKey(),
            'action' => PractitionerAuditLog::ACTION_SUSPENDED,
        ], 'tenant');
    }

    public function test_an_expired_practitioner_is_not_bookable(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Dr Lapsed']);
        $verified = $this->registry()->verify($owner, $practitioner, now()->subDay());

        $this->assertTrue($verified->isVerified());
        $this->assertTrue($verified->isExpired());
        $this->assertFalse($this->registry()->isEligibleForBooking($verified));
    }

    public function test_a_de_membered_practitioner_is_not_bookable(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Dr Removed']);
        $verified = $this->registry()->verify($owner, $practitioner);
        $this->assertTrue($this->registry()->isEligibleForBooking($verified));

        TenantMembership::query()
            ->where('user_id', $clinical->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->update(['status' => TenantMembership::STATUS_REVOKED]);

        $this->assertFalse($this->registry()->isEligibleForBooking($verified->fresh()));
    }

    // ------------------------------------------------------- Tenant isolation

    public function test_practitioners_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $clinicalA = $this->clinical($tenantA);
        $this->provisionTenantDatabase($tenantA);
        $this->bindTenant($tenantA);

        $this->registry()->register($ownerA, (string) $clinicalA->getKey(), ['display_name' => 'Tenant A Doctor']);

        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->tenantDatabases()->configure((string) $tenantB->getKey());

        $this->assertSame(0, Practitioner::query()->count());
    }

    // ----------------------------------------------------------- Credentials

    public function test_credentials_start_pending_and_the_reference_is_masked(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Dr Cred']);

        $credential = $this->registry()->addCredential($owner, $practitioner, [
            'credential_type' => PractitionerCredential::TYPE_LICENSE,
            'reference_number' => 'MOH-123456',
        ]);

        $this->assertTrue($credential->isPending());
        $this->assertSame('••••••3456', $credential->maskedReference());
        $this->assertStringNotContainsString('MOH', $credential->maskedReference());
    }

    public function test_credential_verification_is_audited(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Dr Cred Verify']);
        $credential = $this->registry()->addCredential($owner, $practitioner, [
            'credential_type' => PractitionerCredential::TYPE_REGISTRATION,
        ]);

        $verified = $this->registry()->verifyCredential($owner, $credential, now()->addYear());

        $this->assertTrue($verified->isVerified());
        $this->assertDatabaseHas('practitioner_audit_logs', [
            'practitioner_id' => $practitioner->getKey(),
            'action' => PractitionerAuditLog::ACTION_CREDENTIAL_VERIFIED,
        ], 'tenant');
    }

    // ------------------------------------------------------ Branch authorization

    public function test_branch_authorization_can_be_granted_and_revoked(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);
        $practitioner = $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Dr Branch']);

        $authorization = $this->registry()->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        $this->assertTrue($authorization->isActive());
        $this->assertTrue($this->registry()->isAuthorizedForBranch($practitioner, (string) $branch->getKey()));

        $this->assertTrue($this->registry()->revokeBranch($owner, $practitioner, (string) $branch->getKey()));

        $this->assertFalse($this->registry()->isAuthorizedForBranch($practitioner, (string) $branch->getKey()));
        $this->assertDatabaseHas('practitioner_branch_authorizations', [
            'practitioner_id' => $practitioner->getKey(),
            'branch_id' => $branch->getKey(),
            'status' => PractitionerBranchAuthorization::STATUS_REVOKED,
        ], 'tenant');
    }

    // ------------------------------------------------- Mass-assignment protection

    public function test_identity_and_status_are_not_mass_assignable(): void
    {
        $practitioner = new Practitioner;
        $practitioner->fill([
            'user_id' => 'forged-user',
            'status' => Practitioner::STATUS_VERIFIED,
            'verified_by' => 'forged-actor',
            'display_name' => 'Legit Name',
        ]);

        $this->assertNull($practitioner->user_id);
        $this->assertNull($practitioner->status);
        $this->assertNull($practitioner->verified_by);
        $this->assertSame('Legit Name', $practitioner->display_name);

        $credential = new PractitionerCredential;
        $credential->fill([
            'credential_type' => PractitionerCredential::TYPE_LICENSE,
            'status' => PractitionerCredential::STATUS_VERIFIED,
            'verified_by' => 'forged-actor',
        ]);

        $this->assertNull($credential->status);
        $this->assertNull($credential->verified_by);
    }

    // ------------------------------------------------------- RBAC / access rules

    public function test_the_practitioners_resource_requires_an_active_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);

        $this->actingAs($owner)->get('/clinic/practitioners')->assertForbidden();
    }

    public function test_a_role_without_practitioner_permissions_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $finance = $this->member($tenant, RoleKey::TENANT_FINANCE);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->actingAs($finance)->get('/clinic/practitioners')->assertForbidden();
    }

    public function test_the_practitioners_list_and_create_pages_render(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        $this->registry()->register($owner, (string) $clinical->getKey(), ['display_name' => 'Synthetic Rendered Doctor']);

        $this->actingAs($owner)->get('/clinic/practitioners')->assertOk()->assertSee('Synthetic Rendered Doctor');
        $this->actingAs($owner)->get('/clinic/practitioners/create')->assertOk();
    }

    public function test_the_principal_panel_never_reads_clinical_practitioner_data(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $principal = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();
        $this->grantPrincipalRole($principal, RoleKey::PRINCIPAL_SUPER_ADMIN);

        // The Principal panel has no practitioners route at all.
        $this->actingAs($principal)->get('/clinic/practitioners')->assertForbidden();
    }

    /**
     * A guard against silent RBAC drift: only the clinic administrator roles may
     * verify credentials; every clinical role must NOT be able to.
     */
    public function test_only_clinic_administrators_hold_the_verify_permission(): void
    {
        $catalog = app(RbacCatalog::class);
        $verify = PermissionKey::PRACTITIONER_CREDENTIAL_VERIFY;

        $this->assertContains($verify, $catalog::permissionsFor(RoleKey::TENANT_OWNER));
        $this->assertContains($verify, $catalog::permissionsFor(RoleKey::TENANT_ADMIN));

        foreach ([RoleKey::CONSULTANT, RoleKey::LBA_OPERATOR, RoleKey::REVIEWER, RoleKey::RECEPTIONIST, RoleKey::SCHEDULER] as $role) {
            $this->assertNotContains($verify, $catalog::permissionsFor($role), "Role [{$role}] must not verify credentials.");
        }

        $this->assertNotEmpty(Role::query()->where('key', RoleKey::TENANT_OWNER)->get());
    }
}
