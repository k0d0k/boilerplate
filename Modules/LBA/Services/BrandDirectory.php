<?php

namespace Modules\LBA\Services;

use App\Models\Brand;
use Illuminate\Support\Collection;
use Modules\LBA\Exceptions\AssessmentException;

/**
 * Phase 7A — the tenant <-> brand relationship resolver (Modules/LBA).
 *
 * Brand != tenant != branch. An assessment may only select a brand that is
 * BOTH active AND legitimately linked to the verified tenant through the
 * `tenant_brands` pivot; the brand is never inferred from the visitor's locale
 * or from an unchecked identifier. The brand is commercial identity only, so
 * this directory reads the central Brand table and never mutates it or copies
 * clinical data into it.
 */
final class BrandDirectory
{
    /**
     * The active brands legitimately linked to one tenant.
     *
     * @return Collection<int, Brand>
     */
    public function activeBrandsForTenant(string $tenantId): Collection
    {
        return Brand::query()
            ->where('status', Brand::STATUS_ACTIVE)
            ->whereHas('tenants', fn ($query) => $query->where('tenants.id', $tenantId))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function brandOptions(string $tenantId): array
    {
        $options = [];

        foreach ($this->activeBrandsForTenant($tenantId) as $brand) {
            $options[(string) $brand->getKey()] = (string) $brand->name;
        }

        return $options;
    }

    /**
     * Resolve a selectable brand, or fail closed when it is unknown, suspended
     * or not linked to the verified tenant.
     */
    public function resolveSelectable(string $tenantId, string $brandId): Brand
    {
        $brand = $this->activeBrandsForTenant($tenantId)
            ->firstWhere('id', $brandId);

        if ($brand === null) {
            throw AssessmentException::brandNotAvailable();
        }

        return $brand;
    }

    /**
     * An immutable snapshot of the brand for the eventual report header, so a
     * later rename or suspension cannot rewrite an assessment's delivered
     * identity. The report header rendering itself is Phase 7B.
     *
     * @return array{id: string, name: string, slug: string}
     */
    public function snapshot(Brand $brand): array
    {
        return [
            'id' => (string) $brand->getKey(),
            'name' => (string) $brand->name,
            'slug' => (string) $brand->slug,
        ];
    }

    /**
     * Phase 7B — the report identity of a brand, resolved and validated for
     * snapshotting onto a report version. A brand is commercial presentation
     * identity only; this carries no clinical data.
     *
     * @return array<string, mixed>
     */
    public function reportSnapshot(Brand $brand): array
    {
        return $brand->reportIdentity();
    }

    /**
     * Phase 7B — resolve a brand an identity may configure report settings for:
     * it must be ACTIVE and legitimately linked to the verified tenant. Fails
     * closed, exactly like {@see resolveSelectable()}.
     */
    public function resolveConfigurable(string $tenantId, string $brandId): Brand
    {
        return $this->resolveSelectable($tenantId, $brandId);
    }
}
