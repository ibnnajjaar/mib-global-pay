<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * A gateway payload contains a value of the wrong type or format.
 */
final class MalformedPayload extends \UnexpectedValueException implements MibGlobalPayException
{
}
