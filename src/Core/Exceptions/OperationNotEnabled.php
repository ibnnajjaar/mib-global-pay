<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * The operation is not enabled for this merchant (see Capabilities).
 */
final class OperationNotEnabled extends \DomainException implements MibGlobalPayException
{
}
