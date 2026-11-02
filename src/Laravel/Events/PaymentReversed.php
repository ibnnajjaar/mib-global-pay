<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * Captured money was fully refunded or voided.
 *
 * Dispatched inside Payable::lock(). The package never changes the payable itself. At least once:
 * be idempotent on $deliveryKey (64 hex characters).
 */
final readonly class PaymentReversed
{
    public function __construct(
        public string $gatewayOrderId,
        public Payable $payable,
        public Attempt $attempt,
        public VerificationResult $verification,
        public Money $reversedAmount,
        public Source $source,
        public string $deliveryKey,
        public ?Notification $notification = null,
    ) {
    }
}
