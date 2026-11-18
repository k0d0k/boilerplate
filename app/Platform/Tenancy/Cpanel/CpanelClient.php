<?php

namespace App\Platform\Tenancy\Cpanel;

use App\Platform\Settings\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Minimal, server-side-only cPanel UAPI client for MySQL provisioning.
 *
 * It wraps the UAPI calls the platform needs to create a tenant's isolated
 * database and grant the RESTRICTED runtime user privileges on that database
 * alone:
 *
 *   - Mysql::list_databases            (detect an existing database, reuse safely)
 *   - Mysql::list_users                (verify the runtime user exists; read-only)
 *   - Mysql::create_database           (create the tenant database)
 *   - Mysql::set_privileges_on_database(grant the runtime user access to it)
 *   - Mysql::delete_database           (local/testing only; see the provisioner)
 *
 * SECURITY CONTRACT
 *  - The cPanel account username and API token are read from configuration
 *    (environment) and are never surfaced in an exception, a log line or an
 *    HTTP response. Only cPanel's own error text is propagated.
 *  - This client is NEVER granted global CREATE/USER privileges: cPanel owns
 *    the server and grants the runtime user privileges ON THE TENANT DATABASE
 *    ONLY, so the Laravel runtime credential can never create arbitrary
 *    schemas or escalate.
 *  - PREFIX CONTRACT: cPanel prepends the account username to database names.
 *    `create_database` therefore takes the UNPREFIXED name (cPanel adds the
 *    prefix server-side), but `set_privileges_on_database` requires BOTH its
 *    `database` and `user` arguments ACCOUNT-PREFIXED (`<account>_<name>`).
 *    The caller supplies the already-prefixed values; this client passes them
 *    through unchanged.
 *  - TLS is MANDATORY and the endpoint is restricted to an explicit host/port
 *    ALLOWLIST, and HTTP redirects are REFUSED, so the Authorization header
 *    (the token) can never be sent in the clear or replayed to another host.
 */
final class CpanelClient
{
    /**
     * @param  array<string, mixed>  $config  the `tenancy.cpanel` configuration block
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        // Resolve through the single effective-configuration reader so persisted
        // Global Settings override the `.env` values for web, Artisan and queue.
        return new self(app(PlatformSettings::class)->effectiveCpanel());
    }

    /**
     * Whether the client has the host, account and token it needs to run.
     */
    public function isConfigured(): bool
    {
        return $this->host() !== ''
            && $this->username() !== ''
            && $this->token() !== '';
    }

    /**
     * The full (account-prefixed) database names that already exist on the
     * server. Used to detect an existing tenant database and reuse it rather
     * than re-creating it idempotently.
     *
     * @return list<string>
     */
    public function listDatabases(): array
    {
        $result = $this->call('Mysql', 'list_databases', []);

        $data = $result['data'] ?? [];

        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter($data, 'is_string'));
        }

        return [];
    }

    public function databaseExists(string $physicalName): bool
    {
        return in_array($physicalName, $this->listDatabases(), true);
    }

    /**
     * Create a tenant database. `$name` is the UNPREFIXED database name; cPanel
     * prepends the account username and owns the privileged DDL.
     *
     * Idempotent: a "database already exists" response is treated as success so
     * a retry can never fail merely because the database survived a prior run.
     */
    public function createDatabase(string $name): void
    {
        $this->call('Mysql', 'create_database', ['name' => $name], tolerateAlreadyExists: true);
    }

    /**
     * The ACCOUNT-PREFIXED MySQL user names that exist on the server. Used to
     * confirm the restricted runtime user is available before provisioning.
     *
     * @return list<string>
     */
    public function listUsers(): array
    {
        $result = $this->call('Mysql', 'list_users', []);

        $data = $result['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        $users = [];

        foreach ($data as $entry) {
            // UAPI has historically returned either bare strings or objects
            // carrying a `user` key; accept both without assuming a shape.
            $user = is_string($entry)
                ? $entry
                : (is_array($entry) ? ($entry['user'] ?? $entry['name'] ?? null) : null);

            if (is_string($user) && $user !== '') {
                $users[] = $user;
            }
        }

        return array_values(array_unique($users));
    }

    public function hasUser(string $prefixedUser): bool
    {
        return in_array($prefixedUser, $this->listUsers(), true);
    }

    /**
     * Grant the restricted runtime user privileges ON ONE DATABASE. Never a
     * global grant: the privileges are scoped to `$database` only.
     *
     * BOTH `$database` and `$user` MUST be ACCOUNT-PREFIXED
     * (`<account>_<name>`) — cPanel requires it when prefixing is enabled.
     */
    public function setPrivilegesOnDatabase(string $database, string $user): void
    {
        $this->call('Mysql', 'set_privileges_on_database', [
            'user' => $user,
            'database' => $database,
            'privileges' => 'ALL PRIVILEGES',
        ]);
    }

    /**
     * Remove a tenant database. Callers must gate this to local/testing; the
     * provisioner enforces that boundary before reaching this method.
     */
    public function deleteDatabase(string $name): void
    {
        $this->call('Mysql', 'delete_database', ['name' => $name]);
    }

    /**
     * Execute a UAPI call through the `/execute/` endpoint.
     *
     * @param  array<string, string>  $params
     * @return array<string, mixed> the UAPI result envelope (data/status/errors/messages)
     */
    private function call(string $module, string $function, array $params, bool $tolerateAlreadyExists = false): array
    {
        if (! $this->isConfigured()) {
            throw CpanelApiException::notConfigured();
        }

        // Fail closed BEFORE any credential is placed on the wire: the target
        // host and port must be explicitly allow-listed (staging only).
        $this->assertEndpointAllowed();

        $url = sprintf(
            '%s://%s:%d/execute/%s/%s',
            $this->scheme(),
            $this->host(),
            $this->port(),
            $module,
            $function,
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'cpanel '.$this->username().':'.$this->token(),
                'Accept' => 'application/json',
            ])
                ->withOptions([
                    'verify' => $this->verifyTls(),
                    'timeout' => $this->timeout(),
                    'connect_timeout' => $this->timeout(),
                    // Refuse redirects: the Authorization header carries the
                    // token, so following a redirect could replay it to another
                    // host. A 3xx is treated as a hard failure below.
                    'allow_redirects' => false,
                ])
                ->asForm()
                ->post($url, $params);
        } catch (ConnectionException|Throwable $e) {
            // The exception message never includes the Authorization header, so
            // the token cannot leak through this path.
            throw CpanelApiException::transport($module, $function, $e->getMessage());
        }

        // A redirect is never a legitimate UAPI response. Refuse it explicitly
        // so the credential is never forwarded to a different location.
        if ($response->redirect()) {
            throw CpanelApiException::call(
                $module,
                $function,
                $response->status(),
                'Refused an unexpected HTTP redirect.',
            );
        }

        $body = $response->json();

        // Handle both the `/execute/` envelope and the older wrapped envelope.
        $result = is_array($body) && isset($body['result']) && is_array($body['result'])
            ? $body['result']
            : (is_array($body) ? $body : []);

        $ok = (int) ($result['status'] ?? 0) === 1;

        if (! $ok) {
            $detail = $this->errorDetail($result);

            if ($tolerateAlreadyExists && self::looksLikeAlreadyExists($detail)) {
                return $result;
            }

            throw CpanelApiException::call($module, $function, $response->status(), $detail);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function errorDetail(array $result): string
    {
        $errors = $result['errors'] ?? null;

        if (is_array($errors)) {
            $flat = array_filter(array_map(
                static fn ($value): string => is_string($value) ? $value : (string) json_encode($value),
                $errors,
            ));

            if ($flat !== []) {
                return implode('; ', $flat);
            }
        }

        if (is_string($errors) && $errors !== '') {
            return $errors;
        }

        $messages = $result['messages'] ?? null;

        if (is_array($messages) && $messages !== []) {
            return (string) json_encode($messages);
        }

        return 'unknown error';
    }

    private static function looksLikeAlreadyExists(string $detail): bool
    {
        return stripos($detail, 'already exists') !== false
            || stripos($detail, 'exists') !== false;
    }

    // ------------------------------------------------------------ config

    /**
     * Refuse the request unless the endpoint is on the explicit allowlist.
     *
     * An empty allowlist NEVER means "any host/port": the guard fails closed,
     * so a mis-set or hostile Global Settings value can never redirect the
     * API token to a server the operator did not permit.
     */
    private function assertEndpointAllowed(): void
    {
        if (! $this->endpointAllowed()) {
            throw CpanelApiException::endpointNotAllowed($this->host(), $this->port());
        }
    }

    private function endpointAllowed(): bool
    {
        $hosts = array_map(
            static fn ($host): string => strtolower(trim((string) $host)),
            (array) ($this->config['allowed_hosts'] ?? []),
        );
        $ports = array_map(
            static fn ($port): int => (int) $port,
            (array) ($this->config['allowed_ports'] ?? []),
        );

        if ($hosts === [] || $ports === []) {
            return false;
        }

        return in_array(strtolower($this->host()), $hosts, true)
            && in_array($this->port(), $ports, true);
    }

    private function scheme(): string
    {
        // TLS is MANDATORY: the UAPI carries the API token in the
        // Authorization header, so plain HTTP is never acceptable.
        return 'https';
    }

    private function host(): string
    {
        return trim((string) ($this->config['host'] ?? ''));
    }

    private function port(): int
    {
        return (int) ($this->config['port'] ?? 2083);
    }

    private function username(): string
    {
        return trim((string) ($this->config['username'] ?? ''));
    }

    private function token(): string
    {
        return trim((string) ($this->config['api_token'] ?? ''));
    }

    private function timeout(): int
    {
        return max(1, (int) ($this->config['timeout'] ?? 20));
    }

    private function verifyTls(): bool
    {
        return (bool) ($this->config['verify_tls'] ?? true);
    }
}
