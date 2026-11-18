<?php

namespace App\Platform\Tenancy\Cpanel;

use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\TenantDatabaseName;
use Throwable;

/**
 * Read-only cPanel connectivity probe (Phase 3 — Global Settings).
 *
 * Answers, WITHOUT changing anything on the server, whether the effective
 * cPanel configuration can authenticate and whether the restricted tenant
 * runtime user is available, and reports the prefix rule in effect.
 *
 * READ-ONLY CONTRACT
 *  - The only UAPI call made is `Mysql::list_users`. No database is created,
 *    altered or dropped, and the account's database LIST is never fetched or
 *    surfaced.
 *  - The projection contains no credential: the API token never appears, and
 *    the raw cPanel error text is replaced by a safe reason code, so nothing
 *    sensitive can leak into a response, a view or a log.
 */
final class CpanelConnectionProbe
{
    public function __construct(
        private readonly CpanelClient $client,
        private readonly TenantDatabaseName $names,
        private readonly PlatformSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $cpanel = $this->settings->effectiveCpanel();

        $account = $this->names->account();
        $prefixedRuntimeUser = $this->names->prefixedRuntimeUser();

        $result = [
            'configured' => $this->client->isConfigured(),
            'host' => trim((string) ($cpanel['host'] ?? '')),
            'port' => (int) ($cpanel['port'] ?? 2083),
            'account' => $account,
            'runtime_user' => $this->names->runtimeUser(),
            'prefixed_runtime_user' => $prefixedRuntimeUser,
            'prefix_enabled' => $account !== null,
            'tls_verified' => (bool) ($cpanel['verify_tls'] ?? true),
            'authenticated' => null,
            'runtime_user_available' => null,
            'reason' => null,
        ];

        if (! $result['configured']) {
            $result['reason'] = 'not_configured';

            return $result;
        }

        try {
            // Read-only: authenticate and enumerate the account's MySQL users.
            $users = $this->client->listUsers();

            $result['authenticated'] = true;
            $result['runtime_user_available'] = $prefixedRuntimeUser !== null
                && in_array($prefixedRuntimeUser, $users, true);

            if (! $result['runtime_user_available']) {
                $result['reason'] = 'runtime_user_missing';
            }
        } catch (Throwable) {
            // Surface only a safe code — never the raw cPanel text — so no
            // credential or database detail can leak into the response.
            $result['authenticated'] = false;
            $result['reason'] = 'authentication_failed';
        }

        return $result;
    }
}
