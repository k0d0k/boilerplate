<?php

namespace Modules\Consultations\Models;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workstream 2 — one tenant's SINA AI provider configuration (CENTRAL database).
 *
 * The platform (Principal panel) governs which OpenAI-compatible or Ollama
 * endpoint a tenant's SINA chat uses. The credential (`api_key`) is stored
 * ENCRYPTED via the `encrypted` cast and is never exposed to the Patient panel
 * or the REST API — only the resolver reads it, server-side.
 */
#[Fillable([
    'tenant_id',
    'provider',
    'label',
    'base_url',
    'model',
    'api_key',
    'is_default',
    'enabled',
    'settings',
])]
class AiProviderConfig extends CentralModel
{
    use HasUlids;

    public const PROVIDER_OLLAMA = 'ollama';

    public const PROVIDER_OPENAI_COMPATIBLE = 'openai_compatible';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_OLLAMA,
        self::PROVIDER_OPENAI_COMPATIBLE,
    ];

    protected $table = 'ai_provider_configs';

    /**
     * The credential is not included, so a serialized model can never leak it.
     *
     * @var list<string>
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_default' => 'boolean',
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function hasCredential(): bool
    {
        return filled($this->api_key);
    }
}
