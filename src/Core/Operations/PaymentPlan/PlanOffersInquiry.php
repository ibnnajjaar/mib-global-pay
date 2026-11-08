<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentPlan;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PlanOffers;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Payment Plan: Plan Offers Inquiry (PUT /paymentPlan/{planId}). Shows the installment amounts for a saved token.
 *
 * @implements Operation<PlanOffers>
 */
final readonly class PlanOffersInquiry implements Operation
{
    public string $planId;

    public string $token;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $planId, public Money $amount, public int $numberOfPayments, string $token)
    {
        $this->planId = Validate::identifier($planId, 'Payment plan');
        $this->token = Validate::identifier($token, 'Token');

        if ($numberOfPayments < 1 || $numberOfPayments > 99) {
            throw new InvalidRequest('Number of payments must be between 1 and 99');
        }
    }

    public function method(): HttpMethod
    {
        return HttpMethod::Put;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return 'paymentPlan/' . RequestBody::segment($this->planId);
    }

    public function body(GatewayConfig $config): array
    {
        Validate::amount($this->amount, 'Order amount', $config);

        return [
            'apiOperation' => 'PLAN_OFFERS_INQUIRY',
            'order' => ['amount' => $this->amount->toDecimal(), 'currency' => $this->amount->currency->value],
            'paymentPlan' => ['numberOfPayments' => (string) $this->numberOfPayments],
            'sourceOfFunds' => ['token' => $this->token],
        ];
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function parse(Payload $payload, GatewayConfig $config): PlanOffers
    {
        return PlanOffers::fromPayload($payload);
    }
}
