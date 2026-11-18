<?php

namespace Modules\LBA\AI\Services;

use Modules\LBA\AI\Dto\VisionResult;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\Models\Annotation;

/**
 * Phase 5 — strict validation of an AI vision gateway response.
 *
 * A response is accepted ONLY when it matches the expected protocol version and
 * schema exactly: provider, model name/version, and a list of detections whose
 * label, normalised geometry and confidence are all in range. Anything else is
 * rejected wholesale — no partial result is ever persisted.
 */
final class AiResultValidator
{
    public const MAX_LABEL_LENGTH = 120;

    /**
     * @throws AiAnalysisException when the payload does not match the schema.
     */
    public function validate(mixed $payload, string $expectedProtocolVersion): VisionResult
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw AiAnalysisException::invalidResponse('the payload is not a JSON object');
        }

        $allowedTopLevel = ['protocol_version', 'provider', 'model', 'synthetic', 'detections'];

        foreach (array_keys($payload) as $key) {
            if (! in_array($key, $allowedTopLevel, true)) {
                throw AiAnalysisException::invalidResponse("unexpected field [{$key}]");
            }
        }

        $protocol = $payload['protocol_version'] ?? null;

        if (! is_string($protocol) || $protocol !== $expectedProtocolVersion) {
            throw AiAnalysisException::invalidResponse('unsupported protocol version');
        }

        $provider = $payload['provider'] ?? null;

        if (! is_string($provider) || trim($provider) === '') {
            throw AiAnalysisException::invalidResponse('missing provider');
        }

        $model = $payload['model'] ?? null;

        if (! is_array($model) || ! is_string($model['name'] ?? null) || ! is_string($model['version'] ?? null)) {
            throw AiAnalysisException::invalidResponse('missing model name/version');
        }

        $synthetic = $payload['synthetic'] ?? false;

        if (! is_bool($synthetic)) {
            throw AiAnalysisException::invalidResponse('synthetic must be a boolean');
        }

        $detections = $payload['detections'] ?? null;

        if (! is_array($detections) || ! array_is_list($detections)) {
            throw AiAnalysisException::invalidResponse('detections must be a list');
        }

        $normalised = [];

        foreach ($detections as $index => $detection) {
            $normalised[] = $this->validateDetection($detection, (int) $index);
        }

        return new VisionResult(
            protocolVersion: $protocol,
            provider: $provider,
            modelName: (string) $model['name'],
            modelVersion: (string) $model['version'],
            synthetic: $synthetic,
            detections: $normalised,
        );
    }

    /**
     * @return array{label: string, geometry: array<string, mixed>, confidence: float, quality_flags: list<string>}
     */
    private function validateDetection(mixed $detection, int $index): array
    {
        if (! is_array($detection) || array_is_list($detection)) {
            throw AiAnalysisException::invalidResponse("detection [{$index}] is not an object");
        }

        $allowed = ['label', 'geometry', 'confidence', 'quality_flags', 'type'];

        foreach (array_keys($detection) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw AiAnalysisException::invalidResponse("detection [{$index}] has an unexpected field [{$key}]");
            }
        }

        $label = $detection['label'] ?? null;

        if (! is_string($label) || trim($label) === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw AiAnalysisException::invalidResponse("detection [{$index}] has an invalid label");
        }

        $confidence = $detection['confidence'] ?? null;

        if (! is_numeric($confidence) || (float) $confidence < 0.0 || (float) $confidence > 1.0) {
            throw AiAnalysisException::invalidResponse("detection [{$index}] has an out-of-range confidence");
        }

        $type = $detection['type'] ?? null;

        if ($type !== null && (! is_string($type) || ! in_array($type, Annotation::TYPES, true))) {
            throw AiAnalysisException::invalidResponse("detection [{$index}] has an unknown type");
        }

        $geometry = $this->validateGeometry($detection['geometry'] ?? null, $index, $type);

        $flags = $detection['quality_flags'] ?? [];

        if (! is_array($flags) || ! array_is_list($flags)) {
            throw AiAnalysisException::invalidResponse("detection [{$index}] has invalid quality flags");
        }

        $qualityFlags = [];

        foreach ($flags as $flag) {
            if (! is_string($flag) || trim($flag) === '') {
                throw AiAnalysisException::invalidResponse("detection [{$index}] has an invalid quality flag");
            }

            $qualityFlags[] = $flag;
        }

        return [
            'label' => trim($label),
            'geometry' => $geometry,
            'confidence' => (float) $confidence,
            'quality_flags' => $qualityFlags,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGeometry(mixed $geometry, int $index, ?string $type): array
    {
        if (! is_array($geometry) || array_is_list($geometry)) {
            throw AiAnalysisException::invalidResponse("detection [{$index}] has no geometry object");
        }

        if (array_key_exists('points', $geometry)) {
            return ['points' => $this->validatePoints($geometry['points'], $index)];
        }

        if (array_key_exists('width', $geometry) || array_key_exists('height', $geometry)) {
            return [
                'x' => $this->unit($geometry['x'] ?? null, $index),
                'y' => $this->unit($geometry['y'] ?? null, $index),
                'width' => $this->unit($geometry['width'] ?? null, $index),
                'height' => $this->unit($geometry['height'] ?? null, $index),
            ];
        }

        return [
            'x' => $this->unit($geometry['x'] ?? null, $index),
            'y' => $this->unit($geometry['y'] ?? null, $index),
        ];
    }

    /**
     * @return list<array{float, float}>
     */
    private function validatePoints(mixed $points, int $index): array
    {
        if (! is_array($points) || ! array_is_list($points) || count($points) < 3) {
            throw AiAnalysisException::invalidResponse("detection [{$index}] polygon needs at least three points");
        }

        $normalised = [];

        foreach ($points as $point) {
            if (! is_array($point)) {
                throw AiAnalysisException::invalidResponse("detection [{$index}] has an invalid polygon point");
            }

            $normalised[] = [
                $this->unit($point[0] ?? $point['x'] ?? null, $index),
                $this->unit($point[1] ?? $point['y'] ?? null, $index),
            ];
        }

        /** @var list<array{float, float}> $normalised */
        return $normalised;
    }

    private function unit(mixed $value, int $index): float
    {
        if (! is_numeric($value) || (float) $value < 0.0 || (float) $value > 1.0) {
            throw AiAnalysisException::invalidResponse("detection [{$index}] has an out-of-range coordinate");
        }

        return (float) $value;
    }
}
