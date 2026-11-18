<?php

namespace Modules\LBA\Filament\Pages;

use App\Models\Brand;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use Modules\LBA\Services\BrandDirectory;
use Throwable;

/**
 * Phase 7B — configurable BRAND REPORT IDENTITIES (Modules/LBA).
 *
 * Brand != tenant != branch. This page lets an authorized identity configure how
 * ONE of the brands VERIFIED as active and linked to the current tenant appears
 * on a patient-facing PDF report: display name, address/contact, website, default
 * report language, header/footer and a private logo. It never touches patient or
 * clinical data — commercial presentation metadata only.
 *
 * A brand may only be configured when it is ACTIVE and legitimately linked to the
 * verified tenant (fail-closed). The logo is stored on the PRIVATE report disk;
 * there is no public URL.
 */
class BrandReportSettings extends Page
{
    use AuthorizesModuleResources;
    use WithFileUploads;

    public const MODULE_KEY = 'lba';

    protected static ?string $slug = 'lba/report-brands';

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':lba',
    ];

    protected static ?int $navigationSort = 29;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected string $view = 'lba::pages.brand-report-settings';

    public ?string $brandId = null;

    public string $displayName = '';

    public string $address = '';

    public string $contact = '';

    public string $website = '';

    public string $language = Brand::REPORT_LANGUAGE_DEFAULT;

    public string $header = '';

    public string $footer = '';

    /** @var mixed */
    public $logo = null;

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive()
            && (static::allows(PermissionKey::TENANT_PROFILE_MANAGE)
                || static::allows(PermissionKey::BRAND_METADATA_MANAGE));
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.brand_report.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.exams.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.brand_report.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.brand_report.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.brand_report.subtitle');
    }

    public function tenantId(): ?string
    {
        $user = static::currentUser();

        if ($user === null) {
            return null;
        }

        return app(ManagesTenantContext::class)->currentTenantId($user);
    }

    /**
     * The active brands legitimately linked to the verified tenant.
     *
     * @return Collection<int, Brand>
     */
    public function brands(): Collection
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return collect();
        }

        try {
            return app(BrandDirectory::class)->activeBrandsForTenant($tenantId);
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @return array<string, string>
     */
    public function brandOptions(): array
    {
        $options = [];

        foreach ($this->brands() as $brand) {
            $options[(string) $brand->getKey()] = (string) $brand->name;
        }

        return $options;
    }

    public function selectBrand(string $brandId): void
    {
        // Fail closed: the brand must be active and linked to this tenant.
        $brand = $this->resolve($brandId);

        if ($brand === null) {
            $this->notifyUnauthorized();

            return;
        }

        $this->brandId = (string) $brand->getKey();
        $this->hydrateFromBrand($brand);
    }

    public function updatedBrandId(?string $brandId): void
    {
        if ($brandId === null || $brandId === '') {
            return;
        }

        $this->selectBrand($brandId);
    }

    public function save(): void
    {
        // Page access is checked once on entry, but a Livewire request can
        // invoke save() directly. Re-derive the FULL authority here — active
        // user, verified tenant membership, the platform-scope write permission
        // and the tenant-brand link — before any write or upload.
        if (! $this->authorizeWrite()) {
            $this->notifyUnauthorized();

            return;
        }

        $tenantId = $this->tenantId();
        $brand = $this->brandId !== null ? $this->resolve($this->brandId) : null;

        if ($tenantId === null || $brand === null) {
            $this->notifyUnauthorized();

            return;
        }

        $this->validate([
            'displayName' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'language' => ['required', 'in:'.implode(',', (array) config('lba.reports.locales', ['id', 'ms-MY', 'en']))],
            'header' => ['nullable', 'string', 'max:500'],
            'footer' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'max:'.(int) config('lba.reports.logo_max_kilobytes', 2048)],
        ]);

        $attributes = [
            'report_display_name' => $this->nullOr($this->displayName),
            'report_address' => $this->nullOr($this->address),
            'report_contact' => $this->nullOr($this->contact),
            'report_website' => $this->nullOr($this->website),
            'report_language' => $this->language,
            'report_header' => $this->nullOr($this->header),
            'report_footer' => $this->nullOr($this->footer),
        ];

        if ($this->logo !== null) {
            $disk = (string) config('lba.reports.disk', 'lba-reports');
            $prefix = trim((string) config('lba.reports.logo_prefix', 'brand-logos'), '/');
            $path = $prefix.'/'.(string) $brand->getKey().'/'.strtolower((string) Str::ulid()).'.'.$this->logo->getClientOriginalExtension();

            Storage::disk($disk)->put($path, $this->logo->get(), ['visibility' => 'private']);

            $attributes['report_logo_disk'] = $disk;
            $attributes['report_logo_path'] = $path;
        }

        $brand->forceFill($attributes)->save();

        $this->logo = null;

        Notification::make()->success()->title(__('lba.brand_report.saved'))->send();
    }

    public function hasLogo(): bool
    {
        $brand = $this->brandId !== null ? $this->resolve($this->brandId) : null;

        return $brand !== null && $brand->hasReportLogo();
    }

    /**
     * @return array<string, string>
     */
    public function languageOptions(): array
    {
        $labels = (array) config('localization.labels', []);

        $options = [];

        foreach ((array) config('lba.reports.locales', ['id', 'ms-MY', 'en']) as $locale) {
            $options[$locale] = $labels[$locale] ?? $locale;
        }

        return $options;
    }

    /**
     * Re-derive the authority to MODIFY brand report settings.
     *
     * A brand is a SHARED, central catalog entry: its report identity is global,
     * so being LINKED to a brand is NOT authority to change it. Writing (or
     * uploading a logo) requires, on every call: an active user, an active
     * membership in a verified tenant, and the platform-scope
     * `brand.metadata.manage` capability. A tenant-scoped operator can therefore
     * never mutate shared global brand settings merely because the brand is
     * linked to their tenant.
     */
    private function authorizeWrite(): bool
    {
        if (static::currentUser() === null || $this->tenantId() === null) {
            return false;
        }

        return static::allows(PermissionKey::BRAND_METADATA_MANAGE);
    }

    /**
     * Resolve a configurable brand, or null when it is unknown, suspended or not
     * linked to the verified tenant.
     */
    private function resolve(string $brandId): ?Brand
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return null;
        }

        try {
            return app(BrandDirectory::class)->resolveConfigurable($tenantId, $brandId);
        } catch (Throwable) {
            return null;
        }
    }

    private function hydrateFromBrand(Brand $brand): void
    {
        $this->displayName = (string) ($brand->report_display_name ?? '');
        $this->address = (string) ($brand->report_address ?? '');
        $this->contact = (string) ($brand->report_contact ?? '');
        $this->website = (string) ($brand->report_website ?? '');
        $this->language = $brand->reportLanguage();
        $this->header = (string) ($brand->report_header ?? '');
        $this->footer = (string) ($brand->report_footer ?? '');
        $this->logo = null;
    }

    private function nullOr(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function notifyUnauthorized(): void
    {
        Notification::make()->danger()->title(__('lba.review.not_authorized'))->send();
    }
}
