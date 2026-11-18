<?php

namespace Modules\LBA\AI\Contracts;

use Modules\LBA\AI\Dto\VisionRequest;
use Modules\LBA\AI\Dto\VisionResult;
use Modules\LBA\AI\Exceptions\AiAnalysisException;

/**
 * Phase 5 — the private AI vision analyzer boundary.
 *
 * An implementation sends one opaque image reference to an inference service
 * and returns a strictly-validated result. Inference is a SEPARATE Python /
 * FastAPI service: it is never embedded in Laravel, and it has nothing to do
 * with the SINA text chat / Ollama surface.
 */
interface VisionAnalyzer
{
    /**
     * The provider identifier persisted on every job and detection.
     */
    public function provider(): string;

    /**
     * Analyze one microscopy image and return a validated result.
     *
     * Implementations MUST throw a
     * {@see AiAnalysisException} for an unavailable
     * or invalid gateway rather than returning a partial result.
     */
    public function analyze(VisionRequest $request): VisionResult;
}
