<?php

namespace IbnNajjaar\MIBGlobalPay\Support\Constants;

/**
 * Values of the top-level result field, lowercased as returned by the response DTOs.
 */
final class TransactionResult
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const PENDING = 'pending';
    public const UNKNOWN = 'unknown';
    public const ERROR = 'error';
}
