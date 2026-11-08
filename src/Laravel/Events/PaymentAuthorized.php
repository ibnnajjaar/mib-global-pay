<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * An approved authorization is holding the payer's funds. Capture it with capture(), or release it with void().
 *
 * Dispatched inside Payable::lock(). At least once: be idempotent on $deliveryKey (64 hex characters).
 */
final readonly class PaymentAuthorized
{
    public function __construct(
        public string $gatewayOrderId,
        public Payable $payable,
        public Attempt $attempt,
        public VerificationResult $verification,
        public string $transactionId,
        public Source $source,
        public string $deliveryKey,
        public ?Notification $notification = null,
    ) {
    }
}
