<?php

namespace Tests\Feature\Auth;

use App\Filament\Principal\Resources\TenantResource\RelationManagers\MembershipsRelationManager;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Exceptions\StaffInvitationException;
use App\Platform\Access\RoleKey;
use App\Platform\Access\StaffInvitation;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS4 P0 — secure staff invitation / one-time account activation.
 *
 * A Principal invitation must never hand the invitee a usable credential, and
 * must never be able to take over an established account. Redemption branches
 * on the invited identity:
 *
 *  - a NEW account (created by the invitation) sets its OWN password once;
 *  - an EXISTING account must be signed in as itself to accept, and its
 *    password is never reset by the token.
 *
 * These tests assert the security properties end to end: proof hashing, single
 * use (atomic), expiry, the fail-closed negative paths, the audit trail, that no
 * password is ever exposed, that Principal accounts cannot be taken over, and
 * that a newly activated staff member can sign in through the unified `/login`
 * and reach the Clinic panel.
 */
class StaffInvitationTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, DrivesPrincipalPanel, RefreshDatabase;

    private const NEW_PASSWORD = 'a-strong-staff-passphrase-2026';

    private const EXISTING_PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A NEW invited identity (created by the invitation, no credential yet).
     *
     * @return array{0: TenantMembership, 1: User, 2: string, 3: Tenant}
     */
    private function invitedNewAccount(): array
    {
        $tenant = Tenant::factory()->create();
        $invitee = User::factory()->role(RoleKey::RECEPTIONIST)
            ->mustSetPassword()
            ->create(['email' => 'new.invitee@example.test']);

        $membership = $this->giveMembership($invitee, $tenant, [RoleKey::RECEPTIONIST], TenantMembership::STATUS_INVITED);
        $proof = app(StaffInvitation::class)->issue($membership, $this->principal());

        return [$membership->refresh(), $invitee, $proof, $tenant];
    }

    /**
     * An EXISTING invited identity (already holds a credential).
     *
     * @return array{0: TenantMembership, 1: User, 2: string, 3: Tenant}
     */
    private function invitedExistingAccount(): array
    {
        $tenant = Tenant::factory()->create();
        $invitee = User::factory()->role(RoleKey::RECEPTIONIST)
            ->create(['email' => 'existing.invitee@example.test']);

        $membership = $this->giveMembership($invitee, $tenant, [RoleKey::RECEPTIONIST], TenantMembership::STATUS_INVITED);
        $proof = app(StaffInvitation::class)->issue($membership, $this->principal());

        return [$membership->refresh(), $invitee, $proof, $tenant];
    }

    /** Submit the unified staff login form the way a browser does. */
    private function submitLogin(string $email, string $password): TestResponse
    {
        $html = $this->get('/login')->assertOk()->getContent();

        if (! preg_match('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The login page did not render a Livewire snapshot.');
        }

        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'calls' => [['method' => 'authenticate', 'params' => [], 'path' => '']],
                'updates' => ['data.email' => $email, 'data.password' => $password],
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Issuing a proof
    |--------------------------------------------------------------------------
    */

    public function test_inviting_a_new_staff_member_issues_a_hashed_single_use_proof_and_audits_it(): void
    {
        $tenant = Tenant::factory()->create();
        $principal = $this->principal();

        $this->actingAs($principal);

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['inviteStaff', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.email' => 'new.staff@example.test',
                'mountedActions.0.data.name' => 'New Staff',
                'mountedActions.0.data.role_keys' => [RoleKey::RECEPTIONIST],
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->status);

        $user = User::query()->where('email', 'new.staff@example.test')->firstOrFail();
        $this->assertTrue($user->must_set_password);

        $row = DB::table('tenant_memberships')->where('id', $membership->getKey())->first();

        // Only the hash is persisted — never the raw proof.
        $this->assertNotNull($row->invite_token_hash);
        $this->assertSame(64, strlen($row->invite_token_hash));
        $this->assertNotNull($row->invite_expires_at);
        $this->assertSame((string) $principal->getKey(), (string) $row->invite_issued_by);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.rbac',
            'action' => StaffInvitation::ACTION_ISSUED,
            'scope' => 'platform',
            'scope_key' => $tenant->getKey(),
            'actor_id' => $principal->getKey(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. NEW account — one-time password setup, then login
    |--------------------------------------------------------------------------
    */

    public function test_a_new_account_sets_its_own_password_activates_and_logs_in_through_login(): void
    {
        [$membership, $invitee, $proof] = $this->invitedNewAccount();

        $this->get('/staff/invitation/'.$proof)
            ->assertOk()
            ->assertSee(__('lba.staff_invitation.title'));

        $this->post('/staff/invitation/'.$proof, [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        $invitee->refresh();
        $membership->refresh();

        $this->assertTrue($invitee->is_active);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $invitee->password));
        $this->assertFalse($invitee->must_set_password);
        $this->assertSame(TenantMembership::STATUS_ACTIVE, $membership->status);
        $this->assertNotNull($membership->activated_at);
        $this->assertNull($membership->invite_token_hash);

        $response = $this->submitLogin($invitee->email, self::NEW_PASSWORD);
        $response->assertOk();
        $this->assertSame('/clinic', $response->json('components.0.effects.redirect'));
    }

    public function test_a_new_account_redemption_requires_a_password(): void
    {
        [, , $proof] = $this->invitedNewAccount();

        $this->expectException(StaffInvitationException::class);

        app(StaffInvitation::class)->redeem($proof, null);
    }

    public function test_a_weak_password_is_rejected(): void
    {
        [$membership, , $proof] = $this->invitedNewAccount();

        $this->post('/staff/invitation/'.$proof, [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. EXISTING account — authentication required, password untouched
    |--------------------------------------------------------------------------
    */

    public function test_an_existing_account_cannot_be_accepted_without_being_signed_in(): void
    {
        [$membership, $invitee, $proof] = $this->invitedExistingAccount();

        try {
            app(StaffInvitation::class)->redeem($proof, self::NEW_PASSWORD);
            $this->fail('An existing account must not be activated without authentication.');
        } catch (StaffInvitationException $exception) {
            $this->assertTrue($exception->isAuthenticationRequired());
        }

        $invitee->refresh();
        $membership->refresh();

        // The password was NOT reset and the membership stays invited.
        $this->assertTrue(Hash::check(self::EXISTING_PASSWORD, $invitee->password));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $invitee->password));
        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->status);
    }

    public function test_an_existing_account_accepts_while_signed_in_and_keeps_its_password(): void
    {
        [$membership, $invitee, $proof] = $this->invitedExistingAccount();

        app(StaffInvitation::class)->redeem($proof, self::NEW_PASSWORD, $invitee);

        $invitee->refresh();
        $membership->refresh();

        $this->assertTrue(Hash::check(self::EXISTING_PASSWORD, $invitee->password));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $invitee->password));
        $this->assertSame(TenantMembership::STATUS_ACTIVE, $membership->status);
        $this->assertNull($membership->invite_token_hash);
    }

    public function test_a_different_identity_cannot_accept_an_existing_accounts_invitation(): void
    {
        [$membership, $invitee, $proof] = $this->invitedExistingAccount();

        $intruder = User::factory()->role(RoleKey::RECEPTIONIST)->create();

        try {
            app(StaffInvitation::class)->redeem($proof, null, $intruder);
            $this->fail('A different identity must not be able to accept the invitation.');
        } catch (StaffInvitationException $exception) {
            $this->assertTrue($exception->isAuthenticationRequired());
        }

        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->refresh()->status);
        $this->assertTrue(Hash::check(self::EXISTING_PASSWORD, $invitee->refresh()->password));
    }

    public function test_the_existing_account_page_asks_the_visitor_to_sign_in(): void
    {
        [, $invitee, $proof] = $this->invitedExistingAccount();

        $this->get('/staff/invitation/'.$proof)
            ->assertOk()
            ->assertSee(__('lba.staff_invitation.sign_in_required', ['email' => $invitee->email]));
    }

    public function test_the_existing_account_page_offers_accept_when_signed_in(): void
    {
        [, $invitee, $proof] = $this->invitedExistingAccount();

        $this->actingAs($invitee)
            ->get('/staff/invitation/'.$proof)
            ->assertOk()
            ->assertSee(__('lba.staff_invitation.accept'));
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Principal accounts cannot be taken over
    |--------------------------------------------------------------------------
    */

    public function test_a_principal_identity_cannot_be_invited_as_tenant_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $principal = $this->principal();

        $this->actingAs($principal);

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['inviteStaff', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.email' => $principal->email,
                'mountedActions.0.data.name' => $principal->name,
                'mountedActions.0.data.role_keys' => [RoleKey::TENANT_ADMIN],
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        // No membership and no proof were created for the Principal identity.
        $this->assertDatabaseMissing('tenant_memberships', [
            'tenant_id' => $tenant->getKey(),
            'user_id' => $principal->getKey(),
        ]);
        $this->assertDatabaseMissing('module_audit_logs', [
            'action' => StaffInvitation::ACTION_ISSUED,
            'scope_key' => $tenant->getKey(),
        ]);
    }

    public function test_an_invitation_can_never_take_over_a_principal_account(): void
    {
        // Even if a proof were minted for a Principal membership, redemption
        // refuses it and never touches the credential.
        $tenant = Tenant::factory()->create();
        $principal = $this->principal();

        $membership = $this->giveMembership($principal, $tenant, [RoleKey::TENANT_ADMIN], TenantMembership::STATUS_INVITED);
        $proof = app(StaffInvitation::class)->issue($membership, $principal);

        $original = $principal->refresh()->password;

        try {
            app(StaffInvitation::class)->redeem($proof, self::NEW_PASSWORD, $principal);
            $this->fail('A Principal account must never be activated through an invitation.');
        } catch (StaffInvitationException) {
            // expected — fails closed
        }

        $this->assertSame($original, $principal->refresh()->password);
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $principal->password));
        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Negative paths — fail closed
    |--------------------------------------------------------------------------
    */

    public function test_an_expired_proof_is_refused(): void
    {
        [$membership, , $proof] = $this->invitedNewAccount();

        $this->travel(StaffInvitation::INVITATION_TTL_HOURS + 1)->hours();

        try {
            app(StaffInvitation::class)->redeem($proof, self::NEW_PASSWORD);
            $this->fail('An expired proof must not activate the account.');
        } catch (StaffInvitationException) {
            // expected
        }

        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->refresh()->status);
    }

    public function test_a_proof_can_only_be_redeemed_once(): void
    {
        [$membership, , $proof, $tenant] = $this->invitedNewAccount();

        app(StaffInvitation::class)->redeem($proof, self::NEW_PASSWORD);
        $this->assertSame(TenantMembership::STATUS_ACTIVE, $membership->refresh()->status);

        $this->expectException(StaffInvitationException::class);

        try {
            app(StaffInvitation::class)->redeem($proof, 'another-passphrase-9876');
        } finally {
            // The single-use claim must have produced exactly one redemption.
            $this->assertSame(1, DB::table('module_audit_logs')
                ->where('action', StaffInvitation::ACTION_REDEEMED)
                ->where('scope_key', $tenant->getKey())
                ->count());
        }
    }

    public function test_an_unknown_proof_is_refused(): void
    {
        $this->expectException(StaffInvitationException::class);

        app(StaffInvitation::class)->redeem(str_repeat('x', 64), self::NEW_PASSWORD);
    }

    public function test_the_activation_page_refuses_an_invalid_proof(): void
    {
        $bogus = str_repeat('x', 64);

        $this->get('/staff/invitation/'.$bogus)
            ->assertOk()
            ->assertSee(__('lba.staff_invitation.invalid'));

        $this->post('/staff/invitation/'.$bogus, [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('password');
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Never expose a password
    |--------------------------------------------------------------------------
    */

    public function test_inviting_never_creates_a_usable_or_exposed_credential(): void
    {
        $tenant = Tenant::factory()->create();
        $principal = $this->principal();

        $this->actingAs($principal);

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['inviteStaff', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.email' => 'fresh.staff@example.test',
                'mountedActions.0.data.name' => 'Fresh Staff',
                'mountedActions.0.data.role_keys' => [RoleKey::CONSULTANT],
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $user = User::query()->where('email', 'fresh.staff@example.test')->firstOrFail();

        // The account exists and is routable, but carries no guessable credential.
        $this->assertSame(RoleKey::CONSULTANT, $user->role);
        $this->assertTrue($user->must_set_password);
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('secret', $user->password));

        // No known/default password can authenticate through /login. Drop the
        // principal's session so we reach /login as the invited guest would.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $response = $this->submitLogin($user->email, 'password');
        $this->assertNull($response->json('components.0.effects.redirect'));
    }

    /*
    |--------------------------------------------------------------------------
    | 7. The whole lifecycle is audited
    |--------------------------------------------------------------------------
    */

    public function test_the_full_invitation_lifecycle_is_audited(): void
    {
        [, , $proof, $tenant] = $this->invitedNewAccount();

        app(StaffInvitation::class)->redeem($proof, self::NEW_PASSWORD);

        foreach ([StaffInvitation::ACTION_ISSUED, StaffInvitation::ACTION_REDEEMED] as $action) {
            $this->assertDatabaseHas('module_audit_logs', [
                'module_key' => 'platform.rbac',
                'action' => $action,
                'result' => 'allowed',
                'scope' => 'platform',
                'scope_key' => $tenant->getKey(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Integration — invitation → activation → Clinic login
    |--------------------------------------------------------------------------
    */

    public function test_principal_invitation_activates_and_allows_clinic_login(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Mitra Integrasi']);
        $principal = $this->principal();

        $this->actingAs($principal);

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['inviteStaff', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.email' => 'integration.staff@example.test',
                'mountedActions.0.data.name' => 'Integration Staff',
                'mountedActions.0.data.role_keys' => [RoleKey::TENANT_ADMIN],
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $membership = TenantMembership::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->status);

        // Before activation the invited identity reaches no clinic context.
        // Drop the Principal's session so the invitee is evaluated on its own.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $invitee = $membership->user;
        $this->actingAs($invitee)->get('/clinic')->assertForbidden();

        // The raw proof is only ever surfaced in the Principal's response, so
        // the test obtains the one-time activation link the same way a resend
        // would: by re-issuing it for the invited membership.
        $proof = app(StaffInvitation::class)->issue($membership, $principal);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->post('/staff/invitation/'.$proof, [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        // The freshly activated staff member signs in and lands on the Clinic.
        $login = $this->submitLogin('integration.staff@example.test', self::NEW_PASSWORD);
        $login->assertOk();
        $this->assertSame('/clinic', $login->json('components.0.effects.redirect'));

        // ...and the Clinic dashboard is reachable with the verified context.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // Filament boots the *current* panel only once per process (the manager's
        // boot flag is not reset on a panel switch). A test that drives two
        // panels would otherwise skip the Clinic panel's boot and render its
        // layout without the panel-specific topbar hooks. Boot it explicitly.
        $clinic = Filament::getPanel('clinic');
        Filament::setCurrentPanel($clinic);
        $clinic->boot();

        $this->actingAs($invitee->refresh())
            ->get('/clinic')
            ->assertOk()
            ->assertSee('Mitra Integrasi');
    }

    public function test_the_clinic_denies_an_identity_without_any_membership_with_a_useful_message(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->actingAs($user)
            ->get('/clinic')
            ->assertForbidden()
            ->assertSee(__('lba.tenant_guard.no_active_partner'));
    }
}
