<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Clients;

use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TokenDetails;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OperationResult;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Token\SaveToken;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Token\DeleteToken;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Token\RetrieveToken;

/**
 * Saved card tokens.
 */
final readonly class TokenClient
{
    public function __construct(
        private Gateway $gateway,
    ) {
    }

    public function retrieve(string $token): TokenDetails
    {
        return $this->gateway->send(new RetrieveToken($token));
    }

    /** Saves the card details in a session. With no $token, the gateway generates one. */
    public function saveFromSession(string $sessionId, ?string $token = null): TokenDetails
    {
        return $this->gateway->send(new SaveToken($sessionId, $token));
    }

    public function delete(string $token): OperationResult
    {
        return $this->gateway->send(new DeleteToken($token));
    }
}
