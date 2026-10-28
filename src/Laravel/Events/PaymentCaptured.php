<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * Verified Captured for exactly the frozen amount, and the payable is still payable. Mark it paid and record the settlement.
 *
 * Dispatched inside Payable::lock(). The package never changes the payable itself.
 */
final readonly class PaymentCaptured
{
    public function __construct(
        public string $gatewayOrderId,
        public Payable $payable,
        public Attempt $attempt,
        public VerificationResult $verification,
        public string $transactionId,
        public Source $source,
        public ?Notification $notification = null,
    ) {
    }
}
