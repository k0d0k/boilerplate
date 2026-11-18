<?php

namespace Tests\Feature\Platform\Health;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * WS6.F — the read-only pre-deploy backup command (central source, key, media).
 *
 * Proves the backup captures the central database, the application key and
 * private media; that it fails CLOSED on a missing or unsupported source; that
 * dry-run writes nothing; and that it never prints a credential.
 *
 * These cases deliberately swap the DEFAULT connection, so the class must NOT
 * use RefreshDatabase (whose rollback would resolve the swapped connection).
 * The registry-backed tenant case lives in {@see StagingTenantBackupCommandTest}.
 */
class StagingBackupCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/lba-bk-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tmpDir, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    public function test_it_copies_the_central_sqlite_database(): void
    {
        $central = $this->tmpDir.'/central.sqlite';
        File::put($central, 'SQLITE-CENTRAL-SENTINEL-CONTENT');

        config()->set('database.connections.probe_central', [
            'driver' => 'sqlite',
            'database' => $central,
        ]);
        config()->set('database.default', 'probe_central');

        $dir = $this->tmpDir.'/out';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-tenants' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->expectsOutputToContain('Backup complete')->assertSuccessful();

        $files = File::glob($dir.'/central-*.sqlite');

        $this->assertCount(1, $files);
        $this->assertSame('SQLITE-CENTRAL-SENTINEL-CONTENT', File::get($files[0]));
    }

    public function test_it_fails_closed_when_the_central_sqlite_file_is_missing(): void
    {
        config()->set('database.connections.probe_central', [
            'driver' => 'sqlite',
            'database' => $this->tmpDir.'/does-not-exist.sqlite',
        ]);
        config()->set('database.default', 'probe_central');

        $this->artisan('lba:staging:backup', [
            '--dir' => $this->tmpDir.'/out',
            '--skip-tenants' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->expectsOutputToContain('do NOT deploy')->assertExitCode(1);
    }

    public function test_it_fails_closed_on_an_unsupported_driver(): void
    {
        config()->set('database.connections.probe_unsupported', [
            'driver' => 'pgsql',
            'database' => 'whatever',
        ]);
        config()->set('database.default', 'probe_unsupported');

        $this->artisan('lba:staging:backup', [
            '--dir' => $this->tmpDir.'/out',
            '--skip-tenants' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->expectsOutputToContain('Unsupported')->assertExitCode(1);
    }

    public function test_it_backs_up_the_encryption_key_owner_only(): void
    {
        config()->set('app.key', 'base64:SENTINEL_APP_KEY_VALUE_AAAAAAAAAAAAAAAAAAA=');

        $dir = $this->tmpDir.'/out';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-central' => true,
            '--skip-tenants' => true,
            '--skip-media' => true,
        ])->assertSuccessful();

        $files = File::glob($dir.'/keys/app-key-*.key');

        $this->assertCount(1, $files);
        $this->assertSame('base64:SENTINEL_APP_KEY_VALUE_AAAAAAAAAAAAAAAAAAA=', trim(File::get($files[0])));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
    }

    public function test_it_archives_private_media(): void
    {
        $media = $this->tmpDir.'/media';
        File::ensureDirectoryExists($media.'/lba', 0700);
        File::put($media.'/lba/original.jpg', 'MICROSCOPY-BYTES');
        config()->set('lba.deploy.backup_media_path', $media);

        $dir = $this->tmpDir.'/out';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-central' => true,
            '--skip-tenants' => true,
            '--skip-keys' => true,
        ])->expectsOutputToContain('private media')->assertSuccessful();

        $files = File::glob($dir.'/media-*.tar.gz');

        $this->assertCount(1, $files);
        $this->assertGreaterThan(0, filesize($files[0]));
    }

    public function test_dry_run_writes_nothing(): void
    {
        config()->set('app.key', 'base64:SENTINEL_APP_KEY_VALUE_AAAAAAAAAAAAAAAAAAA=');

        $dir = $this->tmpDir.'/out';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--dry-run' => true,
            '--skip-tenants' => true,
        ])->expectsOutputToContain('Dry run completed')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_it_emits_a_machine_readable_manifest(): void
    {
        config()->set('app.key', 'base64:SENTINEL_APP_KEY_VALUE_AAAAAAAAAAAAAAAAAAA=');

        $this->artisan('lba:staging:backup', [
            '--dir' => $this->tmpDir.'/out',
            '--json' => true,
            '--skip-central' => true,
            '--skip-tenants' => true,
            '--skip-media' => true,
        ])->assertSuccessful();
    }

    public function test_it_never_prints_secrets(): void
    {
        config()->set('app.key', 'base64:SENTINEL_APP_KEY_VALUE_AAAAAAAAAAAAAAAAAAA=');
        config()->set('database.connections.probe_secret', [
            'driver' => 'sqlite',
            'database' => $this->tmpDir.'/secret.sqlite',
            'password' => 'SENTINEL_DB_PASSWORD',
        ]);
        config()->set('database.default', 'probe_secret');
        File::put($this->tmpDir.'/secret.sqlite', 'C');

        $this->artisan('lba:staging:backup', [
            '--dir' => $this->tmpDir.'/out',
            '--skip-tenants' => true,
            '--skip-media' => true,
        ])
            ->doesntExpectOutputToContain('SENTINEL_DB_PASSWORD')
            ->doesntExpectOutputToContain('SENTINEL_APP_KEY_VALUE')
            ->assertSuccessful();
    }

    public function test_it_refuses_to_write_a_backup_inside_the_public_directory(): void
    {
        $dir = public_path().'/deploy-backups';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-tenants' => true,
        ])->expectsOutputToContain('web-served')->assertExitCode(1);

        // Fail closed: nothing may be created in a web-served location.
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_it_refuses_a_web_served_path_segment(): void
    {
        $dir = $this->tmpDir.'/www/backups';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-tenants' => true,
        ])->expectsOutputToContain('web-served')->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_it_streams_a_large_mysql_dump_without_buffering_in_php_memory(): void
    {
        $bytes = 24 * 1024 * 1024; // 24 MiB — larger than any sane PHP buffer.
        $binary = $this->fakeBinary(
            '#!/usr/bin/env bash'."\n"
            ."head -c {$bytes} /dev/zero | tr '\\0' 'Z'\n"
        );

        $this->configureMysqlTarget($binary);

        $dir = $this->tmpDir.'/out';

        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-tenants' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->expectsOutputToContain('Backup complete')->assertSuccessful();

        $peakDelta = memory_get_peak_usage(true) - $baseline;

        $files = File::glob($dir.'/central-*.sql');
        $this->assertCount(1, $files);
        $this->assertSame($bytes, filesize($files[0]));

        // The whole payload must be present and intact…
        $handle = fopen($files[0], 'rb');
        $first = (string) fread($handle, 16);
        fseek($handle, -16, SEEK_END);
        $last = (string) fread($handle, 16);
        fclose($handle);

        $this->assertSame(str_repeat('Z', 16), $first);
        $this->assertSame(str_repeat('Z', 16), $last);

        // …without the dump ever entering PHP memory (a buffering implementation
        // would add the full payload to the peak).
        $this->assertLessThan(
            16 * 1024 * 1024,
            $peakDelta,
            'The MySQL dump appears to have been buffered in PHP memory.',
        );
    }

    public function test_it_fails_closed_and_removes_a_partial_dump(): void
    {
        $binary = $this->fakeBinary(
            '#!/usr/bin/env bash'."\n"
            ."printf 'TRUNCATED-DUMP-PARTIAL'\n"
            ."exit 3\n"
        );

        $this->configureMysqlTarget($binary);

        $dir = $this->tmpDir.'/out';

        $this->artisan('lba:staging:backup', [
            '--dir' => $dir,
            '--skip-tenants' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])->expectsOutputToContain('mysqldump failed')->assertExitCode(1);

        // A partial dump must never survive to look like a valid artefact.
        $this->assertSame([], File::glob($dir.'/central-*.sql'));
    }

    public function test_it_redacts_the_password_from_a_dump_diagnostic(): void
    {
        $binary = $this->fakeBinary(
            '#!/usr/bin/env bash'."\n"
            ."echo 'access denied for SENTINEL_DB_PASSWORD' >&2\n"
            ."exit 1\n"
        );

        $this->configureMysqlTarget($binary);

        $this->artisan('lba:staging:backup', [
            '--dir' => $this->tmpDir.'/out',
            '--skip-tenants' => true,
            '--skip-keys' => true,
            '--skip-media' => true,
        ])
            ->doesntExpectOutputToContain('SENTINEL_DB_PASSWORD')
            ->expectsOutputToContain('mysqldump failed')
            ->assertExitCode(1);
    }

    private function configureMysqlTarget(string $binary): void
    {
        config()->set('database.connections.probe_mysql', [
            'driver' => 'mysql',
            'database' => 'lba_central',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'lba_dump_user',
            'password' => 'SENTINEL_DB_PASSWORD',
        ]);
        config()->set('database.default', 'probe_mysql');
        config()->set('lba.deploy.mysqldump_binary', $binary);
    }

    private function fakeBinary(string $body): string
    {
        $path = $this->tmpDir.'/fake-mysqldump-'.bin2hex(random_bytes(4));
        File::put($path, $body);
        chmod($path, 0755);

        return $path;
    }
}
