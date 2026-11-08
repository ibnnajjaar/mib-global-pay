<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of interaction.displayControl.shipping in Initiate Checkout.
 */
enum ShippingDisplay: string
{
    case Hide = 'HIDE';
    case ReadOnly = 'READ_ONLY';
}
