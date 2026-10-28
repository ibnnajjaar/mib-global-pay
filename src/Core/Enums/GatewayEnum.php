<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Enums;

/**
 * A backed enum whose values come from the gateway.
 *
 * Every implementation has an `Unrecognized` case (backed by '') that
 * fromGateway() returns for missing or unknown values. Keep the raw string
 * on the DTO if you need it.
 */
interface GatewayEnum extends \BackedEnum
{
    /** Never throws: null, '' and unknown values resolve to the Unrecognized case. */
    public static function fromGateway(?string $value): static;

    public function isRecognized(): bool;
}
