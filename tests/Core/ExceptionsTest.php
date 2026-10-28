<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidAmount;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\SecretMismatch;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\CurrencyMismatch;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\OperationNotEnabled;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\UnsupportedCurrency;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedNotification;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MibGlobalPayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

it('implements the marker interface on the matching SPL exception', function (string $class, string $spl) {
    $exception = $class === GatewayException::class ? new GatewayException('x', 500) : new $class('x');

    expect($exception)->toBeInstanceOf(MibGlobalPayException::class)
        ->toBeInstanceOf($spl);
})->with([
    [InvalidAmount::class, InvalidArgumentException::class],
    [InvalidRequest::class, InvalidArgumentException::class],
    [UnsupportedCurrency::class, InvalidArgumentException::class],
    [CurrencyMismatch::class, DomainException::class],
    [ConfigurationException::class, DomainException::class],
    [OperationNotEnabled::class, DomainException::class],
    [SecretMismatch::class, RuntimeException::class],
    [MalformedNotification::class, UnexpectedValueException::class],
    [MalformedPayload::class, UnexpectedValueException::class],
    [TransportException::class, RuntimeException::class],
    [GatewayException::class, RuntimeException::class],
]);

it('carries the gateway error details', function () {
    $e = new GatewayException('Invalid request', 400, [
        'cause' => 'INVALID_REQUEST',
        'explanation' => 'Invalid request',
        'field' => 'order.id',
        'validationType' => 'INVALID',
        'supportCode' => 'ABC',
    ], '{"raw":true}');

    expect($e->httpStatus())->toBe(400)
        ->and($e->getCode())->toBe(400)
        ->and($e->cause())->toBe('INVALID_REQUEST')
        ->and($e->explanation())->toBe('Invalid request')
        ->and($e->field())->toBe('order.id')
        ->and($e->validationType())->toBe('INVALID')
        ->and($e->supportCode())->toBe('ABC')
        ->and($e->rawBody())->toBe('{"raw":true}')
        ->and($e->isClientError())->toBeTrue()
        ->and($e->isServerError())->toBeFalse()
        ->and($e->isAuthenticationError())->toBeFalse();
});

it('returns null for missing error details', function () {
    $e = new GatewayException('HTTP 401', 401, ['cause' => ['nested']]);

    expect($e->cause())->toBeNull()
        ->and($e->explanation())->toBeNull()
        ->and($e->isAuthenticationError())->toBeTrue()
        ->and((new GatewayException('x', 503))->isServerError())->toBeTrue();
});
