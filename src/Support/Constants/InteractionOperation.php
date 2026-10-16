<?php

namespace IbnNajjaar\MIBGlobalPay\Support\Constants;

/**
 * Values for interaction.operation in Initiate Checkout (request values, uppercase).
 */
final class InteractionOperation
{
    public const PURCHASE = 'PURCHASE';
    public const AUTHORIZE = 'AUTHORIZE';
    public const VERIFY = 'VERIFY';
    public const NONE = 'NONE';

    public static function all(): array
    {
        return [self::PURCHASE, self::AUTHORIZE, self::VERIFY, self::NONE];
    }
}
