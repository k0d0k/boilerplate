<?php

namespace Modules\LBA\AI\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\LBA\AI\Contracts\VisionAnalyzer;
use Modules\LBA\AI\Dto\VisionRequest;
use Modules\LBA\AI\Dto\VisionResult;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\AI\Services\AiResultValidator;

/**
 * Phase 5 — the outbound FastAPI vision gateway adapter.
 *
 * Sends ONE opaque request to a configured, allowlisted gateway over HTTPS and
 * validates the response against the strict schema. It enforces:
 *  - an explicit host allowlist (never an arbitrary address);
 *  - HTTPS for any non-loopback host;
 *  - an HMAC-SHA256 signature over `timestamp.nonce.body_hash`, plus a unique
 *    nonce and a timestamp (replay protection);
 *  - a bounded request timeout.
 *
 * No patient identity is ever sent: the body contains only opaque references,
 * the image bytes, their checksum and the protocol version. No secret is ever
 * hard-coded — the key id and shared secret come from the environment. The
 * gateway is a SEPARATE service; no Ollama/chat endpoint is exposed.
 */
final class FastApiVisionAnalyzer implements VisionAnalyzer
{
    public const PROVIDER = 'fastapi';

    public function __construct(private readonly AiResultValidator $validator) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function analyze(VisionRequest $request): VisionResult
    {
        $baseUrl = (string) config('lba.ai.gateway.url', '');
        $keyId = (string) config('lba.ai.gateway.key_id', '');
        $secret = (string) config('lba.ai.gateway.secret', '');

        if ($baseUrl === '' || $keyId === '' || $secret === '') {
            throw AiAnalysisException::gatewayNotConfigured();
        }

        $this->assertAllowedEndpoint($baseUrl);

        $body = [
            'job_reference' => $request->jobReference,
            'image_reference' => $request->imageReference,
            'image_base64' => base64_encode($request->contents),
            'checksum' => $request->checksum,
            'content_type' => $request->mimeType,
            'protocol_version' => $request->protocolVersion,
        ];

        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);

        $timestamp = (string) time();
        $nonce = Str::random(32);
        $signature = hash_hmac('sha256', $this->canonical($timestamp, $nonce, $json), $secret);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-LBA-Key-Id' => $keyId,
                'X-LBA-Timestamp' => $timestamp,
                'X-LBA-Nonce' => $nonce,
                'X-LBA-Signature' => $signature,
            ])
                // Never follow a redirect: a redirect target is not allowlisted
                // and could exfiltrate the signed body to an arbitrary host.
                ->withoutRedirecting()
                ->timeout((int) config('lba.ai.timeout', 30))
                ->withBody($json, 'application/json')
                ->post(rtrim($baseUrl, '/').'/v1/lba/analyze');
        } catch (ConnectionException) {
            throw AiAnalysisException::gatewayUnavailable();
        }

        if ($response->redirect()) {
            // A 3xx is a rejected target, not a valid inference response.
            throw AiAnalysisException::gatewayNotAllowed();
        }

        if (! $response->successful()) {
            // Never surface the gateway body: it may echo request material.
            throw AiAnalysisException::gatewayUnavailable();
        }

        return $this->validator->validate($response->json(), $request->protocolVersion);
    }

    /**
     * The exact string the gateway re-computes to verify the signature.
     */
    private function canonical(string $timestamp, string $nonce, string $body): string
    {
        return $timestamp.'.'.$nonce.'.'.hash('sha256', $body);
    }

    /**
     * @throws AiAnalysisException
     */
    private function assertAllowedEndpoint(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);

        if ($host === '') {
            throw AiAnalysisException::gatewayNotConfigured();
        }

        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        // HTTPS is required for every non-loopback environment.
        if ($scheme !== 'https' && ! $loopback) {
            throw AiAnalysisException::gatewayNotAllowed();
        }

        $allowed = (array) config('lba.ai.gateway.allowed_hosts', []);

        // Host allowlisting is REQUIRED: a non-loopback gateway must be on an
        // explicit allowlist (an empty allowlist never means "any host").
        if ($allowed === []) {
            if (! $loopback) {
                throw AiAnalysisException::gatewayNotAllowed();
            }

            return;
        }

        if (! in_array($host, $allowed, true)) {
            throw AiAnalysisException::gatewayNotAllowed();
        }
    }
}
