<?php

namespace Modules\Commerce\Support;

use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Services\OrderService;

/**
 * WS3 — an in-memory product cart.
 *
 * A cart is a transient working set held by a cashier (POS) or a patient
 * checkout page; it is never persisted on its own. Checkout turns a cart into an
 * order through {@see OrderService}. All money is
 * computed in ONE explicit currency so a mixed-currency cart can never be
 * summed by accident.
 */
final class Cart
{
    /** @var array<string, array{product_id: string, name: string, sku: ?string, unit_price: float, quantity: int}> */
    private array $lines = [];

    public function __construct(private string $currency = Currency::IDR)
    {
        $this->currency = Currency::assertSupported($this->currency);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Add a product to the cart at its price for the cart currency. A product
     * that cannot be priced in the currency is refused rather than guessed.
     */
    public function add(Product $product, int $quantity = 1): self
    {
        if ($quantity < 1) {
            throw OrderException::invalidQuantity($quantity);
        }

        $price = $product->priceFor($this->currency);

        if ($price === null) {
            throw OrderException::unpricedProduct((string) $product->getKey(), $this->currency);
        }

        $key = (string) $product->getKey();
        $existing = $this->lines[$key]['quantity'] ?? 0;

        $this->lines[$key] = [
            'product_id' => $key,
            'name' => (string) $product->name,
            'sku' => $product->sku,
            'unit_price' => $price,
            'quantity' => $existing + $quantity,
        ];

        return $this;
    }

    public function setQuantity(string $productId, int $quantity): self
    {
        if (! isset($this->lines[$productId])) {
            return $this;
        }

        if ($quantity <= 0) {
            unset($this->lines[$productId]);

            return $this;
        }

        $this->lines[$productId]['quantity'] = $quantity;

        return $this;
    }

    public function remove(string $productId): self
    {
        unset($this->lines[$productId]);

        return $this;
    }

    public function clear(): self
    {
        $this->lines = [];

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * @return list<array{product_id: string, name: string, sku: ?string, unit_price: float, quantity: int}>
     */
    public function lines(): array
    {
        return array_values($this->lines);
    }

    public function subtotal(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $total += $line['unit_price'] * $line['quantity'];
        }

        return (float) Currency::round($total, $this->currency);
    }

    public function itemCount(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            $count += $line['quantity'];
        }

        return $count;
    }
}
