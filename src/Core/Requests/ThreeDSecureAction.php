<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of interaction.action.3DSecure in Initiate Checkout.
 */
enum ThreeDSecureAction: string
{
    case Bypass = 'BYPASS';
    case Mandatory = 'MANDATORY';
    case UseGatewayRecommendation = 'USE_GATEWAY_RECOMMENDATION';
}
