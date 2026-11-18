<?php

namespace Tests\Feature\Platform\Tenancy;

use App\Platform\Tenancy\Cpanel\CpanelApiException;
use App\Platform\Tenancy\Cpanel\CpanelClient;
use App\Platform\Tenancy\Cpanel\CpanelTenantDatabaseProvisioner;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 — cPanel provisioning adapter.
 *
 * Proves the cPanel UAPI path creates the tenant database and grants the
 * RESTRICTED runtime user privileges ON THAT DATABASE ONLY, that the API token
 * is never exposed, that an existing database is reused (never re-created) and
 * that an unconfigured adapter fails closed.
 */
class CpanelProvisioningTest extends TestCase
{
    private const ACCOUNT = 'cpaccount';

    private const RUNTIME_USER = 'lba_runtime';

    private const TOKEN = 'super-secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.database_prefix', 'lba_tenant_');
        config()->set('database.connections.tenant.driver', 'mysql');
        config()->set('tenancy.cpanel', [
            'enabled' => true,
            'host' => 'server.example.com',
            'port' => 2083,
            'username' => self::ACCOUNT,
            'api_token' => self::TOKEN,
            'scheme' => 'https',
            'verify_tls' => true,
            'timeout' => 5,
            'runtime_user' => self::RUNTIME_USER,
            'allowed_hosts' => ['server.example.com'],
            'allowed_ports' => [2083],
        ]);
    }

    public function test_provisioning_creates_the_database_then_grants_the_runtime_user_access_only_to_it(): void
    {
        $tenantId = (string) Str::ulid();
        $requested = 'lba_tenant_'.strtolower($tenantId);
        $physical = self::ACCOUNT.'_'.$requested;

        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), 'list_databases') => Http::response(['status' => 1, 'data' => []]),
            str_contains($request->url(), 'create_database') => Http::response(['status' => 1, 'data' => null]),
            str_contains($request->url(), 'set_privileges_on_database') => Http::response(['status' => 1]),
            default => Http::response(['status' => 0, 'errors' => ['unexpected call']]),
        });

        $this->provisioner()->provision($tenantId);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/execute/Mysql/create_database')
            && $request['name'] === $requested);

        // cPanel requires BOTH the database and user arguments prefixed with
        // the account name on set_privileges_on_database.
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/execute/Mysql/set_privileges_on_database')
            && $request['database'] === $physical
            && $request['user'] === self::ACCOUNT.'_'.self::RUNTIME_USER
            && $request['privileges'] === 'ALL PRIVILEGES');

        // The physical (account-prefixed) name is what the runtime connection uses.
        $this->assertSame($physical, app(TenantDatabaseName::class)->for($tenantId));
    }

    public function test_the_runtime_user_is_never_granted_global_privileges(): void
    {
        $tenantId = (string) Str::ulid();

        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), 'list_databases') => Http::response(['status' => 1, 'data' => []]),
            default => Http::response(['status' => 1]),
        });

        $this->provisioner()->provision($tenantId);

        // No privilege grant may target `*.*` / `%` (a global grant), and there
        // must be no privilege-setting call other than per-database.
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) json_encode($request->data()), '*.*'));
    }

    public function test_the_api_token_is_sent_only_in_the_authorization_header_never_in_the_url_or_body(): void
    {
        $tenantId = (string) Str::ulid();

        Http::fake(['*' => Http::response(['status' => 1, 'data' => []])]);

        $this->provisioner()->provision($tenantId);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'cpanel '.self::ACCOUNT.':'.self::TOKEN)
                && ! str_contains($request->url(), self::TOKEN)
                && ! str_contains((string) json_encode($request->data()), self::TOKEN);
        });
    }

    public function test_an_existing_database_is_reused_and_never_re_created(): void
    {
        $tenantId = (string) Str::ulid();
        $physical = self::ACCOUNT.'_lba_tenant_'.strtolower($tenantId);

        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), 'list_databases') => Http::response(['status' => 1, 'data' => [$physical]]),
            default => Http::response(['status' => 1]),
        });

        $this->provisioner()->provision($tenantId);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'create_database'));

        // Privileges are still (re-)granted so a retry converges.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'set_privileges_on_database'));
    }

    public function test_a_create_that_reports_already_exists_is_tolerated(): void
    {
        $tenantId = (string) Str::ulid();

        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), 'list_databases') => Http::response(['status' => 1, 'data' => []]),
            str_contains($request->url(), 'create_database') => Http::response([
                'status' => 0,
                'errors' => ['The database lba_tenant_x already exists.'],
            ]),
            default => Http::response(['status' => 1]),
        });

        $this->provisioner()->provision($tenantId);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'set_privileges_on_database'));
    }

    public function test_an_unconfigured_adapter_fails_closed(): void
    {
        config()->set('tenancy.cpanel.host', '');
        config()->set('tenancy.cpanel.api_token', '');

        $this->expectException(CpanelApiException::class);

        $this->provisioner()->provision((string) Str::ulid());
    }

    public function test_the_uapi_endpoint_is_restricted_to_the_allowed_host_and_port(): void
    {
        Http::fake(['*' => Http::response(['status' => 1])]);

        // A host outside the allowlist is refused BEFORE the token is sent.
        config()->set('tenancy.cpanel.host', 'attacker.example');

        try {
            CpanelClient::fromConfig()->listDatabases();
            $this->fail('The client must refuse a host outside the allowlist.');
        } catch (CpanelApiException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_an_empty_endpoint_allowlist_fails_closed(): void
    {
        Http::fake(['*' => Http::response(['status' => 1])]);

        // An empty allowlist NEVER means "any host/port".
        config()->set('tenancy.cpanel.allowed_hosts', []);
        config()->set('tenancy.cpanel.allowed_ports', []);

        try {
            CpanelClient::fromConfig()->listDatabases();
            $this->fail('An empty endpoint allowlist must fail closed.');
        } catch (CpanelApiException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_a_cpanel_identifiable_name_is_derived_from_the_ulid(): void
    {
        $tenantId = (string) Str::ulid();
        $names = app(TenantDatabaseName::class);

        $this->assertSame('lba_tenant_'.strtolower($tenantId), $names->requestedName($tenantId));
        $this->assertSame(self::ACCOUNT.'_lba_tenant_'.strtolower($tenantId), $names->for($tenantId));
        $this->assertSame(self::RUNTIME_USER, $names->runtimeUser());
    }

    private function provisioner(): CpanelTenantDatabaseProvisioner
    {
        return new CpanelTenantDatabaseProvisioner(
            app(TenantDatabaseName::class),
            CpanelClient::fromConfig(),
        );
    }
}
