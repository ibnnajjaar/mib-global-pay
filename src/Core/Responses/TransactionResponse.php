<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Enums\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayRecommendation;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Verification\ApprovalPolicy;

/**
 * One transaction: an element of Retrieve Order's transaction[], a Retrieve Transaction
 * response, or the response to Capture, Refund, Void and Update Authorization.
 *
 * A declined operation is a TransactionResponse with result FAILURE, not an exception.
 */
final readonly class TransactionResponse
{
    private function __construct(
        public Result $result,
        public GatewayCode $gatewayCode,
        public GatewayRecommendation $gatewayRecommendation,
        public TransactionType $type,
        public ?string $transactionId,
        public ?Money $amount,
        public ?string $targetTransactionId,
        public ?string $reference,
        public ?string $receipt,
        public ?string $authorizationCode,
        public ?string $acquirerCode,
        public ?string $acquirerMessage,
        public ?\DateTimeImmutable $timeOfRecord,
        public ?string $orderId,
        public OrderStatus $orderStatus,
        public Payload $payload,
    ) {
    }

    /**
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            result: $p->enum('result', Result::class),
            gatewayCode: $p->enum('response.gatewayCode', GatewayCode::class),
            gatewayRecommendation: $p->enum('response.gatewayRecommendation', GatewayRecommendation::class),
            type: $p->enum('transaction.type', TransactionType::class),
            transactionId: $p->string('transaction.id'),
            amount: $p->money('transaction.amount', 'transaction.currency'),
            targetTransactionId: $p->string('transaction.targetTransactionId'),
            reference: $p->string('transaction.reference'),
            receipt: $p->string('transaction.receipt'),
            authorizationCode: $p->string('transaction.authorizationCode'),
            acquirerCode: $p->string('response.acquirerCode'),
            acquirerMessage: $p->string('response.acquirerMessage'),
            timeOfRecord: $p->dateTime('timeOfRecord'),
            orderId: $p->string('order.id'),
            orderStatus: $p->enum('order.status', OrderStatus::class),
            payload: $p,
        );
    }

    /** The single approval rule (see ApprovalPolicy). */
    public function isApproved(ApprovalPolicy $policy): bool
    {
        return $policy->isApproved($this->result, $this->gatewayCode);
    }

    public function isSuccessful(): bool
    {
        return $this->result === Result::Success;
    }

    public function isFailure(): bool
    {
        return $this->result === Result::Failure || $this->result === Result::Error;
    }

    public function isPending(): bool
    {
        return $this->result === Result::Pending;
    }

    /** The raw gateway strings, for logging and for values this version does not recognize. */
    public function rawResult(): ?string
    {
        return $this->payload->string('result');
    }

    public function rawGatewayCode(): ?string
    {
        return $this->payload->string('response.gatewayCode');
    }

    public function rawType(): ?string
    {
        return $this->payload->string('transaction.type');
    }
}
