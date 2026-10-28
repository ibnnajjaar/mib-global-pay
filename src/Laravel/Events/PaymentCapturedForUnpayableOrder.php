<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * Verified Captured, but the payable was Cancelled or Failed ("captured after cancel"). Re-link if all original invoices are still unpaid, otherwise flag for finance.
 *
 * Dispatched inside Payable::lock(). The package never changes the payable itself.
 */
final readonly class PaymentCapturedForUnpayableOrder
{
    public function __construct(
        public string $gatewayOrderId,
        public Payable $payable,
        public Attempt $attempt,
        public VerificationResult $verification,
        public string $transactionId,
        public PayableState $stateAtCapture,
        public Source $source,
        public ?Notification $notification = null,
    ) {
    }
}
