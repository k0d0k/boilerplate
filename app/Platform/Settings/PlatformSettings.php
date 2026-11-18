<?php

namespace App\Platform\Settings;

use App\Localization\LocaleResolver;
use App\Models\User;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Throwable;

/**
 * The single read/write path for platform-level settings (Phase 3 — Global
 * Settings).
 *
 * Every consumer that needs the EFFECTIVE platform configuration — a web
 * request, an Artisan command, a queue worker — goes through this class, so
 * there is exactly one place that merges persisted overrides over the `.env`
 * configuration:
 *
 *   - the default platform language ({@see localeDefault()}), consumed by
 *     {@see LocaleResolver} as its last-resort default;
 *   - the cPanel hosting/database configuration
 *     ({@see effectiveCpanel()}), consumed by the tenancy adapters.
 *
 * A setting that has never been persisted falls back to its existing
 * configuration value, so installations that never open Global Settings keep
 * working unchanged. Values are CENTRAL; the cPanel API token is stored
 * encrypted and is never returned in a display projection, and the audit trail
 * names only the setting that changed — never its value.
 */
final class PlatformSettings
{
    public const LOCALE_KEY = 'localization.default_locale';

    public const SYSTEM_NAME_KEY = 'platform.system_name';

    public const CPANEL_ENABLED = 'tenancy.cpanel.enabled';

    public const CPANEL_HOST = 'tenancy.cpanel.host';

    public const CPANEL_PORT = 'tenancy.cpanel.port';

    public const CPANEL_USERNAME = 'tenancy.cpanel.username';

    public const CPANEL_TOKEN = 'tenancy.cpanel.api_token';

    public const CPANEL_RUNTIME_USER = 'tenancy.cpanel.runtime_user';

    public const CPANEL_VERIFY_TLS = 'tenancy.cpanel.verify_tls';

    public const CPANEL_TIMEOUT = 'tenancy.cpanel.timeout';

    /**
     * How tenant databases are provisioned. `cpanel` keeps the existing
     * behaviour (the UAPI adapter when enabled, otherwise the raw-SQL adapter);
     * `manual` means an administrator creates the database in cPanel BY HAND
     * and the application never calls the cPanel UAPI.
     */
    public const DATABASE_MODE = 'tenancy.database.mode';

    public const MODE_CPANEL = 'cpanel';

    public const MODE_MANUAL = 'manual';

    public const AUDIT_MODULE_KEY = 'platform.settings';

    public const AUDIT_ACTION = 'update_setting';

    /** @var array<string, array{value: ?string, is_secret: bool}>|null */
    private ?array $rows = null;

    // ------------------------------------------------------------ Language

    /**
     * The persisted platform default language, validated against the supported
     * locales and falling back to `config('localization.default')`.
     */
    public function localeDefault(): string
    {
        $supported = $this->supportedLocales();

        $stored = $this->value(self::LOCALE_KEY);

        if (is_string($stored) && in_array($stored, $supported, true)) {
            return $stored;
        }

        $fallback = (string) config('localization.default', $supported[0]);

        return in_array($fallback, $supported, true) ? $fallback : $supported[0];
    }

    public function setLocaleDefault(string $locale, ?User $actor): void
    {
        if (! in_array($locale, $this->supportedLocales(), true)) {
            throw new InvalidArgumentException("Unsupported platform locale [{$locale}].");
        }

        if ($this->localeDefault() === $locale && $this->value(self::LOCALE_KEY) === $locale) {
            return;
        }

        $this->put(self::LOCALE_KEY, $locale, false);
        $this->audit(self::LOCALE_KEY, $actor);
    }

    /**
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        $supported = array_values(array_filter(
            (array) config('localization.supported', ['id']),
            'is_string',
        ));

        return $supported === [] ? ['id'] : $supported;
    }

    // -------------------------------------------------------- System name

    /**
     * The effective system display name: the persisted override, or the
     * shipped name as the fallback so an install that never changed it keeps
     * showing the name it shipped with. Never returns an empty string.
     */
    public function systemName(): string
    {
        $stored = $this->value(self::SYSTEM_NAME_KEY);

        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        $fallback = trim((string) config('lba.system_name', config('app.name', 'LBA Cloud')));

        return $fallback !== '' ? $fallback : 'LBA Cloud';
    }

    public function setSystemName(string $name, ?User $actor): void
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('The system name may not be blank.');
        }

        if ($this->value(self::SYSTEM_NAME_KEY) === $name) {
            return;
        }

        $this->put(self::SYSTEM_NAME_KEY, $name, false);
        $this->audit(self::SYSTEM_NAME_KEY, $actor);
    }

    // -------------------------------------------------------------- cPanel

    /**
     * The effective cPanel configuration: the existing `.env` configuration
     * overlaid by any persisted overrides. This is the ONLY source the tenancy
     * adapters (and the settings screen) read.
     *
     * @return array<string, mixed>
     */
    public function effectiveCpanel(): array
    {
        return array_merge((array) config('tenancy.cpanel', []), $this->cpanelOverrides());
    }

    /**
     * Only the persisted cPanel overrides, with the token decrypted.
     *
     * @return array<string, mixed>
     */
    public function cpanelOverrides(): array
    {
        $map = [
            'enabled' => [self::CPANEL_ENABLED, 'bool'],
            'host' => [self::CPANEL_HOST, 'string'],
            'port' => [self::CPANEL_PORT, 'int'],
            'username' => [self::CPANEL_USERNAME, 'string'],
            'api_token' => [self::CPANEL_TOKEN, 'string'],
            'runtime_user' => [self::CPANEL_RUNTIME_USER, 'string'],
            'verify_tls' => [self::CPANEL_VERIFY_TLS, 'bool'],
            'timeout' => [self::CPANEL_TIMEOUT, 'int'],
        ];

        $overrides = [];

        foreach ($map as $name => [$key, $type]) {
            $raw = $this->value($key);

            if ($raw === null) {
                continue;
            }

            $overrides[$name] = match ($type) {
                'bool' => $raw === '1' || $raw === 'true',
                'int' => (int) $raw,
                default => $raw,
            };
        }

        return $overrides;
    }

    public function hasCpanelToken(): bool
    {
        return (string) ($this->effectiveCpanel()['api_token'] ?? '') !== '';
    }

    /**
     * Where the effective token comes from: a persisted value, the `.env`
     * fallback, or nowhere. The token itself is never returned.
     */
    public function cpanelTokenSource(): string
    {
        if ($this->value(self::CPANEL_TOKEN) !== null) {
            return 'stored';
        }

        return (string) config('tenancy.cpanel.api_token', '') !== '' ? 'env' : 'none';
    }

    /**
     * Persist the cPanel settings submitted by the Global Settings screen.
     *
     * A BLANK token preserves the current one (write-only field). Only settings
     * whose value actually changed are written and audited.
     *
     * @param  array<string, mixed>  $data
     * @return list<string> the setting keys that changed
     */
    public function updateCpanel(array $data, ?User $actor): array
    {
        $previous = $this->effectiveCpanel();

        $current = [
            'enabled' => ($previous['enabled'] ?? false) ? '1' : '0',
            'host' => trim((string) ($previous['host'] ?? '')),
            'port' => (string) (int) ($previous['port'] ?? 2083),
            'username' => trim((string) ($previous['username'] ?? '')),
            'runtime_user' => trim((string) ($previous['runtime_user'] ?? '')),
            'verify_tls' => ($previous['verify_tls'] ?? true) ? '1' : '0',
            'timeout' => (string) max(1, (int) ($previous['timeout'] ?? 20)),
        ];

        $submitted = [
            'enabled' => [self::CPANEL_ENABLED, fn ($value): string => $value ? '1' : '0'],
            'host' => [self::CPANEL_HOST, fn ($value): string => trim((string) $value)],
            'port' => [self::CPANEL_PORT, fn ($value): string => (string) (int) $value],
            'username' => [self::CPANEL_USERNAME, fn ($value): string => trim((string) $value)],
            'runtime_user' => [self::CPANEL_RUNTIME_USER, fn ($value): string => trim((string) $value)],
            'verify_tls' => [self::CPANEL_VERIFY_TLS, fn ($value): string => $value ? '1' : '0'],
            'timeout' => [self::CPANEL_TIMEOUT, fn ($value): string => (string) max(1, (int) $value)],
        ];

        $changed = [];

        foreach ($submitted as $name => [$key, $normalize]) {
            if (! array_key_exists($name, $data)) {
                continue;
            }

            $value = $normalize($data[$name]);

            if ($current[$name] === $value) {
                continue;
            }

            $this->put($key, $value, false);
            $changed[] = $key;
            $this->audit($key, $actor);
        }

        // Write-only token: a blank submission keeps whatever is configured.
        $token = trim((string) ($data['token'] ?? ''));

        if ($token !== '') {
            $this->put(self::CPANEL_TOKEN, $token, true);
            $changed[] = self::CPANEL_TOKEN;
            $this->audit(self::CPANEL_TOKEN, $actor);
        }

        return $changed;
    }

    /**
     * A projection safe for the settings screen: everything EXCEPT the token.
     *
     * @return array<string, mixed>
     */
    public function cpanelDisplay(): array
    {
        $cpanel = $this->effectiveCpanel();

        return [
            'enabled' => (bool) ($cpanel['enabled'] ?? false),
            'host' => (string) ($cpanel['host'] ?? ''),
            'port' => (int) ($cpanel['port'] ?? 2083),
            'username' => (string) ($cpanel['username'] ?? ''),
            'runtime_user' => (string) ($cpanel['runtime_user'] ?? ''),
            'verify_tls' => (bool) ($cpanel['verify_tls'] ?? true),
            'timeout' => (int) ($cpanel['timeout'] ?? 20),
            'token_configured' => $this->hasCpanelToken(),
            'token_source' => $this->cpanelTokenSource(),
        ];
    }

    // -------------------------------------------------------- Database mode

    /**
     * The effective tenant database provisioning mode. Defaults to `cpanel`,
     * which preserves the shipped behaviour for an installation that never
     * chose a mode (the UAPI adapter when enabled, otherwise the raw-SQL one).
     */
    public function databaseMode(): string
    {
        return $this->value(self::DATABASE_MODE) === self::MODE_MANUAL
            ? self::MODE_MANUAL
            : self::MODE_CPANEL;
    }

    public function setDatabaseMode(string $mode, ?User $actor): void
    {
        if (! in_array($mode, [self::MODE_CPANEL, self::MODE_MANUAL], true)) {
            throw new InvalidArgumentException("Unsupported database mode [{$mode}].");
        }

        if ($this->value(self::DATABASE_MODE) === $mode) {
            return;
        }

        $this->put(self::DATABASE_MODE, $mode, false);
        $this->audit(self::DATABASE_MODE, $actor);
    }

    /**
     * Whether the PHYSICAL tenant database names carry the hosting account
     * prefix. True when cPanel provisioning is enabled, or when manual mode is
     * active — a database created by hand in cPanel is still prefixed with the
     * account username, so the name the application derives must match it.
     *
     * Legacy installations (cPanel disabled, mode `cpanel`) keep their
     * unprefixed names, so an already-registered tenant is never re-pointed.
     */
    public function databaseNamesPrefixed(): bool
    {
        return (bool) ($this->effectiveCpanel()['enabled'] ?? false)
            || $this->databaseMode() === self::MODE_MANUAL;
    }

    /**
     * The MySQL host/port manual mode uses, read from the tenant connection's
     * SERVER configuration — never from a cPanel UAPI call.
     *
     * @return array{host: string, port: string}
     */
    public function manualServer(): array
    {
        $connection = (string) config('tenancy.tenant_connection', 'tenant');

        return [
            'host' => (string) config("database.connections.{$connection}.host", ''),
            'port' => (string) config("database.connections.{$connection}.port", ''),
        ];
    }

    // ---------------------------------------------------------- internals

    /**
     * @return array<string, array{value: ?string, is_secret: bool}>
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            $rows = [];

            foreach (PlatformSetting::query()->get() as $row) {
                $rows[(string) $row->key] = [
                    'value' => $row->value,
                    'is_secret' => (bool) $row->is_secret,
                ];
            }

            return $this->rows = $rows;
        } catch (Throwable) {
            // The settings table does not exist yet (fresh install, migrate):
            // fall back to configuration rather than failing the request.
            return $this->rows = [];
        }
    }

    private function value(string $key): ?string
    {
        $row = $this->rows()[$key] ?? null;

        if ($row === null || $row['value'] === null) {
            return null;
        }

        if (! $row['is_secret']) {
            return (string) $row['value'];
        }

        try {
            return Crypt::decryptString((string) $row['value']);
        } catch (Throwable) {
            // A rotated APP_KEY makes the ciphertext unreadable; treat it as
            // absent so the `.env` fallback still applies.
            return null;
        }
    }

    private function put(string $key, ?string $value, bool $secret): void
    {
        $stored = $value === null ? null : ($secret ? Crypt::encryptString($value) : $value);

        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'is_secret' => $secret],
        );

        $this->rows = null;
    }

    /**
     * Name only the setting that changed — never its value or any credential.
     */
    private function audit(string $settingKey, ?User $actor): void
    {
        ModuleAuditLog::query()->create([
            'module_key' => self::AUDIT_MODULE_KEY,
            'action' => self::AUDIT_ACTION,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'reason' => null,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => $settingKey,
            'actor_id' => $actor?->getKey(),
            'metadata' => null,
        ]);
    }
}
