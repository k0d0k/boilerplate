<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Services\SinaWalletService;

class TokenWallet extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'dompet-token';

    protected static ?int $navigationSort = 17;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected string $view = 'filament.patient.pages.token-wallet';

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::WALLET_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.wallet');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return static::getNavigationLabel();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        $patient = $this->requirePatient();
        $tenantId = app(ManagesTenantContext::class)->currentTenantId(static::patientUser());
        abort_if($tenantId === null, 403);

        $patientId = (string) $patient->getKey();
        $wallet = app(SinaWalletService::class)->walletFor($tenantId, $patientId);
        $usage = SinaUsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['recorded_at', 'credit_units']);

        return [
            'wallet' => $wallet,
            'usage' => $usage,
        ];
    }
}
