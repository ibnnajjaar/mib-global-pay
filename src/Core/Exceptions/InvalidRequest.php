<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * A request was built with invalid input. Thrown before anything is sent.
 */
final class InvalidRequest extends \InvalidArgumentException implements MibGlobalPayException
{
}
