<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Clients;

use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Responses\AgreementDetails;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Agreement\RetrieveAgreement;

/**
 * Agreements with payers.
 */
final readonly class AgreementClient
{
    public function __construct(
        private Gateway $gateway,
    ) {
    }

    public function retrieve(string $agreementId): AgreementDetails
    {
        return $this->gateway->send(new RetrieveAgreement($agreementId));
    }
}
