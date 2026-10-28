<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * Marker interface implemented by every exception the package throws.
 * Each concrete exception also extends the matching SPL exception.
 */
interface MibGlobalPayException extends \Throwable
{
}
