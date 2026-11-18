<?php

namespace App\Platform\AiBilling\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Phase 6 — a configurable AI pricing rule (CENTRAL database).
 *
 * Pricing is EXPLICIT per provider/model/unit; no universal AI token price is
 * invented. A null `model_version` matches every version of that model. The
 * highest-priority matching active rule governs a reservation, and its unit
 * price is snapshotted onto the job so a later price change never re-prices a
 * job that already reserved credits.
 */
#[Fillable([
    'provider',
    'model_name',
    'model_version',
    'unit',
    'unit_price_credits',
    'active',
    'effective_from',
    'metadata',
])]
class AiPricingRule extends CentralModel
{
    use HasUlids;

    public const UNIT_PER_JOB = 'per_job';

    public const UNIT_PER_IMAGE = 'per_image';

    protected $table = 'ai_pricing_rules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_credits' => 'integer',
            'active' => 'boolean',
            'effective_from' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The active rule that prices a provider/model/version, preferring an exact
     * version match over a version-agnostic one.
     */
    public static function activeFor(string $provider, ?string $modelName, ?string $modelVersion): ?self
    {
        if (blank($modelName)) {
            return null;
        }

        return static::query()
            ->where('provider', $provider)
            ->where('model_name', $modelName)
            ->where('active', true)
            ->where(function (Builder $query) use ($modelVersion): void {
                $query->whereNull('model_version');

                if (filled($modelVersion)) {
                    $query->orWhere('model_version', $modelVersion);
                }
            })
            ->orderByRaw('case when model_version is null then 1 else 0 end')
            ->orderBy('created_at')
            ->first();
    }
}
