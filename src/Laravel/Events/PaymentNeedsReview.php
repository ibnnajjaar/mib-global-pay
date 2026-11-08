<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * The payment has stayed pending or timed out past MIB_REVIEW_AFTER_SECONDS, and the payer cannot
 * start a new attempt. A person should ask the acquirer, then call MibGlobalPay::confirmUnpaid() if
 * it was not charged. It replaces PaymentPending from this point.
 *
 * Dispatched inside Payable::lock(). At least once: be idempotent on $deliveryKey (64 hex characters).
 */
final readonly class PaymentNeedsReview
{
    public function __construct(
        public string $gatewayOrderId,
        public Payable $payable,
        public Attempt $attempt,
        public VerificationResult $verification,
        public \DateTimeImmutable $pendingSince,
        public Source $source,
        public string $deliveryKey,
        public ?Notification $notification = null,
    ) {
    }
}
