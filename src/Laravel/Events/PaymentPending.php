<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * The gateway reports the payment as pending or unknown. Keep the payable frozen; reconciliation asks again.
 *
 * Dispatched inside Payable::lock(). The package never changes the payable itself.
 */
final readonly class PaymentPending
{
    public function __construct(
        public string $gatewayOrderId,
        public Payable $payable,
        public Attempt $attempt,
        public VerificationResult $verification,
        public Source $source,
        public ?Notification $notification = null,
    ) {
    }
}
