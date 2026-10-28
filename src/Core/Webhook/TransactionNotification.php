<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Enums\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * Notification about an order transaction. The body has the shape of the operation's
 * transaction response (order + transaction + response), in the API version that submitted it.
 */
final readonly class TransactionNotification implements Notification
{
    private function __construct(
        private string $id,
        private ?int $attempt,
        public string $orderId,
        public OrderStatus $orderStatus,
        public ?Money $orderTotalCaptured,
        public TransactionResponse $transaction,
        private Payload $payload,
    ) {
    }

    /**
     * @internal built by NotificationVerifier
     *
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $payload, string $id, ?int $attempt): self
    {
        return new self(
            $id,
            $attempt,
            $payload->requireString('order.id'),
            $payload->enum('order.status', OrderStatus::class),
            $payload->money('order.totalCapturedAmount', 'order.currency'),
            TransactionResponse::fromPayload($payload),
            $payload,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function attempt(): ?int
    {
        return $this->attempt;
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::Transaction;
    }

    public function payload(): Payload
    {
        return $this->payload;
    }

    public function transactionId(): ?string
    {
        return $this->transaction->transactionId;
    }

    /** PAYMENT or CAPTURE: a candidate for "payment captured"; confirm with verifyCapture(). */
    public function isMoneyIn(): bool
    {
        return $this->transaction->type->movesMoneyIn();
    }

    public function isRefund(): bool
    {
        return $this->transaction->type === TransactionType::Refund;
    }

    public function isVoid(): bool
    {
        return $this->transaction->type->isVoid();
    }
}
