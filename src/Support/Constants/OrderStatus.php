<?php

namespace IbnNajjaar\MIBGlobalPay\Support\Constants;

/**
 * Values of order.status, lowercased as returned by the response DTOs.
 */
final class OrderStatus
{
    public const AUTHENTICATED = 'authenticated';
    public const AUTHENTICATION_INITIATED = 'authentication_initiated';
    public const AUTHENTICATION_NOT_NEEDED = 'authentication_not_needed';
    public const AUTHENTICATION_UNSUCCESSFUL = 'authentication_unsuccessful';
    public const AUTHORIZED = 'authorized';
    public const CANCELLED = 'cancelled';
    public const CAPTURED = 'captured';
    public const CHARGEBACK_PROCESSED = 'chargeback_processed';
    public const DISBURSED = 'disbursed';
    public const DISPUTED = 'disputed';
    public const EXCESSIVELY_REFUNDED = 'excessively_refunded';
    public const FAILED = 'failed';
    public const FUNDING = 'funding';
    public const INITIATED = 'initiated';
    public const PARTIALLY_CAPTURED = 'partially_captured';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const REFUNDED = 'refunded';
    public const REFUND_REQUESTED = 'refund_requested';
    public const VERIFIED = 'verified';
}
