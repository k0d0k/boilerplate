<?php

namespace App\Localization;

use App\Localization\Contracts\ResolvesLocale;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Settings\PlatformSettings;

/**
 * Default locale resolver (Phase 1B-3).
 *
 * Implements the approved priority order exactly, using only the existing
 * central Brand/Tenant metadata (plus the small additive `users.locale`
 * preference) — it never invents a tenant or brand context to find a language.
 *
 *  1. user preference        — `users.locale`, if a supported code
 *  2. permitted brand default — the tenant's governing brand (only when the
 *     brand context is unambiguous), from `metadata.default_locale` or the
 *     shipped `config/localization.php` starter defaults
 *  3. verified tenant default — the tenant's own `metadata.default_locale`
 *  4. platform default        — `id`
 *
 * Biodam has no shipped default: without an explicit stored setting it falls
 * through to the tenant default and then the platform default rather than
 * inventing a value.
 */
final class LocaleResolver implements ResolvesLocale
{
    public function __construct(
        private readonly ManagesTenantContext $context,
        private readonly PlatformSettings $settings,
    ) {}

    public function supported(): array
    {
        /** @var list<string> $supported */
        $supported = config('localization.supported', ['id']);

        return $supported;
    }

    public function isSupported(?string $locale): bool
    {
        return is_string($locale) && $locale !== '' && in_array($locale, $this->supported(), true);
    }

    public function default(): string
    {
        // The platform default is the persisted Global Settings value, falling
        // back to `config('localization.default')` (the `.env` value).
        return $this->settings->localeDefault();
    }

    public function resolve(?User $user): string
    {
        if ($user === null) {
            return $this->default();
        }

        // 1. The authenticated user's saved preference always wins.
        if ($this->isSupported($user->locale)) {
            return (string) $user->locale;
        }

        $tenant = $this->context->currentTenant($user);

        // 2. Active permitted brand default, only when unambiguous.
        $brandLocale = $this->brandDefault($tenant);

        if ($brandLocale !== null) {
            return $brandLocale;
        }

        // 3. Verified tenant default.
        $tenantLocale = $this->tenantDefault($tenant);

        if ($tenantLocale !== null) {
            return $tenantLocale;
        }

        // 4. Platform default.
        return $this->default();
    }

    /**
     * The brand default for the tenant's governing brand, if one can be chosen
     * without guessing.
     */
    private function brandDefault(?Tenant $tenant): ?string
    {
        $brand = $this->governingBrand($tenant);

        if ($brand === null) {
            return null;
        }

        $stored = $brand->metadata['default_locale'] ?? null;

        if ($this->isSupported($stored)) {
            return $stored;
        }

        $shipped = config("localization.brand_defaults.{$brand->slug}");

        return $this->isSupported($shipped) ? $shipped : null;
    }

    private function tenantDefault(?Tenant $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }

        $stored = $tenant->metadata['default_locale'] ?? null;

        return $this->isSupported($stored) ? $stored : null;
    }

    /**
     * The unambiguous governing brand of the tenant: its `is_primary` brand, or
     * its only active brand. When several active brands exist and none is
     * designated primary the brand context is ambiguous and no language is
     * chosen from it.
     */
    private function governingBrand(?Tenant $tenant): ?Brand
    {
        if ($tenant === null) {
            return null;
        }

        $brands = $tenant->brands
            ->where('status', Brand::STATUS_ACTIVE)
            ->values();

        if ($brands->isEmpty()) {
            return null;
        }

        $primary = $brands->first(fn (Brand $brand): bool => (bool) $brand->pivot->is_primary);

        if ($primary instanceof Brand) {
            return $primary;
        }

        if ($brands->count() > 1) {
            return null;
        }

        return $brands->first();
    }
}
