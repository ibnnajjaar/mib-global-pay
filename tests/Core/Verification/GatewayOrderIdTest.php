<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Support\TransactionId;
use IbnNajjaar\MIBGlobalPay\Core\Support\GatewayOrderId;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\OrderIdGenerator;

it('implements OrderIdGenerator', function () {
    expect(new GatewayOrderId())->toBeInstanceOf(OrderIdGenerator::class);
});

it('sanitises the ISIMS receipt number as decided', function () {
    expect((new GatewayOrderId())->forAttempt('ISIMS', '(R)IUM/FN/2026/123', 2))->toBe('ISIMS-R-IUM-FN-2026-123-2');
});

it('sanitises references', function (string $reference, string $expected) {
    expect(GatewayOrderId::sanitise($reference))->toBe($expected);
})->with([
    ['(R)IUM/FN/2026/123', 'R-IUM-FN-2026-123'],
    ['INV-1001', 'INV-1001'],
    ['a.b_c-d', 'a.b_c-d'],
    ['  spaced   out  ', 'spaced-out'],
    ['[x]{y}<z>', 'x-y-z'],
    ['---lead/trail---', 'lead-trail'],
    ['Nürnberg €5', 'N-rnberg-5'],
    ['()/\\', ''],
]);

it('only produces IDs within the gateway charset and length', function (string $reference, int $attempt) {
    $id = (new GatewayOrderId())->forAttempt('ISIMS', $reference, $attempt);

    expect(strlen($id))->toBeLessThanOrEqual(40)
        ->and($id)->toMatch('/^ISIMS-[A-Za-z0-9._-]+$/');
})->with([
    ['(R)IUM/FN/2026/123', 1],
    [str_repeat('A', 60), 1],
    ['ok', 999999],
    ['()', 3],
]);

it('falls back to a random ID only when the sanitised ID is too long or empty', function () {
    $generator = new GatewayOrderId();

    expect($generator->forAttempt('ISIMS', str_repeat('A', 33), 1))->toMatch('/^ISIMS-[A-Z2-7]{16}$/')
        ->and($generator->forAttempt('ISIMS', '((//))', 1))->toMatch('/^ISIMS-[A-Z2-7]{16}$/')
        ->and($generator->forAttempt('ISIMS', str_repeat('A', 32), 1))->toBe('ISIMS-' . str_repeat('A', 32) . '-1')
        ->and(strlen($generator->forAttempt('ISIMS', str_repeat('A', 32), 1)))->toBe(40);
});

it('generates distinct random IDs', function () {
    $generator = new GatewayOrderId();
    $ids = array_map(fn () => $generator->random('ISIMS'), range(1, 50));

    expect(array_unique($ids))->toHaveCount(50);
});

it('validates the prefix and attempt number', function (Closure $call) {
    $call(new GatewayOrderId());
})->with([
    'empty prefix' => [fn (GatewayOrderId $g) => $g->forAttempt('', 'R', 1)],
    'long prefix' => [fn (GatewayOrderId $g) => $g->forAttempt('ABCDEFGHIJK', 'R', 1)],
    'dash in prefix' => [fn (GatewayOrderId $g) => $g->random('IS-IMS')],
    'attempt zero' => [fn (GatewayOrderId $g) => $g->forAttempt('ISIMS', 'R', 0)],
])->throws(InvalidRequest::class);

it('generates transaction IDs', function () {
    expect(TransactionId::generate('refund'))->toMatch('/^refund-[0-9a-f]{16}$/')
        ->and(TransactionId::generate())->toStartWith('txn-')
        ->and(TransactionId::generate('void'))->not->toBe(TransactionId::generate('void'));

    TransactionId::generate('bad prefix');
})->throws(InvalidRequest::class);
