<?php

namespace App\Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when physical tenant database deletion is attempted outside the
 * permitted environments (Phase 2A safety patch).
 *
 * Destructive deletion is restricted to local/testing until a separately
 * approved backup, retention and recovery workflow exists. There is no override
 * flag: this guard cannot be bypassed with --force.
 */
final class TenantDatabaseDeletionDeniedException extends RuntimeException
{
    public static function environment(string $environment): self
    {
        return new self(
            "Physical tenant database deletion is disabled in the [{$environment}] environment. ".
            'It is restricted to local/testing until an approved backup, retention and recovery workflow exists.',
        );
    }
}
