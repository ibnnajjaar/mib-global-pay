<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of transaction.source (requires permission on the merchant profile for anything but INTERNET).
 */
enum TransactionSource: string
{
    case CallCentre = 'CALL_CENTRE';
    case CardPresent = 'CARD_PRESENT';
    case Internet = 'INTERNET';
    case MailOrder = 'MAIL_ORDER';
    case Merchant = 'MERCHANT';
    case Moto = 'MOTO';
    case TelephoneOrder = 'TELEPHONE_ORDER';
    case VoiceResponse = 'VOICE_RESPONSE';
}
