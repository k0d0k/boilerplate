<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Pages\PlatformSettings;
use App\Models\PaymentGateway;
use App\Models\PrincipalRoleAssignment;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\PrincipalAccessAdministration;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * WS4.5 — Principal platform settings & RBAC administration.
 *
 * The settings surface reports the platform's REAL configuration (locales,
 * payment-provider posture — never credentials) and administers PLATFORM
 * governance roles over the existing RBAC tables through
 * {@see PrincipalAccessAdministration}. These tests prove
 * it fails closed without `platform.rbac.manage`, only grants
 * platform-assignable roles, audits every change and never weakens the existing
 * authorization model.
 */
class PrincipalSettingsTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    // ------------------------------------------------------- Access control

    public function test_the_settings_page_renders_platform_configuration_for_an_authorized_principal(): void
    {
        $this->actingAs($this->principal())
            ->get('/principal/settings')
            ->assertOk()
            ->assertSee('Pengaturan Platform')
            ->assertSee('Bahasa Default')
            ->assertSee('Bahasa Indonesia');
    }

    public function test_the_settings_page_is_forbidden_without_rbac_manage(): void
    {
        // brand_manager reaches the Principal panel but holds only
        // `brand.metadata.manage`.
        $brandManager = $this->principal(RoleKey::BRAND_MANAGER);

        $this->actingAs($brandManager)->get('/principal/settings')->assertForbidden();
        $this->assertFalse(PlatformSettings::canAccess());
    }

    // ------------------------------------------------ Platform configuration

    public function test_the_locale_overview_reflects_the_shipped_platform_configuration(): void
    {
        $this->actingAs($this->principal());

        $page = new PlatformSettings;

        $this->assertSame(
            [
                'id' => 'Bahasa Indonesia',
                'ms-MY' => 'Bahasa Melayu Malaysia',
                'en' => 'English',
            ],
            $page->locales(),
        );
        $this->assertSame('id', $page->defaultLocale());
        $this->assertSame(['babul-syiffa' => 'id', 'nubiom' => 'ms-MY'], $page->brandDefaults());
    }

    public function test_the_gateway_posture_never_exposes_credentials(): void
    {
        PaymentGateway::factory()
            ->provider('midtrans', 'Midtrans')
            ->live()
            ->enabled()
            ->credentials(['server_key' => 'super-secret'])
            ->create();

        $this->actingAs($this->principal());

        $rows = (new PlatformSettings)->gateways();

        $this->assertCount(1, $rows);
        $this->assertSame('Midtrans', $rows[0]['label']);
        $this->assertSame('live', $rows[0]['mode']);
        $this->assertTrue($rows[0]['enabled']);
        $this->assertFalse($rows[0]['verified']);
        // The credential value is never projected to the view.
        $this->assertArrayNotHasKey('credentials', $rows[0]);
    }

    // ------------------------------------------------------ RBAC administration

    public function test_an_authorized_principal_can_grant_and_revoke_a_platform_role(): void
    {
        $target = User::factory()->create();
        $actor = $this->principal();

        $this->actingAs($actor);

        $page = new PlatformSettings;
        $page->grantUserId = (string) $target->getKey();
        $page->grantRoleKey = RoleKey::PRINCIPAL_MODULE_ADMIN;
        $page->grantRole();

        $this->assertDatabaseHas('principal_role_assignments', [
            'user_id' => $target->getKey(),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
        ]);

        // The grant is real: it now resolves through the platform authorizer.
        $this->assertTrue(
            app(AuthorizesPrincipal::class)->can($target->fresh(), PermissionKey::PLATFORM_MODULE_MANAGE),
        );

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.rbac',
            'action' => 'grant_role',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => (string) $target->getKey(),
            'actor_id' => $actor->getKey(),
        ]);

        $page->revokeRole((string) $target->getKey(), RoleKey::PRINCIPAL_MODULE_ADMIN);

        $this->assertDatabaseHas('principal_role_assignments', [
            'user_id' => $target->getKey(),
            'status' => PrincipalRoleAssignment::STATUS_REVOKED,
        ]);

        // The revoked role no longer resolves.
        $this->assertFalse(
            app(AuthorizesPrincipal::class)->can($target->fresh(), PermissionKey::PLATFORM_MODULE_MANAGE),
        );

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.rbac',
            'action' => 'revoke_role',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope_key' => (string) $target->getKey(),
        ]);
    }

    public function test_a_non_platform_role_cannot_be_granted_and_is_audited_denied(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->principal());

        $page = new PlatformSettings;
        $page->grantUserId = (string) $target->getKey();
        $page->grantRoleKey = RoleKey::TENANT_OWNER;
        $page->grantRole();

        $this->assertDatabaseMissing('principal_role_assignments', [
            'user_id' => $target->getKey(),
        ]);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.rbac',
            'action' => 'grant_role',
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'role_not_assignable',
            'scope_key' => (string) $target->getKey(),
        ]);
    }

    public function test_a_denied_principal_cannot_mutate_even_if_reached(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->principal(RoleKey::BRAND_MANAGER));

        $page = new PlatformSettings;
        $page->grantUserId = (string) $target->getKey();
        $page->grantRoleKey = RoleKey::PRINCIPAL_FINANCE;

        // The authorization boundary is re-derived server side, not from UI
        // visibility: reaching the action still fails closed.
        $this->assertThrows(
            fn () => $page->grantRole(),
            HttpException::class,
        );

        $this->assertDatabaseMissing('principal_role_assignments', [
            'user_id' => $target->getKey(),
        ]);
    }

    public function test_the_identity_list_surfaces_existing_governance_assignments(): void
    {
        $governance = User::factory()->create(['name' => 'Gov Identity']);
        $this->grantPrincipalRole($governance, RoleKey::PRINCIPAL_FINANCE);

        $this->actingAs($this->principal());

        $identities = (new PlatformSettings)->identities();

        $this->assertTrue($identities->contains(fn (User $user) => $user->is($governance)));
        $this->assertSame(
            [RoleKey::PRINCIPAL_FINANCE],
            (new PlatformSettings)->activeRoleKeys($governance->fresh()),
        );
    }
}
