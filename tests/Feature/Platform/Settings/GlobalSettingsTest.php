<?php

namespace Tests\Feature\Platform\Settings;

use App\Localization\Contracts\ResolvesLocale;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\Cpanel\CpanelConnectionProbe;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * Phase 3 — Principal "Pengaturan Global" (Global Settings).
 *
 * Proves the surface is reachable ONLY by an active principal holding
 * `platform.rbac.manage` on EVERY action (server-side, not merely hidden in the
 * UI), that the default language persists and feeds {@see ResolvesLocale}, that
 * the cPanel configuration falls back to `.env` until overridden, that the API
 * token is write-only/encrypted and NEVER returned, and that the connection
 * probe is read-only and leaks no credential.
 */
class GlobalSettingsTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    private const PATH = '/principal/global-settings';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
    }

    // ------------------------------------------------------- Access control

    public function test_only_an_active_super_admin_may_reach_every_global_settings_action(): void
    {
        $this->get(self::PATH)->assertRedirect();

        $revoked = $this->principal();
        $revoked->principalRoleAssignments()->update(['status' => 'revoked', 'revoked_at' => now()]);

        $inactive = $this->principal();
        $inactive->update(['is_active' => false]);

        /** @var list<User> $denied */
        $denied = [
            $this->principal(RoleKey::PRINCIPAL_FINANCE),
            $this->principal(RoleKey::BRAND_MANAGER),
            User::factory()->role(RoleKey::TENANT_OWNER)->create(),
            $revoked,
            $inactive,
        ];

        foreach ($denied as $actor) {
            $this->actingAs($actor)->get(self::PATH)->assertForbidden();
            $this->actingAs($actor)
                ->patch(self::PATH.'/general', ['default_locale' => 'en'])
                ->assertForbidden();
            $this->actingAs($actor)
                ->patch(self::PATH.'/hosting', ['host' => 'attacker.example'])
                ->assertForbidden();
            $this->actingAs($actor)->post(self::PATH.'/hosting/test')->assertForbidden();
        }

        $this->actingAs($this->principal())->get(self::PATH)->assertOk();
    }

    // ----------------------------------------------------------- Language

    public function test_the_default_language_is_persisted_and_consumed_by_the_locale_resolver(): void
    {
        $actor = $this->principal();

        $this->actingAs($actor)
            ->patch(self::PATH.'/general', ['system_name' => 'LBA Cloud', 'default_locale' => 'ms-MY'])
            ->assertRedirect(route('principal.tailadmin.settings.index', ['tab' => 'general']));

        $this->assertDatabaseHas('platform_settings', [
            'key' => PlatformSettings::LOCALE_KEY,
            'is_secret' => false,
        ]);

        // The single effective reader and the resolver both see the new default.
        $this->assertSame('ms-MY', app(PlatformSettings::class)->localeDefault());
        $this->assertSame('ms-MY', app(ResolvesLocale::class)->default());

        // The audit names the setting only — never a value.
        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => PlatformSettings::AUDIT_MODULE_KEY,
            'scope_key' => PlatformSettings::LOCALE_KEY,
            'actor_id' => $actor->getKey(),
        ]);
    }

    public function test_an_unsupported_default_language_is_rejected(): void
    {
        $this->actingAs($this->principal())
            ->patch(self::PATH.'/general', ['system_name' => 'LBA Cloud', 'default_locale' => 'fr'])
            ->assertSessionHasErrors('default_locale');
    }

    // ---------------------------------------------------------- System name

    public function test_the_system_name_is_persisted_and_used_as_the_effective_name(): void
    {
        $actor = $this->principal();

        // Before any override the shipped name is the effective fallback.
        $this->assertSame(config('lba.system_name'), app(PlatformSettings::class)->systemName());

        $this->actingAs($actor)
            ->patch(self::PATH.'/general', ['system_name' => 'Klinik Sehat', 'default_locale' => 'id'])
            ->assertRedirect(route('principal.tailadmin.settings.index', ['tab' => 'general']));

        $this->assertDatabaseHas('platform_settings', [
            'key' => PlatformSettings::SYSTEM_NAME_KEY,
            'is_secret' => false,
        ]);

        $settings = app(PlatformSettings::class);
        $this->assertSame('Klinik Sehat', $settings->systemName());

        // The audit names the setting only — never a value.
        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => PlatformSettings::AUDIT_MODULE_KEY,
            'scope_key' => PlatformSettings::SYSTEM_NAME_KEY,
            'actor_id' => $actor->getKey(),
        ]);

        // The new name shows in the Principal app title and header.
        $this->actingAs($actor)->get(self::PATH.'?tab=general')
            ->assertOk()
            ->assertSee('Klinik Sehat');
    }

    public function test_the_system_name_is_required(): void
    {
        $this->actingAs($this->principal())
            ->patch(self::PATH.'/general', ['system_name' => '', 'default_locale' => 'id'])
            ->assertSessionHasErrors('system_name');
    }

    // ------------------------------------------------------------- cPanel

    public function test_the_cpanel_configuration_falls_back_to_env_until_an_override_is_stored(): void
    {
        config()->set('tenancy.cpanel', [
            'enabled' => true,
            'host' => 'env.example',
            'port' => 2083,
            'username' => 'envaccount',
            'api_token' => 'env-token',
            'runtime_user' => 'env_runtime',
            'verify_tls' => true,
            'timeout' => 15,
        ]);

        $effective = app(PlatformSettings::class)->effectiveCpanel();
        $this->assertSame('env.example', $effective['host']);
        $this->assertSame('env-token', $effective['api_token']);
        $this->assertSame('env', app(PlatformSettings::class)->cpanelTokenSource());

        // Storing just the host overrides one key; the rest still come from env.
        app(PlatformSettings::class)->updateCpanel(['host' => 'stored.example'], $this->principal());

        $effective = app(PlatformSettings::class)->effectiveCpanel();
        $this->assertSame('stored.example', $effective['host']);
        $this->assertSame('envaccount', $effective['username']);
        $this->assertSame('env-token', $effective['api_token']);
        // The token is still sourced from `.env` — only the host was overridden.
        $this->assertSame('env', app(PlatformSettings::class)->cpanelTokenSource());
    }

    public function test_the_api_token_is_write_only_encrypted_and_never_returned(): void
    {
        $actor = $this->principal();
        $token = 'cpanel-super-secret-token';

        $this->actingAs($actor)->patch(self::PATH.'/hosting', [
            'enabled' => '1',
            'host' => 'appmax.viacom.id',
            'port' => 2083,
            'username' => 'appmax',
            'runtime_user' => 'lba_runtime',
            'verify_tls' => '1',
            'timeout' => 20,
            'token' => $token,
        ])->assertRedirect(route('principal.tailadmin.settings.index', ['tab' => 'hosting']));

        $row = DB::table('platform_settings')
            ->where('key', PlatformSettings::CPANEL_TOKEN)
            ->sole();

        $this->assertTrue((bool) $row->is_secret);
        $this->assertStringNotContainsString($token, (string) $row->value);

        // The token is never echoed back in the rendered settings screen.
        $this->actingAs($actor)->get(self::PATH.'?tab=hosting')
            ->assertOk()
            ->assertDontSee($token);

        $this->assertSame($token, app(PlatformSettings::class)->effectiveCpanel()['api_token']);

        // The audit trail names the setting only; no value, no metadata.
        $audit = ModuleAuditLog::query()->where('scope_key', PlatformSettings::CPANEL_TOKEN)->sole();
        $this->assertNull($audit->metadata);
        $this->assertStringNotContainsString($token, (string) json_encode($audit->getAttributes()));
    }

    public function test_a_blank_token_field_preserves_the_current_token(): void
    {
        $actor = $this->principal();

        app(PlatformSettings::class)->updateCpanel(['token' => 'keep-me'], $actor);
        app(PlatformSettings::class)->updateCpanel([
            'host' => 'changed.example',
            'token' => '',
        ], $actor);

        $effective = app(PlatformSettings::class)->effectiveCpanel();
        $this->assertSame('keep-me', $effective['api_token']);
        $this->assertSame('changed.example', $effective['host']);
    }

    public function test_the_cpanel_token_is_never_flashed_to_the_session_on_a_validation_failure(): void
    {
        $secret = 'must-not-be-flashed';

        // An out-of-range port fails validation and redirects back with the old
        // input flashed — the write-only token must NOT be among it.
        $this->actingAs($this->principal())
            ->patch(self::PATH.'/hosting', [
                'host' => 'appmax.viacom.id',
                'port' => 99999,
                'token' => $secret,
            ])
            ->assertSessionHasErrors('port');

        $old = session('_old_input');
        $this->assertIsArray($old);
        $this->assertArrayNotHasKey('token', $old);
        $this->assertStringNotContainsString($secret, (string) json_encode($old));
    }

    // -------------------------------------------------------------- Probe

    public function test_the_connection_probe_is_read_only_and_reports_no_credential_or_database_list(): void
    {
        $account = 'appmax';
        $runtimeUser = 'lba_runtime';
        $token = 'probe-secret-token';

        config()->set('tenancy.cpanel', [
            'enabled' => true,
            'host' => 'appmax.viacom.id',
            'port' => 2083,
            'username' => $account,
            'api_token' => $token,
            'runtime_user' => $runtimeUser,
            'verify_tls' => true,
            'timeout' => 15,
            'allowed_hosts' => ['appmax.viacom.id'],
            'allowed_ports' => [2083],
        ]);

        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), 'list_users') => Http::response([
                'status' => 1,
                'data' => [$account.'_'.$runtimeUser],
            ]),
            default => Http::response(['status' => 0, 'errors' => ['unexpected call']]),
        });

        $probe = app(CpanelConnectionProbe::class)->run();

        $this->assertTrue($probe['configured']);
        $this->assertTrue($probe['authenticated']);
        $this->assertTrue($probe['runtime_user_available']);
        $this->assertTrue($probe['prefix_enabled']);
        $this->assertSame($account, $probe['account']);

        // Read-only: only list_users was called — no create/drop/list_databases.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'list_users'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'create_database')
            || str_contains($request->url(), 'list_databases')
            || str_contains($request->url(), 'delete_database'));
    }

    public function test_the_probe_fails_safe_when_authentication_is_rejected(): void
    {
        config()->set('tenancy.cpanel', [
            'enabled' => true,
            'host' => 'appmax.viacom.id',
            'port' => 2083,
            'username' => 'appmax',
            'api_token' => 'probe-secret-token',
            'runtime_user' => 'lba_runtime',
            'verify_tls' => true,
            'timeout' => 15,
            'allowed_hosts' => ['appmax.viacom.id'],
            'allowed_ports' => [2083],
        ]);

        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['login failed']], 401)]);

        $probe = app(CpanelConnectionProbe::class)->run();

        $this->assertFalse($probe['authenticated']);
        $this->assertSame('authentication_failed', $probe['reason']);
        $this->assertStringNotContainsString('probe-secret-token', (string) json_encode($probe));
    }
}
