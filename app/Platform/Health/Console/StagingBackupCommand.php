<?php

namespace App\Platform\Health\Console;

use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseManager;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * WS6.F — read-only pre-deploy backup for the staging host.
 *
 * Before a deploy touches a schema, an operator must be able to restore what
 * existed. This command captures, into a single timestamped directory:
 *
 *   - a logical dump of the CENTRAL database (the platform registry);
 *   - a logical dump of every ALREADY REGISTERED tenant clinical database
 *     (provisioned/migrated only — never a pending, failed or dropped one);
 *   - a copy of the application encryption key (`APP_KEY`), so encrypted
 *     columns remain recoverable;
 *   - a compressed archive of the PRIVATE user-uploaded media (microscopy
 *     originals and generated reports) from `storage/app/private`.
 *
 * SAFETY (do not weaken):
 *   * It NEVER writes to any database — every statement is a read (dump/copy).
 *   * It NEVER prints a credential: only artefact kinds, paths and sizes.
 *   * It fails CLOSED — a missing/empty artefact or an unreachable database
 *     makes the command exit non-zero so the deploy halts before `migrate`.
 *   * Secrets never enter Git: the default target is `storage/app/deploy-backups`
 *     (git-ignored) and every artefact is written owner-only (0600) under a
 *     0700 directory.
 */
class StagingBackupCommand extends Command
{
    protected $signature = 'lba:staging:backup
        {--dir= : Target directory (default: storage/app/deploy-backups)}
        {--skip-central : Do not dump the central database}
        {--skip-tenants : Do not dump registered tenant databases}
        {--skip-keys : Do not copy the application encryption key}
        {--skip-media : Do not archive private user-uploaded media}
        {--dry-run : Report the plan without writing anything}
        {--json : Emit the manifest as machine-readable JSON}';

    protected $description = 'Read-only pre-deploy backup (central + tenant databases, key, private media); never prints credentials.';

    /** @var list<array{kind: string, path: string, bytes: int}> */
    private array $manifest = [];

    /** @var list<string> */
    private array $failures = [];

    /**
     * Directory names a web server commonly exposes. A backup target under any
     * of these segments is refused — clinical dumps and the encryption key must
     * never be reachable over HTTP.
     *
     * @var list<string>
     */
    private const WEB_SERVED_SEGMENTS = ['public', 'public_html', 'www', 'wwwroot', 'htdocs', 'webroot'];

    public function handle(TenantDatabaseName $names, TenantDatabaseManager $tenants): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $dir = rtrim((string) ($this->option('dir') ?: storage_path('app/deploy-backups')), '/');
        $stamp = gmdate('Ymd\THis\Z');

        // A backup contains clinical dumps and the encryption key: it must never
        // be written anywhere a web server could serve it.
        try {
            $this->assertPrivateDirectory($dir);
        } catch (Throwable $e) {
            $this->recordFailure($e->getMessage());

            return self::FAILURE;
        }

        if (! (bool) $this->option('json')) {
            $this->line("LBA staging backup — target: {$dir}".($dryRun ? ' (dry-run)' : ''));
        }

        if (! $dryRun) {
            File::ensureDirectoryExists($dir, 0700);
        }

        if (! (bool) $this->option('skip-central')) {
            $this->safely('central', fn () => $this->backupCentral($dir, $stamp, $dryRun));
        }

        if (! (bool) $this->option('skip-tenants')) {
            $this->safely('tenants', fn () => $this->backupTenants($dir, $stamp, $dryRun, $names, $tenants));
        }

        if (! (bool) $this->option('skip-keys')) {
            $this->safely('key', fn () => $this->backupKey($dir, $stamp, $dryRun));
        }

        if (! (bool) $this->option('skip-media')) {
            $this->safely('media', fn () => $this->backupMedia($dir, $stamp, $dryRun));
        }

        $this->render($dir, $dryRun);

        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------- Sections

    private function backupCentral(string $dir, string $stamp, bool $dryRun): void
    {
        $config = $this->connectionConfig((string) config('database.default'));

        if ($config['driver'] === 'sqlite') {
            $database = $config['database'];

            if ($database === '' || $database === ':memory:') {
                $this->line('Central database is in-memory — nothing to dump.');

                return;
            }

            if (! is_file($database)) {
                throw new RuntimeException('Central SQLite database file not found.');
            }

            $target = "{$dir}/central-{$stamp}.sqlite";

            if ($dryRun) {
                $this->plan('central', $target);

                return;
            }

            File::ensureDirectoryExists($dir, 0700);

            if (! @copy($database, $target)) {
                throw new RuntimeException('Failed to copy the central SQLite database.');
            }

            @chmod($target, 0600);
            $this->record('central', $target);

            return;
        }

        if (in_array($config['driver'], ['mysql', 'mariadb'], true)) {
            $target = "{$dir}/central-{$stamp}.sql";

            if ($dryRun) {
                $this->plan('central', $target);

                return;
            }

            $this->mysqldump($config, $config['database'], $target);
            $this->record('central', $target);

            return;
        }

        throw new RuntimeException("Unsupported central database driver [{$config['driver']}] for backup.");
    }

    private function backupTenants(string $dir, string $stamp, bool $dryRun, TenantDatabaseName $names, TenantDatabaseManager $tenants): void
    {
        try {
            $rows = TenantDatabase::query()
                ->whereIn('status', [TenantDatabase::STATUS_PROVISIONED, TenantDatabase::STATUS_MIGRATED])
                ->orderBy('tenant_id')
                ->get();
        } catch (Throwable) {
            throw new RuntimeException('The central tenant database registry is unreachable.');
        }

        if ($rows->isEmpty()) {
            $this->line('No registered tenant databases to back up.');

            return;
        }

        $tenantDir = "{$dir}/tenants";
        $config = $this->connectionConfig((string) config('tenancy.tenant_connection'));

        if (! $dryRun) {
            File::ensureDirectoryExists($tenantDir, 0700);
        }

        foreach ($rows as $row) {
            $tenantId = (string) $row->tenant_id;

            // One tenant's failure must not abort the others, but it MUST be
            // recorded so the command still exits non-zero (fail-closed).
            try {
                $physical = $names->for($tenantId);
            } catch (Throwable) {
                $this->recordFailure("Cannot resolve the physical database name for tenant [{$tenantId}].");

                continue;
            }

            // A partner in MANUAL mode owns its OWN restricted MySQL user, so the
            // dump must authenticate as THAT partner — mirroring the runtime —
            // not the shared `.env` tenant credential. A partner without a
            // stored credential keeps the template unchanged.
            $tenantConfig = $this->tenantDumpConfig($tenants, $tenantId, $config);

            $extension = $tenantConfig['driver'] === 'sqlite' ? 'sqlite' : 'sql';
            // For SQLite the physical target is a filesystem path, so only its
            // basename is safe as a filename component.
            $label = $tenantConfig['driver'] === 'sqlite' ? basename($physical) : $physical;
            $target = "{$tenantDir}/{$label}-{$stamp}.{$extension}";

            if ($dryRun) {
                $this->plan("tenant {$physical}", $target);

                continue;
            }

            try {
                if ($tenantConfig['driver'] === 'sqlite') {
                    if (! is_file($physical)) {
                        throw new RuntimeException('SQLite file not found.');
                    }

                    if (! @copy($physical, $target)) {
                        throw new RuntimeException('Copy failed.');
                    }

                    @chmod($target, 0600);
                } elseif (in_array($tenantConfig['driver'], ['mysql', 'mariadb'], true)) {
                    $this->mysqldump($tenantConfig, $physical, $target);
                } else {
                    throw new RuntimeException("Unsupported tenant driver [{$tenantConfig['driver']}].");
                }

                $this->record("tenant {$physical}", $target);
            } catch (Throwable $e) {
                $this->recordFailure("Tenant [{$tenantId}] backup failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * The connection the dump must authenticate with for THIS partner: the
     * shared template with the partner's own MANUAL credential overlaid (see
     * {@see TenantDatabaseManager::parametersFor()}). Overlays only the
     * credential/host fields, so the driver and target semantics stay the
     * template's; a partner with no stored credential is dumped exactly as
     * before.
     *
     * @param  array{driver: string, database: string, host: string, port: string, username: string, password: string}  $base
     * @return array{driver: string, database: string, host: string, port: string, username: string, password: string}
     */
    private function tenantDumpConfig(TenantDatabaseManager $tenants, string $tenantId, array $base): array
    {
        try {
            $effective = $tenants->parametersFor($tenantId);
        } catch (Throwable) {
            return $base;
        }

        foreach (['host', 'port', 'username', 'password'] as $key) {
            $value = $effective[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function backupKey(string $dir, string $stamp, bool $dryRun): void
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set — cannot back up the encryption key.');
        }

        $target = "{$dir}/keys/app-key-{$stamp}.key";

        if ($dryRun) {
            $this->plan('encryption key', $target);

            return;
        }

        $keyDir = "{$dir}/keys";
        File::ensureDirectoryExists($keyDir, 0700);
        File::put($target, $key."\n");
        File::chmod($target, 0600);
        $this->record('encryption key', $target);
    }

    private function backupMedia(string $dir, string $stamp, bool $dryRun): void
    {
        $source = rtrim((string) config('lba.deploy.backup_media_path', storage_path('app/private')), '/');

        if (! File::isDirectory($source) || File::glob($source.'/*') === []) {
            $this->line('No private media to archive.');

            return;
        }

        $target = "{$dir}/media-{$stamp}.tar.gz";

        if ($dryRun) {
            $this->plan('private media', $target);

            return;
        }

        $result = Process::timeout(3600)->run([
            'tar', '-czf', $target, '-C', storage_path('app'), 'private',
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Private media archive failed (tar exit '.$result->exitCode().').');
        }

        @chmod($target, 0600);
        $this->record('private media', $target);
    }

    // --------------------------------------------------------------- Support

    /**
     * @return array{driver: string, database: string, host: string, port: string, username: string, password: string}
     */
    private function connectionConfig(string $name): array
    {
        $connection = config("database.connections.{$name}", []);

        return [
            'driver' => (string) ($connection['driver'] ?? ''),
            'database' => (string) ($connection['database'] ?? ''),
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (string) ($connection['port'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
        ];
    }

    /**
     * Dump a MySQL database to a private file WITHOUT buffering the dump.
     *
     * A clinical database can be many gigabytes. The dump's standard output is
     * wired straight to the destination file descriptor and handed to the child
     * process by the operating system, so the payload never enters PHP memory.
     * Standard error is captured to a small bounded temp file (never a pipe we
     * have to drain in memory) so a failure can be reported without leaking the
     * credential or a huge diagnostic.
     *
     * Fails closed: a non-zero exit, an unreadable target or an empty result
     * aborts the backup, and any partial file is removed so a truncated dump can
     * never masquerade as a good one.
     *
     * @param  array{driver: string, database: string, host: string, port: string, username: string, password: string}  $config
     */
    private function mysqldump(array $config, string $database, string $target): void
    {
        $binary = trim((string) config('lba.deploy.mysqldump_binary', 'mysqldump'));

        if ($binary === '') {
            throw new RuntimeException('No mysqldump binary is configured.');
        }

        // Credentials go through a 0600 defaults-file, never the process list.
        $defaults = tempnam(sys_get_temp_dir(), 'lba-dump-');

        if ($defaults === false) {
            throw new RuntimeException('Could not create a temporary credentials file for the dump.');
        }

        @chmod($defaults, 0600);

        $stderr = tempnam(sys_get_temp_dir(), 'lba-dump-err-');

        if ($stderr === false) {
            @unlink($defaults);

            throw new RuntimeException('Could not create a temporary error file for the dump.');
        }

        @chmod($stderr, 0600);

        try {
            $contents = "[client]\n"
                .'host='.$config['host']."\n";

            if ($config['port'] !== '') {
                $contents .= 'port='.$config['port']."\n";
            }

            $contents .= 'user='.$config['username']."\n"
                .'password='.$config['password']."\n";

            File::put($defaults, $contents);

            $out = @fopen($target, 'wb');

            if ($out === false) {
                throw new RuntimeException("Could not open the backup target for writing: {$target}");
            }

            @chmod($target, 0600);

            $err = @fopen($stderr, 'wb');

            if ($err === false) {
                fclose($out);

                throw new RuntimeException('Could not open the temporary error file for writing.');
            }

            $exit = $this->runStreamingDump($binary, $defaults, $database, $out, $err);

            fclose($out);
            fclose($err);

            if ($exit !== 0) {
                // Never leave a truncated dump behind: it would look like a
                // valid artefact to a later `record()`/restore.
                if (is_file($target)) {
                    @unlink($target);
                }

                throw new RuntimeException('mysqldump failed (exit '.$exit.').'.$this->dumpDiagnostic($stderr, $config['password']));
            }
        } finally {
            foreach ([$defaults, $stderr] as $temp) {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
        }
    }

    /**
     * Spawn the dump client with its stdout/stderr bound to real file handles so
     * the OS streams the payload; return the process exit code.
     *
     * @param  resource  $out
     * @param  resource  $err
     */
    private function runStreamingDump(string $binary, string $defaults, string $database, $out, $err): int
    {
        $descriptors = [
            0 => ['pipe', 'r'], // the client needs no stdin; closed immediately
            1 => $out,
            2 => $err,
        ];

        $process = @proc_open([
            $binary,
            '--defaults-extra-file='.$defaults,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            $database,
        ], $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the mysqldump process.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        return proc_close($process);
    }

    /**
     * A short, sanitised diagnostic from the client's stderr — never the whole
     * (possibly huge) stream and never the database password.
     *
     * @param  array{driver: string, database: string, host: string, port: string, username: string, password: string}  $config
     */
    private function dumpDiagnostic(string $stderrFile, string $password): string
    {
        $message = trim((string) @file_get_contents($stderrFile));

        if ($message === '') {
            return '';
        }

        if ($password !== '') {
            $message = str_replace($password, '[redacted]', $message);
        }

        if (strlen($message) > 500) {
            $message = substr($message, 0, 500).'…';
        }

        return ' '.$message;
    }

    /**
     * Refuse a backup target that a web server could serve over HTTP.
     *
     * A backup holds clinical SQL dumps and the application encryption key, so
     * both the resolved Laravel `public` directory and any recognised
     * web-root path segment (`public`, `public_html`, `www`, …) are rejected.
     */
    private function assertPrivateDirectory(string $dir): void
    {
        $resolved = $this->resolvePath($dir);

        foreach ($this->webServedRoots() as $root) {
            if ($this->isWithin($resolved, $root)) {
                throw new RuntimeException("Refusing to write a backup inside the web-served directory [{$root}].");
            }
        }

        foreach (explode('/', $resolved) as $segment) {
            if (in_array(strtolower($segment), self::WEB_SERVED_SEGMENTS, true)) {
                throw new RuntimeException("Refusing to write a backup into a web-served path segment [{$segment}].");
            }
        }

        if (file_exists($dir) && ! is_dir($dir)) {
            throw new RuntimeException("The backup target exists and is not a directory: {$dir}");
        }
    }

    /**
     * Resolve a path to an absolute, slash-normalised form, following symlinks
     * for the part of the path that already exists.
     */
    private function resolvePath(string $path): string
    {
        $real = realpath($path);

        if ($real === false) {
            $parent = realpath(dirname($path));
            $real = $parent === false ? $path : $parent.'/'.basename($path);
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    /**
     * @return list<string>
     */
    private function webServedRoots(): array
    {
        $roots = [public_path()];

        foreach (['public_html', 'www'] as $sibling) {
            $candidate = base_path($sibling);

            if (is_dir($candidate)) {
                $roots[] = $candidate;
            }
        }

        return array_map(fn (string $root): string => $this->resolvePath($root), $roots);
    }

    private function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function safely(string $kind, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->recordFailure("{$kind} backup failed: {$e->getMessage()}");
        }
    }

    private function record(string $kind, string $path): void
    {
        clearstatcache(true, $path);

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException("Backup artefact [{$kind}] is missing or empty: {$path}");
        }

        $bytes = (int) filesize($path);
        $this->manifest[] = ['kind' => $kind, 'path' => $path, 'bytes' => $bytes];
        $this->info(sprintf('%s: %s (%s)', $kind, $path, $this->human($bytes)));
    }

    private function plan(string $kind, string $path): void
    {
        $this->line("[dry-run] would back up {$kind} -> {$path}");
    }

    private function recordFailure(string $message): void
    {
        $this->failures[] = $message;
        $this->error($message);
    }

    private function render(string $dir, bool $dryRun): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $this->failures === [],
                'dry_run' => $dryRun,
                'directory' => $dir,
                'artefacts' => $this->manifest,
                'failures' => $this->failures,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($dryRun) {
            $this->line('Dry run completed — nothing was written.');

            return;
        }

        if ($this->failures === []) {
            $this->info('Backup complete: '.count($this->manifest).' artefact(s) in '.$dir.'.');

            return;
        }

        $this->error('Backup FAILED — '.count($this->failures).' error(s); do NOT deploy.');
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf('%.1f %s', $value, $units[$index]);
    }
}
