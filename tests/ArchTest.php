<?php

declare(strict_types=1);

arch('no debugging helpers are left in the source')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray'])
    ->not->toBeUsed();

arch('the package does not depend on the Laravel framework')
    ->expect('IbnNajjaar\MIBGlobalPay')
    ->not->toUse('Illuminate');

arch('v2 core uses strict types')
    ->expect('IbnNajjaar\MIBGlobalPay\Core')
    ->toUseStrictTypes();

arch('v2 core value objects are final and readonly')
    ->expect([
        'IbnNajjaar\MIBGlobalPay\Core\Money\Money',
        'IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig',
        'IbnNajjaar\MIBGlobalPay\Core\Support\Payload',
        'IbnNajjaar\MIBGlobalPay\Core\Verification\ApprovalPolicy',
    ])
    ->toBeFinal()
    ->toBeReadonly();

arch('v2 core exceptions implement the marker interface')
    ->expect('IbnNajjaar\MIBGlobalPay\Core\Exceptions')
    ->classes()
    ->toImplement('IbnNajjaar\MIBGlobalPay\Core\Exceptions\MibGlobalPayException');

arch('v2 core does not depend on the 1.x classes')
    ->expect('IbnNajjaar\MIBGlobalPay\Core')
    ->not->toUse([
        'IbnNajjaar\MIBGlobalPay\Support',
        'IbnNajjaar\MIBGlobalPay\Requests',
        'IbnNajjaar\MIBGlobalPay\Responses',
        'IbnNajjaar\MIBGlobalPay\Exceptions',
        'IbnNajjaar\MIBGlobalPay\Contracts',
    ]);
