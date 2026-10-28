<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * The request could not be completed: connection error, timeout or similar.
 */
final class TransportException extends \RuntimeException implements MibGlobalPayException
{
}
