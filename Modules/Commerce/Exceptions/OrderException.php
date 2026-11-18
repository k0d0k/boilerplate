<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * WS3 — an order / checkout rule violation.
 *
 * Thrown when an order cannot be placed or moved through its lifecycle: an
 * empty cart, a product that is not priced in the requested currency, an order
 * that is already settled (duplicate payment), or an illegal status change.
 */
final class OrderException extends RuntimeException
{
    public static function emptyCart(): self
    {
        return new self('An order must contain at least one line.');
    }

    public static function unpricedProduct(string $productId, string $currency): self
    {
        return new self(sprintf('Product [%s] has no price in %s.', $productId, $currency));
    }

    public static function invalidQuantity(int $quantity): self
    {
        return new self(sprintf('Invalid order quantity [%d].', $quantity));
    }

    public static function productUnavailable(string $productId): self
    {
        return new self(sprintf('Product [%s] is not available for sale.', $productId));
    }

    public static function unknownBranch(string $branchId): self
    {
        return new self(sprintf('Unknown branch [%s] for the current tenant.', $branchId));
    }

    public static function alreadySettled(string $orderNumber): self
    {
        return new self(sprintf('Order [%s] is already fully paid.', $orderNumber));
    }

    public static function notOpen(string $orderNumber): self
    {
        return new self(sprintf('Order [%s] can no longer be modified.', $orderNumber));
    }

    public static function notRefundable(string $orderNumber): self
    {
        return new self(sprintf('Order [%s] has no settled payment to refund.', $orderNumber));
    }

    public static function alreadyRefunded(string $orderNumber): self
    {
        return new self(sprintf('Order [%s] has already been refunded.', $orderNumber));
    }

    public static function overRefund(string $orderNumber): self
    {
        return new self(sprintf('Refund exceeds the settled amount of order [%s].', $orderNumber));
    }

    public static function overPayment(string $orderNumber): self
    {
        return new self(sprintf('Payment exceeds the outstanding balance of order [%s].', $orderNumber));
    }

    public static function currencyMismatch(string $expected, string $given): self
    {
        return new self(sprintf('Payment currency [%s] does not match the order currency [%s].', $given, $expected));
    }
}
