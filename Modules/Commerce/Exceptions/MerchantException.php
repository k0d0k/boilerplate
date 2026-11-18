<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * WS8.A — a merchant-of-record rule violation.
 *
 * Thrown when an explicit merchant mapping is required but is missing, inactive
 * or scoped to a different currency — so a payment is never taken through an
 * unauthorized or ambiguous merchant.
 */
final class MerchantException extends RuntimeException
{
    public static function inactive(string $merchantId): self
    {
        return new self(sprintf('Merchant [%s] is not active and may not take payment.', $merchantId));
    }

    public static function currencyMismatch(string $merchantId, string $expected, string $given): self
    {
        return new self(sprintf(
            'Merchant [%s] settles in %s, not the order currency [%s].',
            $merchantId,
            $expected,
            $given,
        ));
    }

    public static function unknown(string $merchantId): self
    {
        return new self(sprintf('Unknown merchant [%s] for the current tenant.', $merchantId));
    }

    public static function missingForCurrency(string $currency): self
    {
        return new self(sprintf('No active merchant of record is configured for %s.', $currency));
    }
}
