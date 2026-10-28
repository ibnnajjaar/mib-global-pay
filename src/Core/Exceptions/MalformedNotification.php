<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * A webhook notification is missing required data or is not valid JSON.
 */
final class MalformedNotification extends \UnexpectedValueException implements MibGlobalPayException
{
}
