<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of sourceOfFunds.provided.card.storedOnFile.
 */
enum StoredOnFile: string
{
    case NotStored = 'NOT_STORED';
    case Stored = 'STORED';
    case ToBeStored = 'TO_BE_STORED';
}
