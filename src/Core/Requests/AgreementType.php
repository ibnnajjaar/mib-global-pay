<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of agreement.type.
 */
enum AgreementType: string
{
    case Installment = 'INSTALLMENT';
    case Other = 'OTHER';
    case Recurring = 'RECURRING';
    case Unscheduled = 'UNSCHEDULED';
}
