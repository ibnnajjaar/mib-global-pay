<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Clients;

use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Requests\SessionUpdate;
use IbnNajjaar\MIBGlobalPay\Core\Responses\SessionDetails;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Session\UpdateSession;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Session\RetrieveSession;

/**
 * Session operations.
 */
final readonly class SessionClient
{
    public function __construct(
        private Gateway $gateway,
    ) {
    }

    public function retrieve(string $sessionId): SessionDetails
    {
        return $this->gateway->send(new RetrieveSession($sessionId));
    }

    /**
     * @experimental unconfirmed for Hosted Checkout sessions (DESIGN-v2.md §12 Q4)
     */
    public function update(string $sessionId, SessionUpdate $update): SessionDetails
    {
        return $this->gateway->send(new UpdateSession($sessionId, $update));
    }
}
