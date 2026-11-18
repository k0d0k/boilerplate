<?php

namespace Modules\Commerce\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Modules\Commerce\Exceptions\MerchantException;
use Modules\Commerce\Models\Merchant;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Support\Currency;

/**
 * WS8.A — the merchant-of-record resolver and writer.
 *
 * The single place a merchant mapping is created or resolved. Everything runs on
 * the VERIFIED tenant database, so a merchant can never be read or written
 * across partners.
 *
 * Resolution is deliberately conservative: when MORE THAN ONE active merchant
 * exists for a currency (and none is the tenant default), {@see self::forCurrency}
 * returns null rather than guessing, so money is never attributed to an
 * arbitrary merchant. A caller that needs a merchant for a payment uses
 * {@see self::requireForCurrency}, which fails closed.
 */
final class MerchantRegistry
{
    use RunsVerifiedTenantTransactions;

    public function __construct(private readonly ResolvesTenantDatabase $databases) {}

    /**
     * Create (or update) the merchant-of-record mapping for a brand/currency.
     *
     * @param  array{
     *     name: string,
     *     currency: string,
     *     brand_id?: string|null,
     *     legal_name?: string|null,
     *     country?: string|null,
     *     tax_id?: string|null,
     *     invoice_issuer?: string|null,
     *     settlement_provider?: string|null,
     *     settlement_reference?: string|null,
     *     status?: string,
     *     metadata?: array<string, mixed>|null,
     * }  $attributes
     */
    public function register(User $actor, array $attributes): Merchant
    {
        return $this->tenantTransaction(function () use ($actor, $attributes): Merchant {
            $attributes['currency'] = Currency::assertSupported((string) ($attributes['currency'] ?? Currency::IDR));

            $merchant = new Merchant;
            $merchant->fill($attributes);
            $merchant->forceFill([
                'status' => $attributes['status'] ?? Merchant::STATUS_ACTIVE,
                'created_by' => (string) $actor->getKey(),
            ]);
            $merchant->save();

            return $merchant;
        });
    }

    /**
     * The unambiguous active merchant of record for a currency, or null when
     * none — or more than one — is configured. The tenant default (no brand) is
     * preferred when several exist.
     */
    public function forCurrency(string $currency): ?Merchant
    {
        $currency = Currency::assertSupported($currency);

        if ($this->databases->current() === null) {
            return null;
        }

        $merchants = Merchant::query()->active()->where('currency', $currency)->get();

        if ($merchants->count() === 1) {
            return $merchants->first();
        }

        return $merchants->firstWhere('brand_id', null);
    }

    /**
     * Like {@see self::forCurrency} but fails closed when no unambiguous
     * merchant exists — used before an external payment is initiated.
     */
    public function requireForCurrency(string $currency): Merchant
    {
        return $this->forCurrency($currency) ?? throw MerchantException::missingForCurrency(strtoupper($currency));
    }

    /**
     * The merchant owning an order (nullable — legacy orders keep null).
     */
    public function forOrder(Order $order): ?Merchant
    {
        $merchantId = (string) ($order->merchant_id ?? '');

        if ($merchantId === '') {
            return null;
        }

        return Merchant::query()->whereKey($merchantId)->first();
    }

    /**
     * Assert a merchant may take a payment for the currency (active + matching).
     */
    public function assertCanSettle(Merchant $merchant, string $currency): void
    {
        if (! $merchant->isActive()) {
            throw MerchantException::inactive((string) $merchant->getKey());
        }

        if (! $merchant->settles($currency)) {
            throw MerchantException::currencyMismatch(
                (string) $merchant->getKey(),
                strtoupper((string) $merchant->currency),
                strtoupper($currency),
            );
        }
    }
}
