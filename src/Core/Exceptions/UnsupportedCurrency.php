<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * The currency is not supported by Money, or not allowed by the configuration.
 */
final class UnsupportedCurrency extends \InvalidArgumentException implements MibGlobalPayException
{
}
