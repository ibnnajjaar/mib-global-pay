<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * Base for PUT /order/{orderId}/transaction/{transactionId} operations.
 *
 * The transaction ID must be new and unique within the order. Persist it before sending:
 * resending the identical request with the same ID is safe, which is why these
 * operations are idempotent.
 *
 * @implements Operation<TransactionResponse>
 */
abstract readonly class TransactionOperation implements Operation
{
    public string $orderId;

    public string $transactionId;

    public ?string $reference;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId, string $transactionId, ?string $reference = null)
    {
        $this->orderId = Validate::identifier($orderId, 'Order ID');
        $this->transactionId = Validate::identifier($transactionId, 'Transaction ID');
        $this->reference = $reference === null || $reference === '' ? null : Validate::identifier($reference, 'Transaction reference');
    }

    abstract protected function apiOperation(): string;

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidRequest
     */
    abstract protected function transactionFields(GatewayConfig $config): array;

    public function method(): HttpMethod
    {
        return HttpMethod::Put;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return 'order/' . RequestBody::segment($this->orderId) . '/transaction/' . RequestBody::segment($this->transactionId);
    }

    public function body(GatewayConfig $config): array
    {
        /** @var array<string, mixed> */
        return RequestBody::withoutNulls([
            'apiOperation' => $this->apiOperation(),
            'transaction' => [...$this->transactionFields($config), 'reference' => $this->reference],
        ]);
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function parse(Payload $payload, GatewayConfig $config): TransactionResponse
    {
        return TransactionResponse::fromPayload($payload);
    }
}
