<?php

namespace Tests\Feature\Platform\Health;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * WS6.F — the registry-backed tenant branch of the pre-deploy backup.
 *
 * Proves that every ALREADY REGISTERED (provisioned/migrated) tenant clinical
 * database is dumped, and that a pending/dropped registry row is ignored. The
 * central connection is the in-memory test database, so only the tenant branch
 * is exercised here.
 */
class StagingTenantBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/lba-tbk-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tmpDir, 0700);

        // Keep every tenant SQLite file inside the isolated temp directory.
        config()->set('tenancy.sqlite_path', $this->tmpDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    public function test_it_backs_up_every_registered_tenant_database(): void
    {
        // TenantFactory registers a migrated TenantDatabase row automatically.
        $tenant = Tenant::factory()->create();

        File::put(
            $this->tmpDir.'/'.strtolower($tenant->getKey()).'.sqlite',
            'TENANT-CLINICAL-SENTINEL',
        );

        $dir = $this->tmpDir.'/out';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-central' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->expectsOutputToContain('tenant')->assertSuccessful();

        $files = File::glob($dir.'/tenants/*.sqlite');

        $this->assertCount(1, $files);
        $this->assertSame('TENANT-CLINICAL-SENTINEL', File::get($files[0]));
    }

    public function test_it_ignores_unregistered_tenant_databases(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();

        File::put(
            $this->tmpDir.'/'.strtolower($tenant->getKey()).'.sqlite',
            'NOT-REGISTERED',
        );

        $dir = $this->tmpDir.'/out';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-central' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->expectsOutputToContain('No registered tenant databases')->assertSuccessful();

        $this->assertSame([], File::glob($dir.'/tenants/*.sqlite'));
    }
}
