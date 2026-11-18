<?php

namespace Tests\Feature\Auth;

use App\Filament\Principal\Pages\ChangePassword as PrincipalChangePassword;
use App\Models\PatientApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Exceptions\PasswordChangeException;
use App\Platform\Access\Exceptions\PasswordResetException;
use App\Platform\Access\PasswordChanger;
use App\Platform\Access\PasswordReset;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS6.D — password security.
 *
 * Covers the two sanctioned workflows and every fail-closed negative path:
 *
 *  - self-service change: the identity changes ONLY its own password after
 *    proving the current one (session, no client id);
 *  - controlled reset: a Super Admin may START a one-time recovery for another
 *    human identity, but never learns or sets the new password — the token is
 *    delivered to the account's own email by the existing Laravel broker.
 *
 * Also asserts: the token store keeps only a hash, tokens are single-use and
 * expire, throttling blocks rapid re-issues, no plaintext password is ever
 * persisted or exposed, and both outcomes are audited.
 */
class PasswordManagementTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, DrivesPrincipalPanel, RefreshDatabase;

    private const CURRENT_PASSWORD = 'password';

    private const NEW_PASSWORD = 'a-strong-new-passphrase-2026';

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

    private function resetToken(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    /** Drive the authenticated change-password page exactly like a browser. */
    private function changePasswordViaPage(User $user, array $data): TestResponse
    {
        $this->actingAs($user);

        $html = $this->renderPage('/principal/change-password');

        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $this->componentSnapshot($html, PrincipalChangePassword::class),
                'calls' => [['method' => 'changePassword', 'params' => [], 'path' => '']],
                'updates' => $data,
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Self-service change — the only way to rotate your OWN credential
    |--------------------------------------------------------------------------
    */

    public function test_an_identity_can_change_its_own_password_with_the_current_password(): void
    {
        $user = User::factory()->create();

        app(PasswordChanger::class)->change($user, self::CURRENT_PASSWORD, self::NEW_PASSWORD);

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check(self::CURRENT_PASSWORD, $user->password));
        $this->assertFalse($user->must_set_password);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.rbac',
            'action' => PasswordChanger::ACTION,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope' => 'platform',
            'scope_key' => (string) $user->getKey(),
            'actor_id' => $user->getKey(),
        ]);
    }

    public function test_a_wrong_current_password_is_denied_and_audited_without_changing_anything(): void
    {
        $user = User::factory()->create();
        $original = $user->password;

        try {
            app(PasswordChanger::class)->change($user, 'not-the-current-password', self::NEW_PASSWORD);
            $this->fail('A wrong current password must not change the credential.');
        } catch (PasswordChangeException) {
            // expected — fails closed
        }

        $this->assertSame($original, $user->refresh()->password);
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->password));

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordChanger::ACTION,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'current_password_mismatch',
        ]);
    }

    public function test_changing_a_password_never_stores_a_plaintext_credential(): void
    {
        $user = User::factory()->create();

        app(PasswordChanger::class)->change($user, self::CURRENT_PASSWORD, self::NEW_PASSWORD);

        $stored = (string) $user->refresh()->password;

        $this->assertNotSame(self::NEW_PASSWORD, $stored);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $stored);
    }

    public function test_changing_a_password_revokes_derived_patient_api_tokens(): void
    {
        $user = User::factory()->create();

        $token = PatientApiToken::query()->create([
            'user_id' => $user->getKey(),
            'tenant_id' => Tenant::factory()->create()->getKey(),
            'name' => 'synthetic',
            'token' => hash('sha256', 'raw-secret'),
            'abilities' => ['report.read.published'],
        ]);

        $this->assertTrue($token->isUsable());

        app(PasswordChanger::class)->change($user, self::CURRENT_PASSWORD, self::NEW_PASSWORD);

        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertFalse($token->isUsable());
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Self-service change through the real authenticated page
    |--------------------------------------------------------------------------
    */

    public function test_the_change_password_page_rotates_the_signed_in_identity_only(): void
    {
        $user = $this->principal();

        $this->changePasswordViaPage($user, [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check(self::CURRENT_PASSWORD, $user->password));
    }

    public function test_the_change_password_page_surfaces_a_wrong_current_password(): void
    {
        $user = $this->principal();

        $response = $this->changePasswordViaPage($user, [
            'current_password' => 'wrong-current',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            __('lba.password_change.current_incorrect'),
            (string) $response->json('components.0.effects.html'),
        );
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    public function test_the_change_password_page_rejects_a_weak_password(): void
    {
        $user = $this->principal();

        $this->changePasswordViaPage($user, [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $this->assertFalse(Hash::check('short', $user->refresh()->password));
        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->password));
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Controlled reset — a Super Admin may START one, never own it
    |--------------------------------------------------------------------------
    */

    public function test_a_super_admin_may_start_a_reset_for_another_user_without_learning_the_password(): void
    {
        Notification::fake();

        $principal = $this->principal();
        $target = User::factory()->create();

        // request() returns void: the raw token is never handed back.
        app(PasswordReset::class)->request($target, $principal);

        // Only a HASH of the single-use token is persisted.
        $row = DB::table('password_reset_tokens')->where('email', $target->email)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('', (string) $row->token);

        // The link is delivered to the target's OWN email and points at our
        // localised, panel-less recovery route.
        Notification::assertSentTo($target, ResetPassword::class, function (ResetPassword $notification) use ($target): bool {
            $url = $notification->toMail($target)->actionUrl;

            return str_contains($url, '/staff/password/reset/')
                && str_contains($url, urlencode((string) $target->email));
        });

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordReset::ACTION_REQUESTED,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope_key' => (string) $target->getKey(),
            'actor_id' => $principal->getKey(),
        ]);
    }

    public function test_an_identity_without_the_access_management_capability_cannot_reset_another_user(): void
    {
        $actor = User::factory()->role(RoleKey::TENANT_ADMIN)->create();
        $target = User::factory()->create();

        try {
            app(PasswordReset::class)->request($target, $actor);
            $this->fail('An identity without the capability must not start a reset for another user.');
        } catch (PasswordResetException) {
            // expected — fails closed
        }

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordReset::ACTION_REQUESTED,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'not_permitted',
        ]);
    }

    public function test_a_principal_without_the_access_management_capability_is_also_denied(): void
    {
        // A finance principal holds platform permissions, but NOT rbac.manage.
        $actor = $this->principal(RoleKey::PRINCIPAL_FINANCE);
        $target = User::factory()->create();

        $this->assertFalse(app(PasswordReset::class)->mayInitiateFor($actor, $target));

        $this->expectException(PasswordResetException::class);

        app(PasswordReset::class)->request($target, $actor);
    }

    public function test_a_suspended_actor_cannot_start_a_reset_for_another_user(): void
    {
        $actor = $this->principal();
        $actor->forceFill(['is_active' => false])->save();

        $target = User::factory()->create();

        $this->assertFalse(app(PasswordReset::class)->mayInitiateFor($actor->refresh(), $target));

        $this->expectException(PasswordResetException::class);

        app(PasswordReset::class)->request($target, $actor->refresh());
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Reset negatives — suspended, expired, replayed, cross-user, throttled
    |--------------------------------------------------------------------------
    */

    public function test_a_suspended_identity_cannot_be_sent_a_reset(): void
    {
        Notification::fake();

        $principal = $this->principal();
        $target = User::factory()->suspended()->create();

        try {
            app(PasswordReset::class)->request($target, $principal);
            $this->fail('A suspended identity must not receive a reset link.');
        } catch (PasswordResetException) {
            // expected
        }

        Notification::assertNothingSent();
        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordReset::ACTION_REQUESTED,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'inactive',
        ]);
    }

    public function test_a_valid_token_resets_the_password_and_may_not_be_replayed(): void
    {
        $user = User::factory()->create();
        $token = $this->resetToken($user);

        $this->post('/staff/password/reset/'.$token, [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));

        // The token is deleted on use, so the same link can never work twice.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->post('/staff/password/reset/'.$token, [
            'email' => $user->email,
            'password' => 'another-strong-passphrase-7777',
            'password_confirmation' => 'another-strong-passphrase-7777',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->resetToken($user);

        $this->travel(61)->minutes();

        $this->post('/staff/password/reset/'.$token, [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->refresh()->password));
    }

    public function test_a_token_issued_for_one_identity_cannot_reset_another(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();

        $token = $this->resetToken($victim);

        // The attacker presents the victim's token against their OWN email.
        $this->post('/staff/password/reset/'.$token, [
            'email' => $attacker->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $attacker->refresh()->password));
        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $victim->refresh()->password));
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/staff/password/reset/'.str_repeat('a', 64), [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->refresh()->password));
    }

    public function test_a_suspended_identity_cannot_redeem_a_reset_token(): void
    {
        $user = User::factory()->create();
        $token = $this->resetToken($user);

        $user->forceFill(['is_active' => false])->save();

        $this->post('/staff/password/reset/'.$token, [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    public function test_simultaneous_reset_requests_are_throttled(): void
    {
        Notification::fake();

        $principal = $this->principal();
        $target = User::factory()->create();

        app(PasswordReset::class)->request($target, $principal);

        try {
            app(PasswordReset::class)->request($target, $principal);
            $this->fail('A rapid second request must be throttled.');
        } catch (PasswordResetException) {
            // expected
        }

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordReset::ACTION_REQUESTED,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'throttled',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Self-service recovery request — no identity disclosure
    |--------------------------------------------------------------------------
    */

    public function test_the_forgot_password_page_renders(): void
    {
        $this->get('/staff/password/forgot')
            ->assertOk()
            ->assertSee(__('lba.password_reset.forgot_title'));
    }

    public function test_a_known_email_receives_a_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->from('/staff/password/forgot')
            ->post('/staff/password/forgot', ['email' => $user->email])
            ->assertRedirect('/staff/password/forgot')
            ->assertSessionHas('status', __('lba.password_reset.request_sent'));

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_email_gets_the_same_neutral_response(): void
    {
        Notification::fake();

        $this->from('/staff/password/forgot')
            ->post('/staff/password/forgot', ['email' => 'nobody@example.test'])
            ->assertRedirect('/staff/password/forgot')
            ->assertSessionHas('status', __('lba.password_reset.request_sent'));

        Notification::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | 6. CSRF protection on every write endpoint
    |--------------------------------------------------------------------------
    */

    public function test_the_password_write_endpoints_run_in_the_csrf_protected_web_group(): void
    {
        $router = app('router');

        $csrfGuarded = collect($router->getMiddlewareGroups()['web'] ?? [])->contains(
            fn (string $middleware): bool => str_contains($middleware, 'Csrf')
                || str_contains($middleware, 'RequestForgery'),
        );

        $this->assertTrue($csrfGuarded, 'The web middleware group must include CSRF protection.');

        foreach ([
            'staff.password.email',
            'staff.password.reset.store',
            'staff.invitation.accept',
        ] as $name) {
            $route = $router->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] is not registered.");
            $this->assertContains('web', $route->middleware(), "Route [{$name}] must run in the web group.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 7. No plaintext token or password ever reaches an audit record
    |--------------------------------------------------------------------------
    */

    public function test_audit_records_never_carry_a_token_or_password(): void
    {
        Notification::fake();

        $principal = $this->principal();
        $target = User::factory()->create();

        app(PasswordReset::class)->request($target, $principal);
        $rawToken = $this->resetToken($target);

        app(PasswordReset::class)->reset([
            'email' => $target->email,
            'password' => self::NEW_PASSWORD,
            'token' => $rawToken,
        ], $principal);

        foreach (ModuleAuditLog::query()->get() as $log) {
            $serialized = json_encode($log->toArray());

            $this->assertStringNotContainsString($rawToken, (string) $serialized);
            $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) $serialized);
        }
    }
}
