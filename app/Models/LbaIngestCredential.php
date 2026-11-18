<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WS6.B — a scoped agent ingestion credential (CENTRAL database).
 *
 * A wet-lab / manufacturer-software agent presents the bearer secret on every
 * upload. Only its SHA-256 `token` hash is stored, so a leaked database row can
 * never be replayed. A credential authenticates ONE operator identity and binds
 * it to ONE tenant, branch and editable examination — the agent can never pick a
 * tenant, patient or examination from untrusted input.
 *
 * `expires_at` and `revoked_at` are re-evaluated on every request; either makes
 * the credential unusable.
 */
#[Fillable([
    'user_id',
    'tenant_id',
    'examination_id',
    'branch_id',
    'name',
    'token',
    'abilities',
    'expires_at',
    'last_used_at',
    'revoked_at',
])]
class LbaIngestCredential extends Model
{
    use HasUlids;

    protected $table = 'lba_ingest_credentials';

    /** The single ability a scoped ingest credential carries. */
    public const ABILITY_INGEST = 'exam.image.ingest';

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
