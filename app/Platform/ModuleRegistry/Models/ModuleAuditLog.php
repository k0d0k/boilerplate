<?php

namespace App\Platform\ModuleRegistry\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redacted module lifecycle / authorization audit record (Phase 1B-4).
 *
 * Never carries clinical or patient payloads — only module keys, actions,
 * results and opaque scoped references.
 */
#[Fillable([
    'module_key',
    'action',
    'result',
    'reason',
    'scope',
    'scope_key',
    'actor_id',
    'metadata',
])]
class ModuleAuditLog extends Model
{
    use HasUlids;

    public const RESULT_ALLOWED = 'allowed';

    public const RESULT_DENIED = 'denied';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
