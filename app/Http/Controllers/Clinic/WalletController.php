<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Models\AiUsageRecord;
use App\Platform\AiBilling\Models\TenantAiWalletLedgerEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\LBA\Filament\Pages\AiWalletOverview;

/**
 * TailAdmin AI credit wallet for the Clinic panel.
 *
 * The Blade counterpart of {@see AiWalletOverview}: the verified tenant's AI
 * credit balance, the immutable AI usage history and the wallet ledger, all
 * read from live accounting data (never sample figures). Only non-identifying
 * accounting data is shown — no clinical image, no patient identity — and every
 * query is scoped to the verified current tenant, so another tenant's wallet is
 * never visible.
 */
final class WalletController extends Controller
{
    public function index(Request $request, ManagesTenantContext $tenants): View
    {
        abort_unless(AiWalletOverview::canAccess(), 403);

        $user = $request->user();
        $tenantId = $tenants->currentTenantId($user);

        $wallet = $tenantId !== null
            ? app(AiWalletService::class)->walletFor($tenantId)
            : null;

        $available = (int) ($wallet?->available_credits ?? 0);

        $usage = $tenantId !== null
            ? AiUsageRecord::query()->where('tenant_id', $tenantId)->orderByDesc('recorded_at')->limit(25)->get()
            : collect();

        $ledger = $tenantId !== null
            ? TenantAiWalletLedgerEntry::query()->where('tenant_id', $tenantId)->orderByDesc('created_at')->limit(25)->get()
            : collect();

        return view('clinic.tailadmin.wallet', [
            'tenantId' => $tenantId,
            'available' => $available,
            'reserved' => (int) ($wallet?->reserved_credits ?? 0),
            'consumed' => (int) ($wallet?->lifetime_consumed_credits ?? 0),
            'billingEnabled' => (bool) config('lba.ai.billing.enabled', false),
            'insufficient' => $wallet === null || $available <= 0,
            'usage' => $usage,
            'ledger' => $ledger,
            'links' => WorkspaceController::navigation(),
        ]);
    }
}
