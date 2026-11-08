<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of interaction.displayControl.paymentTerms in Initiate Checkout.
 */
enum PaymentTermsDisplay: string
{
    case Hide = 'HIDE';
    case ShowIfSupported = 'SHOW_IF_SUPPORTED';
}
