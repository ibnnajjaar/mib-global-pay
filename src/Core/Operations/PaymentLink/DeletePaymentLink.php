<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentLink;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OperationResult;

/**
 * Hosted Checkout: Delete Payment Link (DELETE /link/{linkId}).
 *
 * @implements Operation<OperationResult>
 */
final readonly class DeletePaymentLink implements Operation
{
    public string $linkId;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $linkId)
    {
        $this->linkId = Validate::identifier($linkId, 'Payment link ID');
    }

    public function method(): HttpMethod
    {
        return HttpMethod::Delete;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return 'link/' . RequestBody::segment($this->linkId);
    }

    public function body(GatewayConfig $config): ?array
    {
        return null;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function parse(Payload $payload, GatewayConfig $config): OperationResult
    {
        return OperationResult::fromPayload($payload);
    }
}
