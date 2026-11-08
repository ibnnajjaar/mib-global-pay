<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Agreement;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\AgreementDetails;

/**
 * Agreement: Retrieve Agreement (GET /agreement/{agreementId}).
 *
 * @implements Operation<AgreementDetails>
 */
final readonly class RetrieveAgreement implements Operation
{
    public string $agreementId;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $agreementId)
    {
        $this->agreementId = Validate::identifier($agreementId, 'Agreement ID');
    }

    public function method(): HttpMethod
    {
        return HttpMethod::Get;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return 'agreement/' . RequestBody::segment($this->agreementId);
    }

    public function body(GatewayConfig $config): ?array
    {
        return null;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function parse(Payload $payload, GatewayConfig $config): AgreementDetails
    {
        return AgreementDetails::fromPayload($payload);
    }
}
