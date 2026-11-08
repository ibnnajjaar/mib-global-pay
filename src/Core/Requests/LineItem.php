<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * One order.item[n]: what the payer is buying. Healthcare purchases use these too.
 */
final readonly class LineItem
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(
        public string $name,
        public int $quantity,
        public Money $unitPrice,
        public ?string $description = null,
        public ?string $sku = null,
        public ?string $brand = null,
        public ?string $category = null,
        public ?Money $unitTaxAmount = null,
    ) {
        if ($name === '') {
            throw new InvalidRequest('Line item name must not be empty');
        }

        if ($quantity < 1) {
            throw new InvalidRequest('Line item quantity must be at least 1');
        }
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice->toDecimal(),
            'description' => $this->description,
            'sku' => $this->sku,
            'brand' => $this->brand,
            'category' => $this->category,
            'unitTaxAmount' => $this->unitTaxAmount?->toDecimal(),
        ];
    }
}
