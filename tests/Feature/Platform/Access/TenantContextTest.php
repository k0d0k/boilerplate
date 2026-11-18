<?php

namespace Tests\Feature\Platform\Access;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\RoleKey;
use App\Platform\Access\TenantContextManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1B-2B — secure tenant context resolution (STEP 2/STEP 6).
 *
 * The context is resolved server-side from the central membership tables. These
 * tests pin the security guarantees: only active memberships of active tenants
 * are eligible; a stored selection is revalidated on every read; a single
 * eligible membership may be auto-selected; several require an explicit choice;
 * none means no context; and a tenant id supplied by the caller is never
 * trusted without a matching membership.
 */
class TenantContextTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function context(): ManagesTenantContext
    {
        return app(ManagesTenantContext::class);
    }

    private function clinicUser(): User
    {
        return User::factory()->role(RoleKey::TENANT_OWNER)->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution — single, multiple, none
    |--------------------------------------------------------------------------
    */

    public function test_a_single_active_membership_is_auto_selected_after_verification(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->assertTrue($this->context()->hasEligibleMembership($user));
        $this->assertSame($tenant->getKey(), $this->context()->currentTenantId($user));
        $this->assertTrue($this->context()->hasVerifiedContext($user));
        $this->assertFalse($this->context()->requiresExplicitSelection($user));
        $this->assertTrue($this->context()->matchesCurrent($user, $tenant->getKey()));
    }

    public function test_a_single_membership_auto_selection_is_persisted_in_the_session(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant);

        $this->context()->currentTenantId($user);

        $this->assertSame(
            $tenant->getKey(),
            app('session')->driver()->get(TenantContextManager::SESSION_KEY),
        );
    }

    public function test_an_identity_without_any_membership_has_no_context(): void
    {
        $user = $this->clinicUser();

        $this->assertFalse($this->context()->hasEligibleMembership($user));
        $this->assertNull($this->context()->currentTenantId($user));
        $this->assertNull($this->context()->currentTenant($user));
        $this->assertFalse($this->context()->hasVerifiedContext($user));
        $this->assertSame([], $this->context()->eligibleOptions($user));
    }

    public function test_multiple_memberships_require_an_explicit_selection(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create());
        $this->giveMembership($user, Tenant::factory()->create());

        $this->assertTrue($this->context()->hasMultipleEligibleMemberships($user));
        // Never chosen arbitrarily when a choice is outstanding.
        $this->assertNull($this->context()->currentTenantId($user));
        $this->assertTrue($this->context()->requiresExplicitSelection($user));
        $this->assertCount(2, $this->context()->eligibleOptions($user));
    }

    public function test_an_explicit_selection_is_honoured_and_can_be_switched(): void
    {
        $user = $this->clinicUser();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $this->giveMembership($user, $a);
        $this->giveMembership($user, $b);

        $this->assertTrue($this->context()->select($user, $a->getKey()));
        $this->assertSame($a->getKey(), $this->context()->currentTenantId($user));

        $this->assertTrue($this->context()->select($user, $b->getKey()));
        $this->assertSame($b->getKey(), $this->context()->currentTenantId($user));
    }

    /*
    |--------------------------------------------------------------------------
    | Eligibility — lifecycle and tenant/identity status
    |--------------------------------------------------------------------------
    */

    #[DataProvider('ineligibleMembershipStatuses')]
    public function test_only_an_active_membership_is_eligible(string $status): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [], $status);

        $this->assertFalse($this->context()->hasEligibleMembership($user));
        $this->assertNull($this->context()->currentTenantId($user));

        // And it can never be selected through the switcher either.
        $this->assertFalse($this->context()->select($user, $tenant->getKey()));
    }

    /** @return array<string, array{0: string}> */
    public static function ineligibleMembershipStatuses(): array
    {
        return [
            'invited' => [TenantMembership::STATUS_INVITED],
            'suspended' => [TenantMembership::STATUS_SUSPENDED],
            'revoked' => [TenantMembership::STATUS_REVOKED],
        ];
    }

    public function test_a_membership_of_a_suspended_tenant_is_not_eligible(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->suspended()->create();
        $this->giveMembership($user, $tenant);

        $this->assertFalse($this->context()->hasEligibleMembership($user));
        $this->assertNull($this->context()->currentTenantId($user));
        $this->assertFalse($this->context()->select($user, $tenant->getKey()));
    }

    public function test_a_suspended_identity_has_no_context_even_with_active_memberships(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->suspended()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant);

        $this->assertFalse($this->context()->hasEligibleMembership($user));
        $this->assertNull($this->context()->currentTenantId($user));
        $this->assertFalse($this->context()->select($user, $tenant->getKey()));
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — the caller's tenant id is never trusted
    |--------------------------------------------------------------------------
    */

    public function test_a_foreign_tenant_id_cannot_be_selected(): void
    {
        $user = $this->clinicUser();
        $own = Tenant::factory()->create();
        $foreign = Tenant::factory()->create();
        $this->giveMembership($user, $own);

        $this->assertFalse($this->context()->select($user, $foreign->getKey()));

        // The verified context stays on the identity's own membership.
        $this->assertSame($own->getKey(), $this->context()->currentTenantId($user));
        $this->assertFalse($this->context()->matchesCurrent($user, $foreign->getKey()));
    }

    #[DataProvider('untrustworthyTenantIds')]
    public function test_malformed_or_unknown_tenant_ids_are_rejected(?string $tenantId): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create());

        $this->assertFalse($this->context()->select($user, $tenantId));
    }

    /** @return array<string, array{0: ?string}> */
    public static function untrustworthyTenantIds(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'not a ulid' => ['not-a-ulid'],
            'numeric' => ['1234567890'],
            'uuid shaped' => ['00000000-0000-0000-0000-000000000000'],
            'well formed but unknown' => [(string) Str::ulid()],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Revalidation — stale selections can never linger
    |--------------------------------------------------------------------------
    */

    public function test_a_stored_selection_falls_back_to_the_remaining_membership_once_its_own_is_revoked(): void
    {
        $user = $this->clinicUser();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $membershipA = $this->giveMembership($user, $a);
        $this->giveMembership($user, $b);

        $this->assertTrue($this->context()->select($user, $a->getKey()));
        $this->assertSame($a->getKey(), $this->context()->currentTenantId($user));

        $membershipA->update([
            'status' => TenantMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        // Revalidated on every read: A is dropped and B becomes the sole
        // eligible membership (auto-selected, still server-verified).
        $this->assertSame($b->getKey(), $this->context()->currentTenantId($user));
        $this->assertFalse($this->context()->matchesCurrent($user, $a->getKey()));
    }

    public function test_a_stored_selection_is_dropped_when_its_tenant_is_suspended(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant);

        $this->assertSame($tenant->getKey(), $this->context()->currentTenantId($user));

        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->assertNull($this->context()->currentTenantId($user));
        $this->assertFalse($this->context()->hasVerifiedContext($user));
    }

    public function test_clear_forgets_the_stored_selection(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant);

        $this->context()->currentTenantId($user); // auto-select
        $this->context()->clear();

        $this->assertNull(app('session')->driver()->get(TenantContextManager::SESSION_KEY));
    }

    public function test_a_stale_action_for_a_previously_selected_tenant_is_rejected_after_a_switch(): void
    {
        $user = $this->clinicUser();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $this->giveMembership($user, $a);
        $this->giveMembership($user, $b);

        $this->assertTrue($this->context()->select($user, $a->getKey()));

        // Another tab switches the shared session to B...
        $this->assertTrue($this->context()->select($user, $b->getKey()));

        // ...so a stale request still addressed to A is not the current context.
        $this->assertFalse($this->context()->matchesCurrent($user, $a->getKey()));
        $this->assertTrue($this->context()->matchesCurrent($user, $b->getKey()));
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation options
    |--------------------------------------------------------------------------
    */

    public function test_eligible_options_carry_the_tenant_name_and_primary_brand_label(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->create(['name' => 'Mitra Sehat']);
        $primary = Brand::factory()->create(['name' => 'Klinik Sehat']);
        $secondary = Brand::factory()->create(['name' => 'Apotek Sehat']);
        $tenant->brands()->attach($primary, ['is_primary' => true]);
        $tenant->brands()->attach($secondary, ['is_primary' => false]);

        $this->giveMembership($user, $tenant);

        $options = $this->context()->eligibleOptions($user);

        $this->assertCount(1, $options);
        $this->assertSame($tenant->getKey(), $options[0]->id);
        $this->assertSame('Mitra Sehat', $options[0]->name);
        $this->assertSame('Klinik Sehat', $options[0]->brandLabel);
    }
}
