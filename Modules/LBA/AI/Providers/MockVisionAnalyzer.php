<?php

namespace Modules\LBA\AI\Providers;

use Modules\LBA\AI\Contracts\VisionAnalyzer;
use Modules\LBA\AI\Dto\VisionRequest;
use Modules\LBA\AI\Dto\VisionResult;
use Modules\LBA\AI\Services\AiResultValidator;

/**
 * Phase 5 — the synthetic mock vision adapter.
 *
 * This adapter runs NO model and performs NO inference. It returns CLEARLY
 * MARKED SYNTHETIC test output (`synthetic = true`, every detection labelled
 * `synthetic_*` with the `synthetic` / `not_a_diagnosis` quality flags). It NEVER
 * asserts, implies or fabricates a diagnosis. It exists so the asynchronous
 * pipeline, validation, persistence and UI can be exercised safely, and it is
 * never presented to an operator as a real result.
 */
final class MockVisionAnalyzer implements VisionAnalyzer
{
    public const PROVIDER = 'mock';

    public function __construct(private readonly AiResultValidator $validator) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function analyze(VisionRequest $request): VisionResult
    {
        $payload = [
            'protocol_version' => $request->protocolVersion,
            'provider' => self::PROVIDER,
            'model' => [
                'name' => (string) config('lba.ai.model.name', 'lba-vision-synthetic'),
                'version' => (string) config('lba.ai.model.version', '0.0.0-synthetic'),
            ],
            'synthetic' => true,
            'detections' => [
                [
                    'label' => 'synthetic_reference_marker',
                    'type' => 'rectangle',
                    'geometry' => ['x' => 0.10, 'y' => 0.12, 'width' => 0.24, 'height' => 0.20],
                    'confidence' => 0.50,
                    'quality_flags' => ['synthetic', 'not_a_diagnosis'],
                ],
                [
                    'label' => 'synthetic_secondary_marker',
                    'type' => 'point',
                    'geometry' => ['x' => 0.62, 'y' => 0.58],
                    'confidence' => 0.50,
                    'quality_flags' => ['synthetic', 'not_a_diagnosis'],
                ],
            ],
        ];

        // Validate our own synthetic output against the same strict schema a real
        // gateway must satisfy, so the mock can never drift from the contract.
        return $this->validator->validate($payload, $request->protocolVersion);
    }
}
