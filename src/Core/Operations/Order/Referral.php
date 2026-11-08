<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction: Referral. Resubmits a transaction the issuer referred, with the authorization code
 * the issuer gave you.
 */
final readonly class Referral extends TransactionOperation
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId, string $transactionId, public string $authorizationCode, ?string $reference = null)
    {
        parent::__construct($orderId, $transactionId, $reference);

        if (preg_match('/^[0-9A-Za-z]{1,6}$/', $authorizationCode) !== 1) {
            throw new InvalidRequest('Authorization code must be 1-6 characters from 0-9, a-z and A-Z');
        }
    }

    protected function apiOperation(): string
    {
        return 'REFERRAL';
    }

    protected function transactionFields(GatewayConfig $config): array
    {
        return ['authorizationCode' => $this->authorizationCode];
    }
}
