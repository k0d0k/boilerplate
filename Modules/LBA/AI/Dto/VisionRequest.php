<?php

namespace Modules\LBA\AI\Dto;

/**
 * Phase 5 — the outbound payload for one AI vision analysis request.
 *
 * Carries ONLY opaque references (never a patient identity, name, contact
 * detail or clinical note), the raw microscopy image bytes and their SHA-256
 * checksum, plus the protocol version the gateway must answer with.
 */
final readonly class VisionRequest
{
    public function __construct(
        public string $jobReference,
        public string $imageReference,
        public string $contents,
        public string $checksum,
        public string $mimeType,
        public string $protocolVersion,
    ) {}
}
