<?php

declare(strict_types=1);

arch('no debugging helpers are left in the source')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray'])
    ->not->toBeUsed();

arch('only the optional Laravel layer depends on the Laravel framework')
    ->expect('IbnNajjaar\MIBGlobalPay\Core')
    ->not->toUse('Illuminate');

arch('all source files use strict types')
    ->expect(['IbnNajjaar\MIBGlobalPay\Core', 'IbnNajjaar\MIBGlobalPay\Laravel'])
    ->toUseStrictTypes();

arch('core value objects are final and readonly')
    ->expect([
        'IbnNajjaar\MIBGlobalPay\Core\Money\Money',
        'IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig',
        'IbnNajjaar\MIBGlobalPay\Core\Support\Payload',
        'IbnNajjaar\MIBGlobalPay\Core\Verification\ApprovalPolicy',
    ])
    ->toBeFinal()
    ->toBeReadonly();

arch('core exceptions implement the marker interface')
    ->expect('IbnNajjaar\MIBGlobalPay\Core\Exceptions')
    ->classes()
    ->toImplement('IbnNajjaar\MIBGlobalPay\Core\Exceptions\MibGlobalPayException');

arch('response DTOs and operations are final and readonly')
    ->expect([
        'IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse',
        'IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse',
        'IbnNajjaar\MIBGlobalPay\Core\Responses\CheckoutSession',
        'IbnNajjaar\MIBGlobalPay\Core\Responses\SessionDetails',
        'IbnNajjaar\MIBGlobalPay\Core\Responses\GatewayStatus',
        'IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentOptions',
        'IbnNajjaar\MIBGlobalPay\Core\Operations\Checkout\InitiateCheckout',
        'IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Capture',
        'IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Refund',
        'IbnNajjaar\MIBGlobalPay\Core\Operations\Order\VoidTransaction',
    ])
    ->toBeFinal()
    ->toBeReadonly();

arch('every operation implements the Operation contract')
    ->expect('IbnNajjaar\MIBGlobalPay\Core\Operations')
    ->classes()
    ->toImplement('IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation');

arch('host contracts live in Core and have no Laravel dependency')
    ->expect('IbnNajjaar\MIBGlobalPay\Core\Attempts')
    ->not->toUse(['Illuminate', 'IbnNajjaar\MIBGlobalPay\Laravel']);

arch('webhook notifications are final and readonly')
    ->expect([
        'IbnNajjaar\MIBGlobalPay\Core\Webhook\TransactionNotification',
        'IbnNajjaar\MIBGlobalPay\Core\Webhook\AuthenticationNotification',
        'IbnNajjaar\MIBGlobalPay\Core\Webhook\GenericNotification',
        'IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier',
    ])
    ->toBeFinal()
    ->toBeReadonly();

arch('the core does not depend on the Laravel layer')
    ->expect('IbnNajjaar\MIBGlobalPay\Core')
    ->not->toUse('IbnNajjaar\MIBGlobalPay\Laravel');
