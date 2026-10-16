<?php

namespace IbnNajjaar\MIBGlobalPay\Support\Constants;

/**
 * Values of transaction.type, lowercased as returned by the response DTOs.
 */
final class TransactionType
{
    public const AUTHENTICATION = 'authentication';
    public const AUTHORIZATION = 'authorization';
    public const AUTHORIZATION_UPDATE = 'authorization_update';
    public const CAPTURE = 'capture';
    public const CHARGEBACK = 'chargeback';
    public const DISBURSEMENT = 'disbursement';
    public const FUNDING = 'funding';
    public const PAYMENT = 'payment';
    public const REFUND = 'refund';
    public const REFUND_REQUEST = 'refund_request';
    public const VERIFICATION = 'verification';
    public const VOID_AUTHORIZATION = 'void_authorization';
    public const VOID_CAPTURE = 'void_capture';
    public const VOID_PAYMENT = 'void_payment';
    public const VOID_REFUND = 'void_refund';
}
