<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/** A refund notification. $resolved is null when the order ID is unknown to the host. Check $transaction->isApproved(). */
final readonly class RefundProcessed
{
    public function __construct(
        public string $gatewayOrderId,
        public ?ResolvedAttempt $resolved,
        public TransactionResponse $transaction,
        public Source $source,
        public ?Notification $notification = null,
    ) {
    }
}
