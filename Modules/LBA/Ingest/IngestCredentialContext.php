<?php

namespace Modules\LBA\Ingest;

use App\Models\LbaIngestCredential;

/**
 * WS6.B — request-scoped holder for the resolved agent ingest credential.
 *
 * The `lba.ingest` middleware authenticates the bearer secret and records the
 * matching {@see LbaIngestCredential} here, so the ingest controller reads the
 * VERIFIED tenant, branch and examination scope without re-parsing the header
 * or trusting any client input. Bound as a scoped singleton, so it is discarded
 * between requests and can never leak one caller's context into another.
 */
final class IngestCredentialContext
{
    private ?LbaIngestCredential $credential = null;

    public function set(LbaIngestCredential $credential): void
    {
        $this->credential = $credential;
    }

    public function credential(): ?LbaIngestCredential
    {
        return $this->credential;
    }

    public function forget(): void
    {
        $this->credential = null;
    }
}
