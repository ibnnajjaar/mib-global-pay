<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of interaction.displayControl.cardSecurityCode in Initiate Checkout.
 */
enum CardSecurityCodeDisplay: string
{
    case Mandatory = 'MANDATORY';
    case Optional = 'OPTIONAL';
}
