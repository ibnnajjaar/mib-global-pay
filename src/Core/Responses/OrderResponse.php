<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Enums\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Retrieve Order response.
 *
 * The totals are always Money in the order currency: a total the gateway leaves out is zero.
 * A payload without a supported order currency is rejected (MalformedPayload), so
 * totalCaptured can be relied on.
 */
final readonly class OrderResponse
{
    /**
     * @param list<TransactionResponse> $transactions
     */
    private function __construct(
        public string $id,
        public ?string $reference,
        public OrderStatus $status,
        public Currency $currency,
        public Money $amount,
        public Money $totalAuthorized,
        public Money $totalCaptured,
        public Money $totalRefunded,
        public ?\DateTimeImmutable $creationTime,
        public array $transactions,
        public Payload $payload,
    ) {
    }

    /**
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $p): self
    {
        $currency = $p->currency('currency');
        if (! $currency->isRecognized()) {
            throw new MalformedPayload(sprintf(
                'Order has an unsupported or missing currency "%s"',
                $p->string('currency') ?? '',
            ));
        }

        $zero = Money::ofMinor(0, $currency);

        return new self(
            id: $p->requireString('id'),
            reference: $p->string('reference'),
            status: $p->enum('status', OrderStatus::class),
            currency: $currency,
            amount: $p->moneyIn('amount', $currency) ?? $zero,
            totalAuthorized: $p->moneyIn('totalAuthorizedAmount', $currency) ?? $zero,
            totalCaptured: $p->moneyIn('totalCapturedAmount', $currency) ?? $zero,
            totalRefunded: $p->moneyIn('totalRefundedAmount', $currency) ?? $zero,
            creationTime: $p->dateTime('creationTime'),
            transactions: array_map(
                TransactionResponse::fromPayload(...),
                $p->list('transaction'),
            ),
            payload: $p,
        );
    }

    /**
     * Whether the gateway reported totalCapturedAmount at all. $totalCaptured is zero when it
     * did not; verification must treat a missing total as a mismatch, never as a match.
     */
    public function isTotalCapturedReported(): bool
    {
        return $this->payload->has('totalCapturedAmount');
    }

    /** Captured and not yet refunded; never negative. */
    public function refundable(): Money
    {
        $refundable = $this->totalCaptured->minus($this->totalRefunded);

        return $refundable->isNegative() ? Money::ofMinor(0, $this->currency) : $refundable;
    }

    /** Authorized and not yet captured; never negative. */
    public function capturable(): Money
    {
        $capturable = $this->totalAuthorized->minus($this->totalCaptured);

        return $capturable->isNegative() ? Money::ofMinor(0, $this->currency) : $capturable;
    }

    public function findTransaction(string $transactionId): ?TransactionResponse
    {
        return array_find($this->transactions, fn (TransactionResponse $t): bool => $t->transactionId === $transactionId);
    }

    /**
     * @return list<TransactionResponse> in gateway order
     */
    public function transactionsOfType(TransactionType ...$types): array
    {
        return array_values(array_filter(
            $this->transactions,
            fn (TransactionResponse $t): bool => in_array($t->type, $types, true),
        ));
    }

    /** The last transaction (in gateway order) of one of the given types. */
    public function latestOfType(TransactionType ...$types): ?TransactionResponse
    {
        $matches = $this->transactionsOfType(...$types);

        return $matches === [] ? null : $matches[array_key_last($matches)];
    }

    public function rawStatus(): ?string
    {
        return $this->payload->string('status');
    }
}
