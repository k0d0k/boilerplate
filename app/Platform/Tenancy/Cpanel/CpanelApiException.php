<?php

namespace App\Platform\Tenancy\Cpanel;

use RuntimeException;

/**
 * Raised when a cPanel UAPI call fails (Phase 3 — one-click provisioning).
 *
 * The message is safe to log: it never contains the API token or any credential,
 * only the UAPI module/function, the HTTP status and the cPanel error text.
 */
final class CpanelApiException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'cPanel provisioning is enabled but not fully configured. '
            .'Set CPANEL_HOST, CPANEL_USERNAME and CPANEL_API_TOKEN (server-side only).',
        );
    }

    public static function call(string $module, string $function, int $status, string $detail): self
    {
        return new self(
            "cPanel UAPI call {$module}::{$function} failed (HTTP {$status}): {$detail}",
        );
    }

    public static function transport(string $module, string $function, string $detail): self
    {
        return new self("cPanel UAPI call {$module}::{$function} could not be reached: {$detail}");
    }

    public static function endpointNotAllowed(string $host, int $port): self
    {
        return new self(
            "Refusing the cPanel UAPI request: endpoint {$host}:{$port} is not on the "
            .'allowed host/port list (set CPANEL_ALLOWED_HOSTS and CPANEL_ALLOWED_PORTS).',
        );
    }
}
