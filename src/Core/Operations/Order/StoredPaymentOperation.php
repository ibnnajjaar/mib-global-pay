<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Requests\StoredPayment;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Base for server-side transactions paid with a saved token (PAY, AUTHORIZE, VERIFY).
 */
abstract readonly class StoredPaymentOperation extends TransactionOperation
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId, string $transactionId, public StoredPayment $payment, ?string $reference = null)
    {
        parent::__construct($orderId, $transactionId, $reference);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidRequest
     */
    abstract protected function orderFields(GatewayConfig $config): array;

    protected function transactionFields(GatewayConfig $config): array
    {
        return [];
    }

    public function body(GatewayConfig $config): array
    {
        /** @var array<string, mixed> */
        return RequestBody::withoutNulls(RequestBody::merge(
            RequestBody::merge(parent::body($config), $this->payment->toBody()),
            ['order' => $this->orderFields($config)],
        ));
    }
}
