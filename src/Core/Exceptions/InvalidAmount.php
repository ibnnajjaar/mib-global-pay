<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * An amount string or value is not a valid amount for its currency.
 */
final class InvalidAmount extends \InvalidArgumentException implements MibGlobalPayException
{
}
