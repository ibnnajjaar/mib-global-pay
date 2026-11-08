<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Verification;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * What verifyCapture() found. $order is null only for NotFound.
 */
final readonly class VerificationResult
{
    public function __construct(
        public VerificationStatus $status,
        public string $orderId,
        public Money $expected,
        public ?Money $captured,
        public ?Money $refunded,
        public ?TransactionResponse $capturingTransaction,
        public ?OrderResponse $order,
    ) {
    }

    public function isCapturedExactly(): bool
    {
        return $this->status === VerificationStatus::Captured;
    }

    /** Whether an approved authorization is holding the payer's funds. */
    public function isAuthorizationHeld(): bool
    {
        return $this->status === VerificationStatus::Authorized;
    }

    /** Captured, PartiallyCaptured, AmountMismatch or CurrencyMismatch: money moved, whether or not it matches. */
    public function isMoneyCaptured(): bool
    {
        return in_array($this->status, [
            VerificationStatus::Captured,
            VerificationStatus::PartiallyCaptured,
            VerificationStatus::AmountMismatch,
            VerificationStatus::CurrencyMismatch,
        ], true);
    }

    /** The transaction ID of the approved payment, capture, authorization or verification, if any. */
    public function transactionId(): ?string
    {
        return $this->capturingTransaction?->transactionId;
    }
}
