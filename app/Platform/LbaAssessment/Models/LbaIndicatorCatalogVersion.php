<?php

namespace App\Platform\LbaAssessment\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7A — a versioned LBA indicator catalog (CENTRAL database).
 *
 * Central, non-patient reference metadata. It is the terminology layer the
 * digital assessment form is built against; it never holds patient findings and
 * is never touched by clinical writes. Pinning the CENTRAL connection means a
 * catalog read can never be confused with a tenant clinical read.
 */
#[Fillable(['key', 'label', 'status', 'source', 'notes', 'metadata', 'published_at'])]
class LbaIndicatorCatalogVersion extends CentralModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RETIRED = 'retired';

    protected $table = 'lba_indicator_catalog_versions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LbaIndicatorDefinition, $this>
     */
    public function definitions(): HasMany
    {
        return $this->hasMany(LbaIndicatorDefinition::class, 'catalog_version_id')->orderBy('position');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
