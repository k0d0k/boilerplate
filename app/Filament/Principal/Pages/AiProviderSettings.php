<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Models\Tenant;
use App\Platform\Access\PermissionKey;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rule;
use Modules\Consultations\Models\AiProviderConfig;
use UnitEnum;

/**
 * Workstream 2 — Principal SINA AI provider configuration.
 *
 * The platform governs which Ollama / OpenAI-compatible endpoint a tenant's
 * SINA consultation chat uses. The page shows only tenant-local, server-derived
 * data: the tenant list and the configuration rows. Credentials are written
 * through the model's `encrypted` cast and are NEVER displayed back.
 *
 * Access and every mutation are re-derived server side from the acting
 * principal's `platform.billing.manage` permission — UI visibility is never the
 * authorization boundary.
 */
class AiProviderSettings extends Page
{
    use AuthorizesPrincipalResources;

    protected static ?string $slug = 'ai-providers';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'filament.principal.pages.ai-provider-settings';

    /** The tenant whose SINA provider is being administered. */
    public ?string $tenantId = null;

    public string $provider = AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE;

    public string $baseUrl = '';

    public string $model = '';

    /** Write-only: never prefilled, never echoed back. */
    public string $apiKey = '';

    public bool $enabled = true;

    public static function canAccess(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.principal.ai_providers');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.principal.ai_providers.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.principal.ai_providers.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.principal.ai_providers.subtitle');
    }

    /**
     * @return array<string, string>
     */
    public function tenantOptions(): array
    {
        return Tenant::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    public function providerOptions(): array
    {
        return [
            AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE => __('lba.principal.ai_providers.provider_openai'),
            AiProviderConfig::PROVIDER_OLLAMA => __('lba.principal.ai_providers.provider_ollama'),
        ];
    }

    public function updatedTenantId(): void
    {
        $this->loadConfig();
    }

    public function updatedProvider(): void
    {
        $this->loadConfig();
    }

    /**
     * The currently selected (tenant, provider) row, if any — for read-only status.
     */
    public function currentConfig(): ?AiProviderConfig
    {
        if ($this->tenantId === null || $this->tenantId === '') {
            return null;
        }

        return AiProviderConfig::query()
            ->where('tenant_id', $this->tenantId)
            ->where('provider', $this->provider)
            ->first();
    }

    public function save(): void
    {
        $this->authorizeManagement();
        $tenant = $this->requireTenant();

        $this->validate([
            'provider' => ['required', Rule::in(AiProviderConfig::PROVIDERS)],
            'baseUrl' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:190'],
            'enabled' => ['boolean'],
            'apiKey' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->isAcceptableEndpoint($this->baseUrl)) {
            $this->addError('baseUrl', __('lba.principal.ai_providers.error_endpoint'));

            return;
        }

        $config = AiProviderConfig::query()->firstOrNew([
            'tenant_id' => (string) $tenant->getKey(),
            'provider' => $this->provider,
        ]);

        $config->fill([
            'base_url' => rtrim($this->baseUrl, '/'),
            'model' => $this->model,
            'enabled' => $this->enabled,
            'is_default' => true,
        ]);

        // The credential is only replaced when a new one is supplied.
        if (filled($this->apiKey)) {
            $config->api_key = $this->apiKey;
        }

        $config->save();

        $this->apiKey = '';

        Notification::make()->success()->title(__('lba.principal.ai_providers.saved'))->send();
    }

    private function loadConfig(): void
    {
        $this->reset(['baseUrl', 'model', 'apiKey']);
        $this->enabled = true;

        $config = $this->currentConfig();

        if ($config === null) {
            return;
        }

        $this->baseUrl = (string) $config->base_url;
        $this->model = (string) $config->model;
        $this->enabled = (bool) $config->enabled;
    }

    /**
     * Only HTTPS endpoints, or an HTTP loopback host (a local Ollama), are
     * accepted — a production credential is never sent over plaintext HTTP.
     */
    private function isAcceptableEndpoint(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function authorizeManagement(): void
    {
        abort_unless(static::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE), 403);
    }

    private function requireTenant(): Tenant
    {
        $tenant = Tenant::query()->find($this->tenantId);

        abort_if($tenant === null, 404);

        return $tenant;
    }
}
