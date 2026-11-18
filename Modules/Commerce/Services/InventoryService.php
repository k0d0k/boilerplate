<?php

namespace Modules\Commerce\Services;

use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Illuminate\Support\Collection;
use Modules\Commerce\Exceptions\InventoryException;
use Modules\Commerce\Models\InventoryStock;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Models\StockMovement;

/**
 * WS3 — branch inventory operations.
 *
 * The single place stock ever changes. Every mutation runs inside a transaction
 * against the VERIFIED tenant database and:
 *  - re-validates that the product and branch belong to the current tenant (a
 *    foreign id can never resolve, so a cross-tenant movement is impossible);
 *  - locks the stock row before changing it, so concurrent issues cannot race
 *    past zero;
 *  - appends an immutable {@see StockMovement} recording the signed delta and
 *    the resulting balance.
 *
 * An issue that would drive a branch's stock below zero throws an
 * {@see InventoryException} — negative stock is never recorded.
 */
final class InventoryService
{
    use RunsVerifiedTenantTransactions;

    public function level(Product $product, string $branchId): int
    {
        return (int) (InventoryStock::query()
            ->where('product_id', $product->getKey())
            ->where('branch_id', $branchId)
            ->value('quantity') ?? 0);
    }

    /**
     * Stock in: add to the branch level (a receipt of goods or a refund return).
     */
    public function receive(
        Product $product,
        string $branchId,
        int $quantity,
        ?User $actor = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        string $type = StockMovement::TYPE_RECEIPT,
    ): InventoryStock {
        if ($quantity < 1) {
            throw InventoryException::invalidQuantity($quantity);
        }

        return $this->apply($product, $branchId, $quantity, $type, $actor, $reason, $referenceType, $referenceId);
    }

    /**
     * Stock out: remove from the branch level. Refuses to go negative.
     */
    public function issue(
        Product $product,
        string $branchId,
        int $quantity,
        ?User $actor = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        string $type = StockMovement::TYPE_ISSUE,
    ): InventoryStock {
        if ($quantity < 1) {
            throw InventoryException::invalidQuantity($quantity);
        }

        return $this->apply($product, $branchId, -$quantity, $type, $actor, $reason, $referenceType, $referenceId);
    }

    /**
     * Move stock from one branch to another of the SAME tenant.
     *
     * Both stock rows are first ensured to exist, then locked in a
     * deterministic branch-id order (so concurrent opposite transfers cannot
     * deadlock), and the paired movements are appended in one transaction. The
     * source branch is never driven below zero, and the two movements
     * (`transfer_out` / `transfer_in`) reference each other immutably, so the
     * ledger always balances.
     */
    public function transfer(
        Product $product,
        string $fromBranchId,
        string $toBranchId,
        int $quantity,
        ?User $actor = null,
        ?string $reason = null,
    ): void {
        if ($quantity < 1) {
            throw InventoryException::invalidQuantity($quantity);
        }

        if ($fromBranchId === $toBranchId) {
            throw InventoryException::sameBranch($fromBranchId);
        }

        $this->tenantTransaction(function () use ($product, $fromBranchId, $toBranchId, $quantity, $actor, $reason): void {
            if (! Product::query()->whereKey($product->getKey())->exists()) {
                throw InventoryException::unknownProduct((string) $product->getKey());
            }

            foreach ([$fromBranchId, $toBranchId] as $branchId) {
                if (! TenantBranch::query()->whereKey($branchId)->exists()) {
                    throw InventoryException::unknownBranch($branchId);
                }

                $this->ensureStock($product, $branchId);
            }

            /** @var Collection<string, InventoryStock> $rows */
            $rows = InventoryStock::query()
                ->where('product_id', $product->getKey())
                ->whereIn('branch_id', [$fromBranchId, $toBranchId])
                ->orderBy('branch_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('branch_id');

            $from = $rows[$fromBranchId];
            $available = (int) $from->quantity;

            if ($available < $quantity) {
                throw InventoryException::insufficientStock(
                    (string) $product->getKey(),
                    $fromBranchId,
                    $available,
                    $quantity,
                );
            }

            $to = $rows[$toBranchId];
            $fromBalance = $available - $quantity;
            $toBalance = (int) $to->quantity + $quantity;

            $from->forceFill(['quantity' => $fromBalance])->save();
            $to->forceFill(['quantity' => $toBalance])->save();

            $this->record($product, $fromBranchId, -$quantity, $fromBalance, StockMovement::TYPE_TRANSFER_OUT, $actor, $reason, TenantBranch::class, $toBranchId);
            $this->record($product, $toBranchId, $quantity, $toBalance, StockMovement::TYPE_TRANSFER_IN, $actor, $reason, TenantBranch::class, $fromBranchId);
        });
    }

    /**
     * Set the branch level to an exact count (a stock-take correction). The
     * delta — positive or negative — is what is recorded.
     */
    public function adjustTo(
        Product $product,
        string $branchId,
        int $newQuantity,
        ?User $actor = null,
        ?string $reason = null,
    ): InventoryStock {
        if ($newQuantity < 0) {
            throw InventoryException::invalidQuantity($newQuantity);
        }

        return $this->tenantTransaction(function () use ($product, $branchId, $newQuantity, $actor, $reason): InventoryStock {
            $stock = $this->lockOrCreate($product, $branchId);
            $current = (int) $stock->quantity;
            $delta = $newQuantity - $current;

            $stock->forceFill(['quantity' => $newQuantity])->save();

            if ($delta !== 0) {
                $this->record($product, $branchId, $delta, $newQuantity, StockMovement::TYPE_ADJUSTMENT, $actor, $reason);
            }

            return $stock;
        });
    }

    private function apply(
        Product $product,
        string $branchId,
        int $delta,
        string $type,
        ?User $actor,
        ?string $reason,
        ?string $referenceType,
        ?string $referenceId,
    ): InventoryStock {
        return $this->tenantTransaction(function () use ($product, $branchId, $delta, $type, $actor, $reason, $referenceType, $referenceId): InventoryStock {
            $stock = $this->lockOrCreate($product, $branchId);
            $current = (int) $stock->quantity;
            $resulting = $current + $delta;

            if ($resulting < 0) {
                throw InventoryException::insufficientStock(
                    (string) $product->getKey(),
                    $branchId,
                    $current,
                    abs($delta),
                );
            }

            $stock->forceFill(['quantity' => $resulting])->save();

            $this->record($product, $branchId, $delta, $resulting, $type, $actor, $reason, $referenceType, $referenceId);

            return $stock;
        });
    }

    /**
     * Ensure a (product, branch) stock row exists (at zero) without locking it.
     */
    private function ensureStock(Product $product, string $branchId): void
    {
        InventoryStock::query()->firstOrCreate([
            'product_id' => $product->getKey(),
            'branch_id' => $branchId,
        ], [
            'quantity' => 0,
        ]);
    }

    /**
     * Lock the (product, branch) stock row, creating it at zero when absent.
     * Fail-closed validation of the product and branch happens first.
     */
    private function lockOrCreate(Product $product, string $branchId): InventoryStock
    {
        if (! Product::query()->whereKey($product->getKey())->exists()) {
            throw InventoryException::unknownProduct((string) $product->getKey());
        }

        if (! TenantBranch::query()->whereKey($branchId)->exists()) {
            throw InventoryException::unknownBranch($branchId);
        }

        InventoryStock::query()->firstOrCreate([
            'product_id' => $product->getKey(),
            'branch_id' => $branchId,
        ], [
            'quantity' => 0,
        ]);

        return InventoryStock::query()
            ->where('product_id', $product->getKey())
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function record(
        Product $product,
        string $branchId,
        int $delta,
        int $balanceAfter,
        string $type,
        ?User $actor,
        ?string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): void {
        StockMovement::query()->create([
            'product_id' => $product->getKey(),
            'branch_id' => $branchId,
            'type' => $type,
            'quantity' => $delta,
            'balance_after' => $balanceAfter,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actor?->getKey(),
            'recorded_at' => now(),
        ]);
    }
}
