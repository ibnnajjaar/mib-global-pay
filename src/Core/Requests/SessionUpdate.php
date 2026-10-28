<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Fields for Update Session.
 *
 * @experimental Whether Update Session can change a live Hosted Checkout session is not
 *               confirmed by MIB (DESIGN-v2.md §12 Q4). Do not rely on it in the payment flow.
 */
final readonly class SessionUpdate
{
    /**
     * @param array<string, mixed> $extraFields deep-merged last; a null value removes a field
     */
    public function __construct(
        public ?Money $orderAmount = null,
        public ?string $orderDescription = null,
        public array $extraFields = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidRequest
     */
    public function toBody(GatewayConfig $config): array
    {
        if ($this->orderAmount !== null) {
            Validate::amount($this->orderAmount, 'Order amount', $config);
        }

        $body = RequestBody::withoutNulls(RequestBody::merge([
            'order' => [
                'amount' => $this->orderAmount?->toDecimal(),
                'currency' => $this->orderAmount?->currency->value,
                'description' => Validate::optionalText($this->orderDescription, 'Order description', 127),
            ],
        ], $this->extraFields));

        if ($body === []) {
            throw new InvalidRequest('Update Session needs at least one field to update');
        }

        /** @var array<string, mixed> */
        return $body;
    }
}
