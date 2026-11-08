<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of agreement.paymentFrequency.
 */
enum PaymentFrequency: string
{
    case AdHoc = 'AD_HOC';
    case Daily = 'DAILY';
    case Fortnightly = 'FORTNIGHTLY';
    case Monthly = 'MONTHLY';
    case Other = 'OTHER';
    case Quarterly = 'QUARTERLY';
    case TwiceYearly = 'TWICE_YEARLY';
    case Weekly = 'WEEKLY';
    case Yearly = 'YEARLY';
}
