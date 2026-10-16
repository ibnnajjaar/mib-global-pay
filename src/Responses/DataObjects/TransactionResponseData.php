<?php

namespace IbnNajjaar\MIBGlobalPay\Responses\DataObjects;

use IbnNajjaar\MIBGlobalPay\Support\Arr;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;
use IbnNajjaar\MIBGlobalPay\Support\Constants\TransactionType;
use IbnNajjaar\MIBGlobalPay\Support\Constants\TransactionResult;

/**
 * A single transaction: an element of order.transaction[] in Retrieve Order,
 * a Retrieve Transaction response, or the response to Refund, Void, Capture
 * and Update Authorization.
 */
class TransactionResponseData implements IsResponseData
{
    private $type;
    private $result;
    private $gateway_code;
    private $raw_response;

    public function __construct(?string $result, ?string $gateway_code, ?string $type, ?array $raw_response)
    {
        $this->type = $type;
        $this->result = $result;
        $this->gateway_code = $gateway_code;
        $this->raw_response = $raw_response;
    }

    public static function fromArray(array $response): IsResponseData
    {
        return new self(
            Arr::lower(Arr::get($response, 'result')),
            Arr::lower(Arr::get($response, 'response.gatewayCode')),
            Arr::lower(Arr::get($response, 'transaction.type')),
            $response
        );
    }

    public function getType(): ?string
    {
        return $this->type ? strtolower($this->type) : null;
    }

    public function getResult(): ?string
    {
        return $this->result ? strtolower($this->result) : null;
    }

    public function getGatewayCode(): ?string
    {
        return $this->gateway_code ? strtolower($this->gateway_code) : null;
    }

    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }

    public function getTransactionId(): ?string
    {
        return Arr::string($this->raw('transaction.id'));
    }

    public function getAmount(): ?float
    {
        return Arr::float($this->raw('transaction.amount'));
    }

    public function getCurrency(): ?string
    {
        return Arr::string($this->raw('transaction.currency'));
    }

    public function getTargetTransactionId(): ?string
    {
        return Arr::string($this->raw('transaction.targetTransactionId'));
    }

    public function getReference(): ?string
    {
        return Arr::string($this->raw('transaction.reference'));
    }

    public function getReceipt(): ?string
    {
        return Arr::string($this->raw('transaction.receipt'));
    }

    public function getAuthorizationCode(): ?string
    {
        return Arr::string($this->raw('transaction.authorizationCode'));
    }

    public function getAcquirerCode(): ?string
    {
        return Arr::string($this->raw('response.acquirerCode'));
    }

    public function getTimeOfRecord(): ?string
    {
        return Arr::string($this->raw('timeOfRecord'));
    }

    public function getOrderId(): ?string
    {
        return Arr::string($this->raw('order.id'));
    }

    public function getOrderStatus(): ?string
    {
        return Arr::lower($this->raw('order.status'));
    }

    public function isSuccessful(): bool
    {
        return $this->getResult() === TransactionResult::SUCCESS;
    }

    public function isPending(): bool
    {
        return $this->getResult() === TransactionResult::PENDING;
    }

    public function isFailure(): bool
    {
        return in_array($this->getResult(), [TransactionResult::FAILURE, TransactionResult::ERROR], true);
    }

    public function isUnknown(): bool
    {
        return $this->getResult() === TransactionResult::UNKNOWN;
    }

    /**
     * Gateway code is APPROVED, APPROVED_PENDING_SETTLEMENT, APPROVED_AUTO, ...
     */
    public function isApproved(): bool
    {
        $code = $this->getGatewayCode();

        return $code !== null && strpos($code, 'approved') === 0;
    }

    public function isPayment(): bool
    {
        return $this->getType() === TransactionType::PAYMENT;
    }

    public function isRefund(): bool
    {
        return $this->getType() === TransactionType::REFUND;
    }

    public function isCapture(): bool
    {
        return $this->getType() === TransactionType::CAPTURE;
    }

    public function isAuthorization(): bool
    {
        return $this->getType() === TransactionType::AUTHORIZATION;
    }

    public function isVoid(): bool
    {
        $type = $this->getType();

        return $type !== null && strpos($type, 'void') === 0;
    }

    /**
     * @return mixed
     */
    private function raw(string $path)
    {
        return Arr::get($this->raw_response ?? [], $path);
    }
}
