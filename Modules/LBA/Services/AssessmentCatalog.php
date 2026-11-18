<?php

namespace Modules\LBA\Services;

use App\Platform\LbaAssessment\Models\LbaIndicatorCatalogVersion;
use App\Platform\LbaAssessment\Models\LbaIndicatorDefinition;
use Illuminate\Support\Collection;
use Modules\LBA\Assessment\LegacyIndicatorCatalog;

/**
 * Phase 7A — the versioned indicator catalog (Modules/LBA).
 *
 * The catalog is CENTRAL, non-patient reference metadata: it defines the
 * indicator terminology an assessment is built against and never holds any
 * patient finding. This service only reads and provisions it — clinical writes
 * go through {@see AssessmentRecorder} on the tenant connection.
 *
 * Provisioning is idempotent: importing the legacy reference catalog repeatedly
 * is a no-op, and a future correction must arrive as a NEW catalog version so
 * that historical assessments (which snapshot their catalog version and labels)
 * are never silently altered.
 */
final class AssessmentCatalog
{
    /**
     * The active catalog version, or null when none has been provisioned.
     */
    public function activeVersion(): ?LbaIndicatorCatalogVersion
    {
        return LbaIndicatorCatalogVersion::query()
            ->where('status', LbaIndicatorCatalogVersion::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Idempotently provision the legacy reference catalog and return its
     * version. Existing definitions are never overwritten, so re-running this
     * cannot rewrite a label or a review decision already recorded.
     */
    public function ensureLegacyCatalog(): LbaIndicatorCatalogVersion
    {
        $version = LbaIndicatorCatalogVersion::query()
            ->where('key', LegacyIndicatorCatalog::CATALOG_KEY)
            ->first();

        if ($version === null) {
            $version = LbaIndicatorCatalogVersion::query()->create([
                'key' => LegacyIndicatorCatalog::CATALOG_KEY,
                'label' => 'LBA Legacy Form Reference Catalog v1',
                'status' => LbaIndicatorCatalogVersion::STATUS_ACTIVE,
                'source' => 'legacy_paper_form',
                'notes' => 'Legacy source labels transcribed from the supplied paper LBA form. '
                    .'Reference-only and unvalidated until authorized clinical review.',
                'published_at' => now(),
            ]);
        }

        if ($version->definitions()->doesntExist()) {
            foreach (LegacyIndicatorCatalog::definitions() as $definition) {
                LbaIndicatorDefinition::query()->create([
                    'catalog_version_id' => $version->getKey(),
                    'key' => $definition['key'],
                    'source_label' => $definition['source_label'],
                    'source_column' => $definition['source_column'],
                    'position' => $definition['position'],
                    'observation_label' => null,
                    'validation_status' => LbaIndicatorDefinition::VALIDATION_REFERENCE_ONLY,
                    'is_active' => true,
                ]);
            }
        }

        return $version;
    }

    /**
     * The active version, provisioning the legacy reference catalog if none
     * exists yet. The assessment form always has a catalog to work against.
     */
    public function activeVersionOrProvision(): LbaIndicatorCatalogVersion
    {
        return $this->activeVersion() ?? $this->ensureLegacyCatalog();
    }

    /**
     * @return Collection<int, LbaIndicatorDefinition>
     */
    public function definitions(LbaIndicatorCatalogVersion $version): Collection
    {
        return $version->definitions()->where('is_active', true)->get();
    }

    public function definitionFor(LbaIndicatorCatalogVersion $version, string $key): ?LbaIndicatorDefinition
    {
        return $version->definitions()->where('key', $key)->first();
    }
}
