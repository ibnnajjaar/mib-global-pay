<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Checkout;

/**
 * How the Hosted Payment Page is shown to the payer.
 */
enum CheckoutDisplay: string
{
    /** Redirect to a separate Payment Page. The only display Apple Pay supports. */
    case Payment = 'payment';

    /** Show the Embedded Page inside your page. */
    case Embedded = 'embedded';

    /** Show the Embedded Page in a modal dialog. */
    case Modal = 'modal';
}
