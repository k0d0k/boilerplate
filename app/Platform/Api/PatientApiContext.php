<?php

namespace App\Platform\Api;

use App\Models\PatientApiToken;

/**
 * Phase 8B — request-scoped holder for the resolved patient API token.
 *
 * The token guard authenticates the bearer secret and records the matching
 * {@see PatientApiToken} here, so the tenant-binding middleware and the
 * controllers can read the VERIFIED clinic scope without re-parsing the header
 * or trusting any client input. Bound as a scoped singleton, so it is discarded
 * between requests and can never leak one caller's context into another.
 */
final class PatientApiContext
{
    private ?PatientApiToken $token = null;

    public function set(PatientApiToken $token): void
    {
        $this->token = $token;
    }

    public function token(): ?PatientApiToken
    {
        return $this->token;
    }

    public function tenantId(): ?string
    {
        $tenantId = $this->token?->tenant_id;

        return $tenantId === null ? null : (string) $tenantId;
    }

    public function forget(): void
    {
        $this->token = null;
    }
}
