<?php

namespace Tests\Feature\Platform\Access;

use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use App\Platform\Access\AccountEmailChanger;
use App\Platform\Access\Exceptions\AccountEmailChangeException;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * WS9.E — secure self-service change of the LOGIN email.
 *
 * The new address is never applied until it is verified, the current password is
 * required to request a change, and a verification can never be replayed or
 * applied to a different address. Every request/verification is audited.
 */
class AccountEmailChangerTest extends TestCase
{
    use RefreshDatabase;

    private function changer(): AccountEmailChanger
    {
        return app(AccountEmailChanger::class);
    }

    private function user(string $password = 'correct-horse-battery'): User
    {
        return User::factory()->create([
            'email' => 'lama@example.test',
            'password' => $password,
        ]);
    }

    public function test_a_change_request_requires_the_current_password_and_sends_a_verification_link_to_the_new_address(): void
    {
        Notification::fake();

        $user = $this->user();

        $this->changer()->requestChange($user, 'BARU@example.test', 'correct-horse-battery');

        $user->refresh();

        // The login email is NOT yet changed; the requested address is pending.
        $this->assertSame('lama@example.test', $user->email);
        $this->assertSame('baru@example.test', $user->pending_email);

        Notification::assertSentOnDemand(VerifyAccountEmail::class);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => AccountEmailChanger::MODULE_KEY,
            'action' => AccountEmailChanger::ACTION_REQUESTED,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'actor_id' => $user->getKey(),
        ]);
    }

    public function test_a_wrong_current_password_is_refused_and_nothing_is_pending(): void
    {
        Notification::fake();

        $user = $this->user();

        try {
            $this->changer()->requestChange($user, 'baru@example.test', 'wrong-password');
            $this->fail('A wrong current password must be refused.');
        } catch (AccountEmailChangeException) {
            // expected
        }

        $user->refresh();

        $this->assertSame('lama@example.test', $user->email);
        $this->assertNull($user->pending_email);
        Notification::assertNothingSent();

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => AccountEmailChanger::ACTION_REQUESTED,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'current_password_mismatch',
        ]);
    }

    public function test_verifying_applies_the_pending_email_and_marks_it_verified(): void
    {
        $user = $this->user();
        $this->changer()->requestChange($user, 'baru@example.test', 'correct-horse-battery');

        $this->assertTrue($this->changer()->verify((string) $user->getKey(), 'baru@example.test'));

        $user->refresh();

        $this->assertSame('baru@example.test', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => AccountEmailChanger::ACTION_VERIFIED,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'actor_id' => $user->getKey(),
        ]);
    }

    public function test_verifying_cannot_be_replayed(): void
    {
        $user = $this->user();
        $this->changer()->requestChange($user, 'baru@example.test', 'correct-horse-battery');

        $this->assertTrue($this->changer()->verify((string) $user->getKey(), 'baru@example.test'));
        // A second attempt finds nothing pending.
        $this->assertFalse($this->changer()->verify((string) $user->getKey(), 'baru@example.test'));
    }

    public function test_verifying_with_a_mismatched_address_fails_closed(): void
    {
        $user = $this->user();
        $this->changer()->requestChange($user, 'baru@example.test', 'correct-horse-battery');

        $this->assertFalse($this->changer()->verify((string) $user->getKey(), 'lain@example.test'));

        $this->assertSame('lama@example.test', $user->refresh()->email);
    }

    public function test_verifying_an_unknown_user_fails_closed(): void
    {
        $this->assertFalse($this->changer()->verify('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'baru@example.test'));
    }
}
