<?php

namespace Modules\Consultations\Ai\Contracts;

use Modules\Consultations\Ai\Dto\SinaChatMessage;
use Modules\Consultations\Ai\Dto\SinaCompletion;
use Modules\Consultations\Ai\Exceptions\SinaException;

/**
 * Workstream 2 — the SINA consultation chat provider contract.
 *
 * A SINA provider is a plain chat/completion endpoint (Ollama or any
 * OpenAI-compatible API). It is deliberately SEPARATE from the LBA vision
 * gateway (`Modules\LBA\AI`): text chat never touches the FastAPI vision
 * service, and vice versa.
 */
interface SinaChatProvider
{
    /**
     * The provider identifier recorded on usage.
     */
    public function providerId(): string;

    /**
     * Run one chat completion.
     *
     * @param  list<SinaChatMessage>  $messages
     * @param  array{model?: string, temperature?: float, max_tokens?: int}  $options
     *
     * @throws SinaException
     */
    public function chat(array $messages, array $options = []): SinaCompletion;
}
