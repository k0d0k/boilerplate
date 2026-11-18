<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 1B-1 — unified staff authentication security tests.
 *
 * All identities are synthetic (see UserFactory); the shared factory password
 * is a literal used by the tests and is never a real credential.
 *
 * The staff login is a Filament page that submits through Livewire, so the
 * flows below are exercised over HTTP exactly like a browser does: fetch the
 * page, then POST the rendered snapshot back to Livewire's update endpoint
 * with the form state applied. Livewire's own `test()` harness cannot replay
 * the panel middleware on its synthetic update request (its REQUEST_URI is
 * built from a root-relative URL and loses the leading slash — see
 * tests/TestCase.php), so the real endpoint is used instead.
 *
 * Nothing here trusts the UI: every outcome is asserted against the
 * server-side gate (`canAccessPanel()` + the role => panel policy).
 */
class StaffLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SYNTHETIC_PASSWORD = 'password';

    /*
    |--------------------------------------------------------------------------
    | Helpers — the real browser flow
    |--------------------------------------------------------------------------
    */

    /** Render a Livewire-backed page and return its markup. */
    private function renderPage(string $path): string
    {
        return $this->get($path)->assertOk()->getContent();
    }

    /**
     * Submit a rendered login page's form the way the browser does.
     *
     * @param  array<string, mixed>  $extraState  additional top-level form state keys
     */
    private function submitLoginForm(string $html, string $email, string $password, array $extraState = []): TestResponse
    {
        if (! preg_match('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The login page did not render a Livewire snapshot.');
        }

        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'calls' => [['method' => 'authenticate', 'params' => [], 'path' => '']],
                'updates' => array_merge([
                    'data.email' => $email,
                    'data.password' => $password,
                ], $extraState),
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /** Convenience wrapper: render the page, then submit it. */
    private function login(string $path, string $email, string $password, array $extraState = []): TestResponse
    {
        return $this->submitLoginForm($this->renderPage($path), $email, $password, $extraState);
    }

    /** The panel the login response redirects to, if it authenticated. */
    private function redirectTarget(TestResponse $response): ?string
    {
        return $response->json('components.0.effects.redirect');
    }

    /** Validation errors reported by the server after a rejected attempt. */
    private function validationErrors(TestResponse $response): array
    {
        $snapshot = json_decode($response->json('components.0.snapshot') ?? '{}', true) ?: [];

        return $snapshot['memo']['errors'] ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Unified entry point & redirect safety
    |--------------------------------------------------------------------------
    */

    public function test_the_unified_staff_login_is_the_single_entry_point(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_legacy_staff_login_urls_forward_to_the_unified_login_without_loops(): void
    {
        // A guest who hits a staff panel is sent to that panel's login route...
        $this->get('/principal')->assertRedirect('/principal/login');
        $this->get('/clinic')->assertRedirect('/clinic/login');

        // ...which forwards to the one unified entry point...
        $this->get('/principal/login')->assertRedirect('/login');
        $this->get('/clinic/login')->assertRedirect('/login');

        // ...and the chain terminates on the form instead of looping.
        $this->get('/login')->assertOk();
    }

    public function test_login_page_exposes_no_role_selection_or_privileged_shortcut(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Masuk ke akun Anda')
            ->assertDontSee('name="role"', false)
            ->assertDontSee('Super Admin')
            ->assertDontSee('Pilih peran');
    }

    /*
    |--------------------------------------------------------------------------
    | Successful authentication routes to the permitted panel
    |--------------------------------------------------------------------------
    */

    public function test_principal_staff_is_redirected_to_the_principal_panel(): void
    {
        $user = User::factory()->role('principal_super_admin')->create();

        $response = $this->login('/login', $user->email, self::SYNTHETIC_PASSWORD);

        $response->assertOk();
        $this->assertSame('/principal', $this->redirectTarget($response));
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_clinic_staff_is_redirected_to_the_clinic_panel(): void
    {
        $user = User::factory()->role('tenant_owner')->create();

        $response = $this->login('/login', $user->email, self::SYNTHETIC_PASSWORD);

        $response->assertOk();
        $this->assertSame('/clinic', $this->redirectTarget($response));
        $this->assertSame($user->id, Auth::id());
    }

    public function test_a_client_supplied_role_can_never_elevate_the_destination_panel(): void
    {
        // A clinic identity tries to smuggle a Principal role through form
        // state; the backend resolves the panel from the identity only.
        $user = User::factory()->role('tenant_admin')->create();

        $response = $this->login('/login', $user->email, self::SYNTHETIC_PASSWORD, [
            'data.role' => 'principal_super_admin',
        ]);

        $this->assertSame('/clinic', $this->redirectTarget($response));
    }

    /*
    |--------------------------------------------------------------------------
    | Negative authentication
    |--------------------------------------------------------------------------
    */

    public function test_incorrect_credentials_are_rejected(): void
    {
        $user = User::factory()->role('principal_super_admin')->create();

        $response = $this->login('/login', $user->email, 'definitely-not-the-password');

        $this->assertNull($this->redirectTarget($response));
        $this->assertArrayHasKey('data.email', $this->validationErrors($response));
        $this->assertFalse(Auth::check());
    }

    public function test_suspended_staff_identity_is_denied(): void
    {
        $user = User::factory()->role('principal_super_admin')->suspended()->create();

        $response = $this->login('/login', $user->email, self::SYNTHETIC_PASSWORD);

        $this->assertNull($this->redirectTarget($response));
        $this->assertArrayHasKey('data.email', $this->validationErrors($response));
        $this->assertFalse(Auth::check());
    }

    public function test_unknown_role_is_denied(): void
    {
        $user = User::factory()->create(); // role = null

        $response = $this->login('/login', $user->email, self::SYNTHETIC_PASSWORD);

        $this->assertNull($this->redirectTarget($response));
        $this->assertArrayHasKey('data.email', $this->validationErrors($response));
        $this->assertFalse(Auth::check());
    }

    public function test_patient_identity_cannot_use_the_staff_login(): void
    {
        $patient = User::factory()->role('patient_customer')->create();

        $response = $this->login('/login', $patient->email, self::SYNTHETIC_PASSWORD);

        $this->assertNull($this->redirectTarget($response));
        $this->assertArrayHasKey('data.email', $this->validationErrors($response));
        $this->assertFalse(Auth::check());
    }

    /*
    |--------------------------------------------------------------------------
    | Patient boundary stays separate
    |--------------------------------------------------------------------------
    */

    public function test_patient_login_is_a_separate_boundary(): void
    {
        $this->renderPage('/patient/login');

        $patient = User::factory()->role('patient_customer')->create();

        $response = $this->login('/patient/login', $patient->email, self::SYNTHETIC_PASSWORD);

        $this->assertSame('/patient', $this->redirectTarget($response));
    }

    public function test_staff_identity_cannot_sign_in_through_the_patient_login(): void
    {
        $staff = User::factory()->role('tenant_admin')->create();

        $response = $this->login('/patient/login', $staff->email, self::SYNTHETIC_PASSWORD);

        $this->assertNull($this->redirectTarget($response));
        $this->assertArrayHasKey('data.email', $this->validationErrors($response));
        $this->assertFalse(Auth::check());
    }

    /*
    |--------------------------------------------------------------------------
    | Session lifecycle & cross-panel isolation
    |--------------------------------------------------------------------------
    */

    public function test_session_is_regenerated_after_authentication(): void
    {
        $user = User::factory()->role('tenant_admin')->create();

        $html = $this->renderPage('/login');
        $before = session()->getId();

        $response = $this->submitLoginForm($html, $user->email, self::SYNTHETIC_PASSWORD);

        $this->assertSame('/clinic', $this->redirectTarget($response));
        $this->assertNotSame($before, session()->getId());
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->role('principal_super_admin')->create();

        $this->actingAs($user)->get('/principal')->assertOk();

        $this->actingAs($user)->post('/principal/logout')->assertRedirect();

        $this->assertFalse(Auth::check());

        // The protected panel is no longer reachable without re-authenticating.
        $this->get('/principal')->assertRedirect('/principal/login');
    }

    public function test_cross_panel_access_is_still_rejected(): void
    {
        $principal = User::factory()->role('principal_super_admin')->create();
        $clinic = User::factory()->role('tenant_admin')->create();
        $patient = User::factory()->role('patient_customer')->create();

        $this->actingAs($principal)->get('/clinic')->assertForbidden();
        $this->actingAs($principal)->get('/patient')->assertForbidden();

        $this->actingAs($clinic)->get('/principal')->assertForbidden();

        $this->actingAs($patient)->get('/principal')->assertForbidden();
        $this->actingAs($patient)->get('/clinic')->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 1B-1R — `url.intended` open-redirect hardening
    |--------------------------------------------------------------------------
    |
    | `url.intended` is seeded from a request the guest can shape, so it must
    | never be trusted as a whole URL. It is honoured only when it reduces to a
    | same-origin path inside the identity's authorised panel.
    |
    */

    /**
     * Sign in with a client-supplied `url.intended` already in the session,
     * exactly the way an attacker would seed it before a victim authenticates.
     */
    private function loginWithIntended(string $intended, string $email, string $password): TestResponse
    {
        $html = $this->renderPage('/login');

        $this->withSession(['url.intended' => $intended]);

        return $this->submitLoginForm($html, $email, $password);
    }

    public function test_intended_url_inside_the_authorized_panel_is_honoured(): void
    {
        $user = User::factory()->role('principal_super_admin')->create();

        $response = $this->loginWithIntended('/principal/reports', $user->email, self::SYNTHETIC_PASSWORD);

        $this->assertSame('/principal/reports', $this->redirectTarget($response));
    }

    public function test_same_origin_absolute_intended_url_is_honoured(): void
    {
        // Laravel's own `redirect()->guest()` stores intended URLs as absolute
        // URLs, so a same-origin absolute value must still resume the target.
        $user = User::factory()->role('tenant_admin')->create();

        $response = $this->loginWithIntended('http://localhost/clinic/appointments', $user->email, self::SYNTHETIC_PASSWORD);

        $this->assertSame('/clinic/appointments', $this->redirectTarget($response));
    }

    public function test_intended_url_from_another_panel_is_never_used(): void
    {
        // A clinic identity that requested a Principal URL before signing in is
        // not bounced onto a panel it cannot open.
        $user = User::factory()->role('tenant_admin')->create();

        $response = $this->loginWithIntended('http://localhost/principal/settings', $user->email, self::SYNTHETIC_PASSWORD);

        $this->assertSame('/clinic', $this->redirectTarget($response));
    }

    #[DataProvider('unsafeIntendedUrls')]
    public function test_external_or_malformed_intended_urls_are_never_honoured(string $label, string $intended): void
    {
        // Each case runs as its own test, keeping the per-IP login throttle
        // (5/minute) from masking the redirect result.
        $user = User::factory()->role('principal_super_admin')->create();

        $target = $this->redirectTarget(
            $this->loginWithIntended($intended, $user->email, self::SYNTHETIC_PASSWORD)
        );

        // The redirect always falls back to the authorised panel dashboard
        // and never mirrors the crafted target.
        $this->assertSame('/principal', $target, "Unsafe intended URL was honoured ({$label}).");
        $this->assertNotSame($intended, $target, $label);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeIntendedUrls(): array
    {
        return [
            'protocol-relative' => ['protocol-relative', '//evil.com/principal'],
            'protocol-relative, deep path' => ['protocol-relative, deep path', '///evil.com/principal'],
            'protocol-relative, panel match' => ['protocol-relative, panel match', '//evil.com/principal/reports'],
            'absolute https external' => ['absolute https external', 'https://evil.com/principal'],
            'absolute http external' => ['absolute http external', 'http://evil.com/principal'],
            'wrong port, same host' => ['wrong port, same host', 'http://localhost:8000/principal'],
            'authority-like backslash' => ['authority-like backslash', '/\\evil.com/principal'],
            'non-http scheme' => ['non-http scheme', 'javascript:alert(1)'],
            'dot-segment traversal' => ['dot-segment traversal', '/principal/../../clinic'],
            'encoded traversal' => ['encoded traversal', '/principal/%2e%2e/%2e%2e/clinic'],
            'not rooted' => ['not rooted', 'principal'],
            'empty' => ['empty', ''],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 1B-1R — already-authenticated identities at the staff login
    |--------------------------------------------------------------------------
    */

    public function test_already_authenticated_principal_is_redirected_to_their_panel(): void
    {
        $user = User::factory()->role('principal_super_admin')->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/principal');
    }

    public function test_already_authenticated_clinic_is_redirected_to_their_panel(): void
    {
        $user = User::factory()->role('tenant_admin')->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/clinic');
    }

    public function test_already_authenticated_patient_is_redirected_to_the_patient_panel(): void
    {
        $user = User::factory()->role('patient_customer')->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/patient');
    }

    public function test_already_authenticated_unknown_identity_fails_closed(): void
    {
        $user = User::factory()->create(); // role = null

        // No accessible panel: the neutral public entry point, never a panel.
        $this->actingAs($user)->get('/login')->assertRedirect('/');

        $this->actingAs($user)->get('/principal')->assertForbidden();
        $this->actingAs($user)->get('/clinic')->assertForbidden();
    }

    public function test_already_authenticated_suspended_identity_fails_closed(): void
    {
        $user = User::factory()->role('principal_super_admin')->suspended()->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/');

        $this->actingAs($user)->get('/principal')->assertForbidden();
    }
}
