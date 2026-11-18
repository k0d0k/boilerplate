<?php

namespace Modules\Consultations\Ai;

use Modules\Consultations\Ai\Contracts\SinaChatProvider;
use Modules\Consultations\Ai\Exceptions\SinaException;
use Modules\Consultations\Ai\Providers\OllamaChatProvider;
use Modules\Consultations\Ai\Providers\OpenAiCompatibleChatProvider;
use Modules\Consultations\Models\AiProviderConfig;

/**
 * Workstream 2 — resolves the SINA chat provider for a tenant.
 *
 * Resolution order:
 *  1. the tenant's own enabled {@see AiProviderConfig} (default first), else
 *  2. the platform default from `config('lba.sina.*')`.
 *
 * The resolver NEVER trusts client input: the tenant id is always a
 * server-derived value (the verified tenant context or the token's clinic).
 */
final class SinaProviderResolver
{
    public function forTenant(string $tenantId): SinaChatProvider
    {
        $config = AiProviderConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('provider')
            ->first();

        if ($config instanceof AiProviderConfig) {
            return $this->make($config->provider, $config->base_url, $config->model, $config->api_key);
        }

        return $this->platformDefault();
    }

    public function make(string $provider, string $baseUrl, string $model, ?string $apiKey = null): SinaChatProvider
    {
        if ($baseUrl === '' || $model === '') {
            throw SinaException::notConfigured($provider);
        }

        return match ($provider) {
            AiProviderConfig::PROVIDER_OLLAMA => new OllamaChatProvider($model, $baseUrl, $apiKey, $this->timeout()),
            AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE => new OpenAiCompatibleChatProvider($model, $baseUrl, $apiKey, $this->timeout()),
            default => throw SinaException::notConfigured($provider),
        };
    }

    private function platformDefault(): SinaChatProvider
    {
        $provider = (string) config('lba.sina.default_provider', AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE);

        return $this->make(
            $provider,
            (string) config("lba.sina.defaults.{$provider}.base_url", ''),
            (string) config("lba.sina.defaults.{$provider}.model", ''),
        );
    }

    private function timeout(): int
    {
        return max(1, (int) config('lba.sina.timeout', 30));
    }
}
