<?php

namespace App\Platform\Settings;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * One persisted platform setting (Phase 3 — Global Settings).
 *
 * A CENTRAL key/value row. When `is_secret` is true the raw value is held
 * ENCRYPTED at rest (see {@see PlatformSettings}); the ciphertext is never
 * projected to a view, response, log or audit record.
 */
#[Fillable(['key', 'value', 'is_secret'])]
class PlatformSetting extends CentralModel
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
        ];
    }
}
