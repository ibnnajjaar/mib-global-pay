<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of interaction.displayControl.billingAddress and customerEmail in Initiate Checkout.
 */
enum FieldDisplay: string
{
    case Hide = 'HIDE';
    case Mandatory = 'MANDATORY';
    case Optional = 'OPTIONAL';
    case ReadOnly = 'READ_ONLY';
}
