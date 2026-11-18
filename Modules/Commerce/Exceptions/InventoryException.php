<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * WS3 — inventory rule violation.
 *
 * Thrown when a stock operation would break an invariant — most importantly
 * when an issue would drive a branch's stock below zero, which is never
 * allowed.
 */
final class InventoryException extends RuntimeException
{
    public static function insufficientStock(string $productId, string $branchId, int $available, int $requested): self
    {
        return new self(sprintf(
            'Insufficient stock for product [%s] at branch [%s]: %d available, %d requested.',
            $productId,
            $branchId,
            $available,
            $requested,
        ));
    }

    public static function unknownProduct(string $productId): self
    {
        return new self(sprintf('Unknown product [%s] for the current tenant.', $productId));
    }

    public static function unknownBranch(string $branchId): self
    {
        return new self(sprintf('Unknown branch [%s] for the current tenant.', $branchId));
    }

    public static function invalidQuantity(int $quantity): self
    {
        return new self(sprintf('Invalid stock quantity [%d].', $quantity));
    }

    public static function sameBranch(string $branchId): self
    {
        return new self(sprintf('A stock transfer needs two different branches, both were [%s].', $branchId));
    }
}
