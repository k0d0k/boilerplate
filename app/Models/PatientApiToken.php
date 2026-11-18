<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8B — a secure patient REST API access token (CENTRAL database).
 *
 * The bearer secret is never stored: only its SHA-256 `token` hash. A token
 * authenticates ONE account and is scoped to ONE clinic (`tenant_id`), so a
 * token issued for clinic A can never read clinic B — even when the same
 * account holds a membership there.
 *
 * `expires_at` and `revoked_at` are re-evaluated on every request; either makes
 * the token unusable.
 */
#[Fillable([
    'user_id',
    'tenant_id',
    'name',
    'token',
    'abilities',
    'expires_at',
    'last_used_at',
    'revoked_at',
])]
class PatientApiToken extends Model
{
    use HasUlids;

    protected $table = 'patient_api_tokens';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * SHA-256 hash of a raw bearer secret. Only this value is persisted.
     */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
