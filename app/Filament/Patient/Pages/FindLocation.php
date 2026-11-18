<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Branches\Models\TenantBranch;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Phase 8A — "Cari Lokasi LBA".
 *
 * A read-only listing of every ACTIVE branch (clinic location) of the verified
 * tenant. It exposes only non-clinical, public-facing location details — never
 * patient, examination or staff data. A deactivated branch is never listed.
 */
class FindLocation extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'lokasi';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected string $view = 'filament.patient.pages.find-location';

    public static function canAccess(): bool
    {
        return static::hasPatientContext();
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.find_location');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.locations.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.locations.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.locations.subtitle');
    }

    /**
     * Every active branch of the verified tenant, alphabetically. Fails closed
     * to an empty list.
     *
     * @return Collection<int, TenantBranch>
     */
    public function branches(): Collection
    {
        try {
            return TenantBranch::query()
                ->active()
                ->orderBy('name')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function addressLine(TenantBranch $branch): string
    {
        $parts = array_filter([
            $branch->address,
            $branch->city,
            $branch->region,
            $branch->country,
        ], static fn (?string $part): bool => filled($part));

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /**
     * The booking URL pre-selected for this branch, so "Cari Lokasi" and
     * "Booking Pemeriksaan" are one continuous flow. The target branch is only
     * a HINT: the booking page re-validates it against its own active-branch
     * list before adopting it.
     */
    public function bookingUrl(TenantBranch $branch): string
    {
        return Booking::getUrl(['branch' => $branch->getKey()], panel: 'patient');
    }
}
