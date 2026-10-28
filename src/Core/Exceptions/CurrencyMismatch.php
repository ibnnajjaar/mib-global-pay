<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * Two Money values with different currencies were combined or compared.
 */
final class CurrencyMismatch extends \DomainException implements MibGlobalPayException
{
}
