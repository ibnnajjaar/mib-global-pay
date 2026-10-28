<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * A webhook notification did not carry the configured secret.
 */
final class SecretMismatch extends \RuntimeException implements MibGlobalPayException
{
}
