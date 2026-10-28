<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryAttempt;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryPayable;
use IbnNajjaar\MIBGlobalPay\Tests\Laravel\Support\TestResolver;

const LARAVEL_SECRET = '7C1F3A9E5B2D4F6081A3C5E7092B4D6F';
const LARAVEL_ORDER = 'ISIMS-R-IUM-FN-2026-123-1';

function frozenAmount(): Money
{
    return Money::of('1250.50', Currency::Mvr);
}

/** Bind a MockHandler-backed gateway; returns the harness to inspect requests. */
function fakeGatewayResponses(array $responses): GatewayHarness
{
    $harness = new GatewayHarness($responses);
    app()->instance(Gateway::class, $harness->gateway);

    return $harness;
}

function hostPayable(PayableState $state = PayableState::Frozen, AttemptState $attemptState = AttemptState::Live, ?Settlement $settlement = null, string $expires = '+20 minutes'): InMemoryPayable
{
    $attempt = new InMemoryAttempt(1, LARAVEL_ORDER, frozenAmount(), $attemptState, new DateTimeImmutable($expires), successIndicator: 'f8e1b1e3c2d54a7b');

    return new InMemoryPayable(frozenAmount(), $state, [$attempt], $settlement);
}

function bindResolver(InMemoryPayable ...$payables): TestResolver
{
    $resolver = new TestResolver();
    foreach ($payables as $payable) {
        $resolver->add($payable);
    }
    app()->instance(PayableResolver::class, $resolver);

    return $resolver;
}

function orderJson(string $code = 'APPROVED', string $result = 'SUCCESS', float $captured = 1250.5, array $extra = []): array
{
    return $extra + [
        'id' => LARAVEL_ORDER, 'currency' => 'MVR', 'amount' => 1250.5, 'totalCapturedAmount' => $captured,
        'transaction' => [['result' => $result, 'response' => ['gatewayCode' => $code], 'transaction' => ['id' => '1', 'type' => 'PAYMENT', 'amount' => 1250.5, 'currency' => 'MVR']]],
    ];
}

function notificationBody(string $type = 'PAYMENT', string $result = 'SUCCESS', string $code = 'APPROVED', string $txnId = '1'): string
{
    return (string) json_encode([
        'order' => ['id' => LARAVEL_ORDER, 'currency' => 'MVR', 'status' => 'CAPTURED', 'totalCapturedAmount' => 1250.5],
        'response' => ['gatewayCode' => $code],
        'result' => $result,
        'transaction' => ['id' => $txnId, 'type' => $type, 'amount' => 1250.5, 'currency' => 'MVR', 'targetTransactionId' => $type === 'PAYMENT' ? null : '1'],
    ]);
}

function webhookServer(string $id = 'NOTIF-1', ?string $secret = LARAVEL_SECRET): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_NOTIFICATION_ID' => $id, 'HTTP_X_NOTIFICATION_ATTEMPT' => '1', 'HTTPS' => 'on'];
    if ($secret !== null) {
        $server['HTTP_X_NOTIFICATION_SECRET'] = $secret;
    }

    return $server;
}
