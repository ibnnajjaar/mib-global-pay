<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Verification\HostedCheckoutReturn;

it('reads the return query', function () {
    $return = HostedCheckoutReturn::fromQuery(['resultIndicator' => ' abc123 ', 'sessionVersion' => 'e3f144ce02', 'order' => 'ORDER-1']);

    expect($return->resultIndicator)->toBe('abc123')
        ->and($return->sessionVersion)->toBe('e3f144ce02')
        ->and($return->orderId)->toBe('ORDER-1');
});

it('matches the stored success indicator exactly', function () {
    $return = HostedCheckoutReturn::fromQuery(['resultIndicator' => 'f8e1b1e3c2d54a7b']);

    expect($return->matchesSuccessIndicator('f8e1b1e3c2d54a7b'))->toBeTrue()
        ->and($return->matchesSuccessIndicator('F8E1B1E3C2D54A7B'))->toBeFalse()
        ->and($return->matchesSuccessIndicator('f8e1b1e3c2d54a7'))->toBeFalse()
        ->and($return->matchesSuccessIndicator(''))->toBeFalse()
        ->and($return->matchesSuccessIndicator(null))->toBeFalse();
});

it('never matches when the indicator is missing or not a string', function (array $query) {
    expect(HostedCheckoutReturn::fromQuery($query)->matchesSuccessIndicator('abc'))->toBeFalse();
})->with([[[]], [['resultIndicator' => '']], [['resultIndicator' => ['abc']]], [['resultIndicator' => 123]]]);
