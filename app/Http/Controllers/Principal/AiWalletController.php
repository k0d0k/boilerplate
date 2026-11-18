<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\AiWalletResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Exceptions\AiBillingException;
use App\Platform\AiBilling\Models\TenantAiWallet;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 5 — Principal "Penggunaan & Token AI" (tenant AI credit wallets)
 * TailAdmin surface.
 *
 * Mirrors the interim Filament {@see AiWalletResource}.
 * The wallet engine ({@see AiWalletService}) accounts in the CENTRAL database
 * only: no clinical image, patient identity or finding is ever stored on a
 * wallet, ledger entry or usage record. This screen exposes the platform-wide
 * billing position — available / reserved / consumed credits per tenant — plus
 * a controlled, idempotent credit top-up.
 *
 * Viewing requires `platform.billing.manage` OR `platform.analytics.view`;
 * granting credits requires `platform.billing.manage`. A wallet is created only
 * by the engine, never by hand.
 */
final class AiWalletController extends Controller
{
    use AuthorizesPrincipalResources;

    public function index(): View
    {
        $this->authorizeView();

        $wallets = TenantAiWallet::query()
            ->with('tenant')
            ->orderByDesc('created_at')
            ->get();

        return view('principal.ai-credits.index', [
            'wallets' => $wallets,
            'canGrant' => self::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE),
        ]);
    }

    public function grant(Request $request, TenantAiWallet $wallet, AiWalletService $service): RedirectResponse
    {
        $this->authorizeGrant();

        $data = $request->validate([
            'units' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $actor = $request->user();

        try {
            $service->grant(
                (string) $wallet->tenant_id,
                (int) $data['units'],
                (string) $data['reason'],
                'principal-grant-'.Str::ulid()->toBase32(),
                $actor instanceof User ? (string) $actor->getKey() : null,
            );
        } catch (AiBillingException $exception) {
            return to_route('principal.tailadmin.ai-credits.index')
                ->withErrors(['units' => $exception->getMessage()]);
        }

        return to_route('principal.tailadmin.ai-credits.index')
            ->with('success', __('lba.wallets.granted'));
    }

    private function authorizeView(): void
    {
        abort_unless(
            self::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE)
                || self::principalCan(PermissionKey::PLATFORM_ANALYTICS_VIEW),
            403,
        );
    }

    private function authorizeGrant(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE), 403);
    }
}
