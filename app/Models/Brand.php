<?php

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Central brand catalog entry (Phase 1B-2A).
 *
 * A brand is a commercial identity only — it is never a tenant and never a
 * branch (ARCH-05) and holds no clinical data. Phase 7B adds the brand's
 * REPORT IDENTITY (display name, address, contact, website, default report
 * language, header/footer and a private logo object key): this is commercial
 * presentation metadata only — never patient data and never clinical content.
 */
#[Fillable([
    'name',
    'slug',
    'status',
    'metadata',
    'report_display_name',
    'report_address',
    'report_contact',
    'report_website',
    'report_language',
    'report_header',
    'report_footer',
    'report_logo_disk',
    'report_logo_path',
])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** Default report language when a brand has none configured. */
    public const REPORT_LANGUAGE_DEFAULT = 'id';

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
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_brands')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The name shown on a report header: the configured report display name when
     * present, otherwise the brand's own name.
     */
    public function reportDisplayName(): string
    {
        return $this->report_display_name ?: (string) $this->name;
    }

    /**
     * The default report language, always one of the supported locales.
     */
    public function reportLanguage(): string
    {
        $language = $this->report_language ?: self::REPORT_LANGUAGE_DEFAULT;

        return in_array($language, (array) config('localization.supported', ['id']), true)
            ? $language
            : self::REPORT_LANGUAGE_DEFAULT;
    }

    public function hasReportLogo(): bool
    {
        return $this->report_logo_path !== null && $this->report_logo_path !== '';
    }

    /**
     * A fully-resolved, immutable report identity for snapshotting onto a report
     * version. Commercial presentation metadata only.
     *
     * @return array<string, mixed>
     */
    public function reportIdentity(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'display_name' => $this->reportDisplayName(),
            'address' => $this->report_address,
            'contact' => $this->report_contact,
            'website' => $this->report_website,
            'language' => $this->reportLanguage(),
            'header' => $this->report_header,
            'footer' => $this->report_footer,
            'logo_disk' => $this->report_logo_disk,
            'logo_path' => $this->report_logo_path,
        ];
    }
}
