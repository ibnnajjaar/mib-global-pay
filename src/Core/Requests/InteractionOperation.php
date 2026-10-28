<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of interaction.operation in Initiate Checkout.
 */
enum InteractionOperation: string
{
    case Authorize = 'AUTHORIZE';
    case None = 'NONE';
    case Purchase = 'PURCHASE';
    case Verify = 'VERIFY';
}
