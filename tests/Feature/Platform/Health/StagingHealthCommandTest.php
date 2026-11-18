<?php

namespace Tests\Feature\Platform\Health;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WS5 — the read-only staging health/diagnostic command.
 *
 * Proves the command reports the deployment posture, is safe to run on staging
 * (never prints a credential, an identity or any clinical value) and gates a
 * release by exiting non-zero on a blocking finding.
 */
class StagingHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_staging_posture_and_succeeds(): void
    {
        $this->artisan('lba:staging:health')
            ->expectsOutputToContain('Central database')
            ->expectsOutputToContain('Tenant databases (registry)')
            ->expectsOutputToContain('Registered modules')
            ->expectsOutputToContain('Localization audit')
            ->expectsOutputToContain('Staging health: OK')
            ->assertSuccessful();
    }

    public function test_it_reports_payments_as_sandbox_only(): void
    {
        $this->artisan('lba:staging:health')
            ->expectsOutputToContain('sandbox-only')
            ->assertSuccessful();
    }

    public function test_it_fails_closed_when_the_application_key_is_missing(): void
    {
        config()->set('app.key', null);

        $this->artisan('lba:staging:health')
            ->expectsOutputToContain('Staging health: 1 blocking finding')
            ->assertExitCode(1);
    }

    public function test_it_never_prints_credentials_or_identities(): void
    {
        config()->set('app.key', 'base64:SENTINEL_APP_KEY_VALUE');

        $connection = (string) config('database.default');
        config()->set("database.connections.{$connection}.password", 'SENTINEL_DB_PASSWORD');

        Tenant::factory()->create(['name' => 'SENTINEL CLINIC NAME']);

        $this->artisan('lba:staging:health')
            ->doesntExpectOutputToContain('SENTINEL_APP_KEY_VALUE')
            ->doesntExpectOutputToContain('SENTINEL_DB_PASSWORD')
            ->doesntExpectOutputToContain('SENTINEL CLINIC NAME')
            ->assertSuccessful();
    }

    public function test_it_emits_machine_readable_json(): void
    {
        $this->artisan('lba:staging:health', ['--json' => true])
            ->assertSuccessful();
    }
}
