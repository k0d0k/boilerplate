<?php

namespace Modules\LBA\AI\Dto;

use Modules\LBA\AI\Services\AiResultValidator;

/**
 * Phase 5 — a validated AI vision analysis result.
 *
 * Only a {@see AiResultValidator} may build this: a
 * malformed gateway response is rejected before any detection is persisted.
 * `synthetic` marks clearly-fabricated test output from the mock adapter, which
 * is never a diagnosis.
 */
final readonly class VisionResult
{
    /**
     * @param  list<array{label: string, geometry: array<string, mixed>, confidence: float, quality_flags: list<string>}>  $detections
     */
    public function __construct(
        public string $protocolVersion,
        public string $provider,
        public string $modelName,
        public string $modelVersion,
        public bool $synthetic,
        public array $detections,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'protocol_version' => $this->protocolVersion,
            'provider' => $this->provider,
            'model' => [
                'name' => $this->modelName,
                'version' => $this->modelVersion,
            ],
            'synthetic' => $this->synthetic,
            'detections' => $this->detections,
        ];
    }
}
