<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * Money captured, but not a clean settlement: amount or currency mismatch, or a double capture. Never auto-paid.
 *
 * Dispatched inside Payable::lock(). The package never changes the payable itself.
 */
final readonly class CaptureMismatch
{
    public function __construct(
        public string $gatewayOrderId,
        public Payable $payable,
        public Attempt $attempt,
        public VerificationResult $verification,
        public MismatchReason $reason,
        public ?Settlement $existingSettlement,
        public ?string $transactionId,
        public Source $source,
        public ?Notification $notification = null,
    ) {
    }
}
