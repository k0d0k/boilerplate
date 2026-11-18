<?php

namespace Modules\Commerce\Payments\Exceptions;

use RuntimeException;

/**
 * WS3 — a payment gateway / webhook rule violation.
 *
 * Live payments fail closed here: a charge is refused unless the master switch
 * is on, the gateway row is enabled, its credentials are complete and the row
 * has been verified.
 */
final class PaymentException extends RuntimeException
{
    public static function unknownProvider(string $provider): self
    {
        return new self(sprintf('Unknown payment provider [%s].', $provider));
    }

    public static function gatewayMissing(string $provider): self
    {
        return new self(sprintf('Payment gateway [%s] is not configured on this platform.', $provider));
    }

    public static function gatewayDisabled(string $provider): self
    {
        return new self(sprintf('Payment gateway [%s] is disabled.', $provider));
    }

    public static function notConfigured(string $provider): self
    {
        return new self(sprintf('Payment gateway [%s] has no usable credentials.', $provider));
    }

    public static function liveDisabled(string $provider): self
    {
        return new self(sprintf('Live payments are disabled; gateway [%s] cannot charge in live mode.', $provider));
    }

    public static function notVerified(string $provider): self
    {
        return new self(sprintf('Payment gateway [%s] has not been verified for live payments.', $provider));
    }

    public static function unsupportedCurrency(string $provider, string $currency): self
    {
        return new self(sprintf('Payment gateway [%s] does not support currency [%s].', $provider, $currency));
    }

    public static function invalidSignature(string $provider): self
    {
        return new self(sprintf('Rejected an unverified [%s] webhook.', $provider));
    }

    public static function chargeFailed(string $provider, string $reason): self
    {
        return new self(sprintf('[%s] charge failed: %s', $provider, $reason));
    }

    public static function chargeInProgress(string $provider, string $orderNumber): self
    {
        return new self(sprintf('A [%s] charge for order [%s] is already in progress.', $provider, $orderNumber));
    }

    public static function webhookCurrencyMismatch(string $provider): self
    {
        return new self(sprintf('Rejected a [%s] webhook whose currency contradicts the payment intent.', $provider));
    }

    public static function webhookAmountMismatch(string $provider): self
    {
        return new self(sprintf('Rejected a [%s] webhook whose amount exceeds the payment intent.', $provider));
    }

    public static function unresolvedWebhook(string $provider): self
    {
        return new self(sprintf('The [%s] webhook could not be matched to a local order.', $provider));
    }
}
