<?php

namespace Modules\LBA\Filament\Pages;

use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\AiBilling\Models\AiUsageRecord;
use App\Platform\AiBilling\Models\TenantAiWallet;
use App\Platform\AiBilling\Models\TenantAiWalletLedgerEntry;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Phase 6 — Clinic panel "Token AI & Pemakaian".
 *
 * Shows ONE verified tenant's AI credit wallet and usage: available, reserved
 * and consumed credits, the immutable AI usage history and the wallet
 * transaction (ledger) history, plus a notice when the balance cannot fund a
 * billable analysis.
 *
 * Every query is scoped to the verified current tenant, so another tenant's
 * wallet is never visible. Only non-identifying accounting data is shown — no
 * clinical image, no patient identity. Access requires the tenant AI wallet or
 * tenant billing permission.
 */
class AiWalletOverview extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'lba';

    protected static ?string $slug = 'lba/ai-wallet';

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':lba',
    ];

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected string $view = 'lba::pages.ai-wallet-overview';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive()
            && (static::allows(PermissionKey::WALLET_MANAGE)
                || static::allows(PermissionKey::TENANT_BILLING_MANAGE));
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.wallet.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.exams.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.wallet.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.wallet.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.wallet.subtitle');
    }

    /**
     * The verified current tenant, or null when none applies.
     */
    public function tenantId(): ?string
    {
        $user = static::currentUser();

        if ($user === null) {
            return null;
        }

        return app(ManagesTenantContext::class)->currentTenantId($user);
    }

    public function wallet(): ?TenantAiWallet
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return null;
        }

        return TenantAiWallet::query()->where('tenant_id', $tenantId)->first();
    }

    public function availableCredits(): int
    {
        return (int) ($this->wallet()?->available_credits ?? 0);
    }

    public function reservedCredits(): int
    {
        return (int) ($this->wallet()?->reserved_credits ?? 0);
    }

    public function consumedCredits(): int
    {
        return (int) ($this->wallet()?->lifetime_consumed_credits ?? 0);
    }

    public function billingEnabled(): bool
    {
        return (bool) config('lba.ai.billing.enabled', false);
    }

    /**
     * True when a billable analysis could not be funded right now.
     */
    public function hasInsufficientBalance(): bool
    {
        return $this->wallet() === null || $this->availableCredits() <= 0;
    }

    /**
     * @return Collection<int, AiUsageRecord>
     */
    public function usageRecords(): Collection
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return collect();
        }

        return AiUsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('recorded_at')
            ->limit(25)
            ->get();
    }

    /**
     * @return Collection<int, TenantAiWalletLedgerEntry>
     */
    public function ledgerEntries(): Collection
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return collect();
        }

        return TenantAiWalletLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();
    }
}
