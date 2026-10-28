<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * The gateway configuration is missing a value or has an invalid one.
 */
final class ConfigurationException extends \DomainException implements MibGlobalPayException
{
}
