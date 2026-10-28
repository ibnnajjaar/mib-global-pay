<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway gateway()
 * @method static string attemptOrderId(string $payableReference, int $attemptNumber)
 * @method static string randomOrderId()
 * @method static \IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest newCheckoutRequest(string $orderId, \IbnNajjaar\MIBGlobalPay\Core\Money\Money $amount, string $merchantName, string $returnUrl)
 * @method static string|null webhookUrl()
 * @method static \DateTimeImmutable attemptExpiresAt(\DateTimeInterface $createdAt)
 * @method static \IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptGuard attemptGuard()
 * @method static \IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalHelper reversal()
 * @method static \IbnNajjaar\MIBGlobalPay\Core\Responses\CheckoutSession initiateCheckout(\IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest $request)
 * @method static \IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult verifyCapture(string $orderId, \IbnNajjaar\MIBGlobalPay\Core\Money\Money $expected)
 * @method static \IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse retrieveOrder(string $orderId)
 *
 * @see \IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay
 */
final class MibGlobalPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay::class;
    }
}
