<?php

namespace Tests\Feature\Platform\Health;

use App\Models\Tenant;
use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\Models\TenantDatabaseCredential;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * WS6.F + v3.3 — the pre-deploy backup must dump each partner's clinical
 * database with THAT partner's own MANUAL MySQL credential, exactly as the
 * runtime would (App\Platform\Tenancy\TenantDatabaseManager::parametersFor).
 *
 * MySQL is unavailable in the sandbox, so the dump client is replaced by a tiny
 * script that records the `--defaults-extra-file` the command hands it. The
 * recorded credential file is the same one a real `mysqldump` AND the matching
 * restore would use, so this proves the method (which credential is selected
 * per partner) without a live server.
 */
class StagingTenantBackupCredentialTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    private string $capture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/lba-tbcred-'.bin2hex(random_bytes(6));
        $this->capture = $this->tmpDir.'/capture';
        File::ensureDirectoryExists($this->capture, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    /** A fake dump client that copies its credential file out and echoes a body. */
    private function installFakeDumpClient(): void
    {
        $script = "#!/usr/bin/env bash\n"
            ."set -e\n"
            .'CAP="'.$this->capture."\"\n"
            ."defs=\"\"\n"
            ."for a in \"\$@\"; do\n"
            ."  case \"\$a\" in --defaults-extra-file=*) defs=\"\${a#--defaults-extra-file=}\";; esac\n"
            ."done\n"
            ."db=\"\${!#}\"\n"
            ."cp \"\$defs\" \"\$CAP/\$db.defaults\"\n"
            ."printf -- '-- fake dump of %s\\n' \"\$db\"\n";

        $path = $this->tmpDir.'/fake-mysqldump';
        File::put($path, $script);
        chmod($path, 0o755);

        config()->set('lba.deploy.mysqldump_binary', $path);
    }

    /**
     * Point the tenant connection at MySQL with a SHARED runtime credential, so
     * any per-partner overlay is distinguishable from the template.
     */
    private function useMySqlTenantTemplate(): void
    {
        config()->set('tenancy.tenant_connection', 'tenant');
        config()->set('database.connections.tenant.driver', 'mysql');
        config()->set('database.connections.tenant.host', 'db.staging.internal');
        config()->set('database.connections.tenant.port', '3306');
        config()->set('database.connections.tenant.username', 'shared_runtime');
        config()->set('database.connections.tenant.password', 'shared-pass');
    }

    private function partner(string $databaseName, string $username, string $password): Tenant
    {
        $tenant = Tenant::factory()->create();

        TenantDatabase::query()->where('tenant_id', $tenant->getKey())->update([
            'configured_database_name' => $databaseName,
        ]);

        TenantDatabaseCredential::factory()->forTenant($tenant)->create([
            'username' => $username,
            'password' => $password,
        ]);

        return $tenant;
    }

    public function test_each_partner_is_dumped_with_its_own_manual_credential(): void
    {
        $this->useMySqlTenantTemplate();
        $this->installFakeDumpClient();

        app(PlatformSettings::class)->setDatabaseMode(PlatformSettings::MODE_MANUAL, null);

        $this->partner('lbacloud_tenant_alpha', 'lba_alpha', 'alpha-pass');
        $this->partner('lbacloud_tenant_beta', 'lba_beta', 'beta-pass');

        $this->artisan('lba:staging:backup', [
            '--dir' => $this->tmpDir.'/out',
            '--skip-central' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->assertSuccessful();

        $alpha = File::get($this->capture.'/lbacloud_tenant_alpha.defaults');
        $beta = File::get($this->capture.'/lbacloud_tenant_beta.defaults');

        $this->assertStringContainsString('user=lba_alpha', $alpha);
        $this->assertStringContainsString('password=alpha-pass', $alpha);

        $this->assertStringContainsString('user=lba_beta', $beta);
        $this->assertStringContainsString('password=beta-pass', $beta);

        // The shared `.env` runtime credential must NOT be used for either.
        $this->assertStringNotContainsString('shared_runtime', $alpha);
        $this->assertStringNotContainsString('shared_runtime', $beta);

        // And each partner's dump is a real, non-empty artefact.
        $this->assertCount(1, File::glob($this->tmpDir.'/out/tenants/lbacloud_tenant_alpha-*.sql'));
        $this->assertCount(1, File::glob($this->tmpDir.'/out/tenants/lbacloud_tenant_beta-*.sql'));
    }

    public function test_a_partner_without_a_stored_credential_keeps_the_shared_template(): void
    {
        $this->useMySqlTenantTemplate();
        $this->installFakeDumpClient();

        // No configured name and no credential: a legacy partner. The backup
        // resolves the target through TenantDatabaseName (never the registry's
        // stored `database_name`), so compute the expected physical name the
        // same way the command does.
        $tenant = Tenant::factory()->create();
        $physical = app(TenantDatabaseName::class)->for((string) $tenant->getKey());

        $this->artisan('lba:staging:backup', [
            '--dir' => $this->tmpDir.'/out',
            '--skip-central' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->assertSuccessful();

        $legacy = File::get($this->capture.'/'.$physical.'.defaults');

        $this->assertStringContainsString('user=shared_runtime', $legacy);
        $this->assertStringContainsString('password=shared-pass', $legacy);
    }
}
