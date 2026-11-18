<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * Phase D — TailAdmin parity for the Principal tenant governance surface.
 *
 * The Filament "Edit tenant" relation managers (memberships, subscriptions,
 * brand links) are replaced by a TailAdmin page so a principal never leaves
 * TailAdmin. These tests prove the page is reachable only by the same policy
 * the Filament surface used, that every write reuses the existing RBAC /
 * domain services, and that a child record belonging to another partner can
 * never be mutated through this route.
 */
class PrincipalTenantRelationsTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_the_tenant_form_links_to_the_tailadmin_relations_surface_instead_of_filament(): void
    {
        $tenant = Tenant::factory()->create();

        $html = $this->actingAs($this->principal())
            ->get("/principal/master-tenants/{$tenant->id}/edit")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('principal.tailadmin.tenants.relations', $tenant), $html);
        $this->assertStringNotContainsString(
            route('filament.principal.resources.tenants.edit', $tenant),
            $html,
        );
    }

    public function test_the_relations_surface_and_its_writes_require_tenant_management(): void
    {
        $tenant = Tenant::factory()->create();
        $membership = $this->giveMembership(User::factory()->create(), $tenant, [RoleKey::TENANT_ADMIN]);
        $base = "/principal/master-tenants/{$tenant->id}/relations";

        $this->get($base)->assertRedirect();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));
        $this->get($base)->assertForbidden();
        $this->patch("{$base}/members/{$membership->id}/roles", ['role_keys' => [RoleKey::TENANT_OWNER]])
            ->assertForbidden();

        $this->actingAs(User::factory()->role(RoleKey::TENANT_OWNER)->create());
        $this->get($base)->assertForbidden();
        $this->patch("{$base}/members/{$membership->id}/status", ['status' => TenantMembership::STATUS_SUSPENDED])
            ->assertForbidden();
    }

    public function test_the_relations_surface_lists_members_subscriptions_and_brands(): void
    {
        $tenant = Tenant::factory()->create();
        $member = User::factory()->create(['name' => 'Rina Wijaya', 'email' => 'rina@clinic.test']);
        $this->giveMembership($member, $tenant, [RoleKey::TENANT_ADMIN]);
        $brand = Brand::factory()->create(['name' => 'NuBiom']);
        $tenant->brands()->attach($brand, ['is_primary' => true]);

        $this->actingAs($this->principal())
            ->get("/principal/master-tenants/{$tenant->id}/relations")
            ->assertOk()
            ->assertSee('Rina Wijaya')
            ->assertSee('NuBiom')
            ->assertSee(__('lba.tenants.memberships'));
    }

    public function test_a_principal_can_assign_an_owner_and_invite_a_staff_member(): void
    {
        $tenant = Tenant::factory()->create();
        $candidate = User::factory()->create(['email' => 'owner@clinic.test']);
        $base = "/principal/master-tenants/{$tenant->id}/relations";

        $this->actingAs($this->principal());

        $this->post("{$base}/members/owner", ['user_id' => $candidate->id])->assertRedirect();

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $candidate->id)
            ->sole();

        $this->assertSame(TenantMembership::STATUS_ACTIVE, $membership->status);
        $this->assertTrue($membership->roles()->where('key', RoleKey::TENANT_OWNER)->exists());

        $this->post("{$base}/members/invite", [
            'email' => 'new.staff@clinic.test',
            'name' => 'New Staff',
            'role_keys' => [RoleKey::RECEPTIONIST],
        ])->assertRedirect();

        $invited = User::query()->where('email', 'new.staff@clinic.test')->sole();
        $invitedMembership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $invited->id)
            ->sole();

        $this->assertSame(TenantMembership::STATUS_INVITED, $invitedMembership->status);
        $this->assertTrue($invitedMembership->roles()->where('key', RoleKey::RECEPTIONIST)->exists());
    }

    public function test_a_principal_can_change_member_roles_and_status(): void
    {
        $tenant = Tenant::factory()->create();
        $member = User::factory()->create();
        $membership = $this->giveMembership($member, $tenant, [RoleKey::TENANT_ADMIN]);
        $base = "/principal/master-tenants/{$tenant->id}/relations";

        $this->actingAs($this->principal());

        $this->patch("{$base}/members/{$membership->id}/roles", [
            'role_keys' => [RoleKey::CONSULTANT],
        ])->assertRedirect();

        $membership->refresh();
        $this->assertTrue($membership->roles()->where('key', RoleKey::CONSULTANT)->exists());
        $this->assertFalse($membership->roles()->where('key', RoleKey::TENANT_ADMIN)->exists());

        $this->patch("{$base}/members/{$membership->id}/status", [
            'status' => TenantMembership::STATUS_SUSPENDED,
        ])->assertRedirect();
        $this->assertSame(TenantMembership::STATUS_SUSPENDED, $membership->refresh()->status);

        $this->patch("{$base}/members/{$membership->id}/status", [
            'status' => TenantMembership::STATUS_REVOKED,
        ])->assertRedirect();
        $membership->refresh();
        $this->assertSame(TenantMembership::STATUS_REVOKED, $membership->status);
        $this->assertNotNull($membership->revoked_at);
    }

    public function test_memberships_belonging_to_another_tenant_are_not_reachable(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $foreign = $this->giveMembership(User::factory()->create(), $otherTenant, [RoleKey::TENANT_ADMIN]);

        $this->actingAs($this->principal())
            ->patch("/principal/master-tenants/{$tenant->id}/relations/members/{$foreign->id}/status", [
                'status' => TenantMembership::STATUS_SUSPENDED,
            ])
            ->assertNotFound();
    }

    public function test_a_principal_can_create_activate_and_cancel_a_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = SubscriptionPlan::query()->create([
            'code' => 'plan-'.uniqid(),
            'name' => 'Klinik Plan',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => '250000.00',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 0,
            'module_keys' => [],
        ]);
        $base = "/principal/master-tenants/{$tenant->id}/relations";

        $this->actingAs($this->principal());

        $this->post("{$base}/subscriptions", ['plan_id' => $plan->id, 'seats' => 3])->assertRedirect();

        $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(3, $subscription->seats);

        $this->patch("{$base}/subscriptions/{$subscription->id}/cancel")->assertRedirect();
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->refresh()->status);

        $this->patch("{$base}/subscriptions/{$subscription->id}/activate")->assertRedirect();
        $this->assertTrue($subscription->refresh()->isActive());
    }

    public function test_brand_links_can_be_attached_prioritised_and_detached(): void
    {
        $tenant = Tenant::factory()->create();
        $previous = Brand::factory()->create();
        $brand = Brand::factory()->create();
        $foreign = Brand::factory()->create();
        $tenant->brands()->attach($previous, ['is_primary' => true]);
        $base = "/principal/master-tenants/{$tenant->id}/relations";

        $this->actingAs($this->principal());

        $this->post("{$base}/brands", ['brand_id' => $brand->id, 'is_primary' => '1'])->assertRedirect();
        $this->assertDatabaseHas('tenant_brands', [
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('tenant_brands', [
            'tenant_id' => $tenant->id,
            'brand_id' => $previous->id,
            'is_primary' => false,
        ]);

        $this->patch("{$base}/brands/{$previous->id}/primary")->assertRedirect();
        $this->assertDatabaseHas('tenant_brands', [
            'tenant_id' => $tenant->id,
            'brand_id' => $previous->id,
            'is_primary' => true,
        ]);

        $this->patch("{$base}/brands/{$foreign->id}/primary")->assertNotFound();

        $this->delete("{$base}/brands/{$brand->id}")->assertRedirect();
        $this->assertDatabaseMissing('tenant_brands', [
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
        ]);
    }
}
