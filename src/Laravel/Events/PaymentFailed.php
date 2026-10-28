<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * No money captured and the attempt is finished (failed transaction, or expired without payment). The host may mark the attempt failed, and cancel the payable only if no other attempt is live.
 *
 * Dispatched inside Payable::lock(). The package never changes the payable itself.
 */
final readonly class PaymentFailed
{
    public function __construct(
        public string $gatewayOrderId,
        public Payable $payable,
        public Attempt $attempt,
        public VerificationResult $verification,
        public ?GatewayCode $gatewayCode,
        public Source $source,
        public ?Notification $notification = null,
    ) {
    }
}
