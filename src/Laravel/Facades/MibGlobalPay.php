<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\MibGlobalPayFake;

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
 * @method static void assertCheckoutInitiated(?callable $callback = null)
 *
 * @see \IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay
 */
final class MibGlobalPay extends Facade
{
    /**
     * Replace the gateway with an in-memory fake for tests. The container's Gateway binding and
     * this facade use it, so the webhook job, the outcome pipeline, PaymentOutcome and
     * mib:reconcile go through the fake. Code that type-hints the concrete MibGateway class
     * (instead of the Gateway contract) still gets the real client.
     */
    public static function fake(): MibGlobalPayFake
    {
        $app = static::getFacadeApplication();
        if ($app === null) {
            throw new \RuntimeException('MibGlobalPay::fake() needs a Laravel application');
        }

        $fake = new MibGlobalPayFake($app->make(GatewayConfig::class));
        $app->instance(Gateway::class, $fake);
        $app->forgetInstance(\IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay::class);
        static::clearResolvedInstance(static::getFacadeAccessor());

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay::class;
    }
}
