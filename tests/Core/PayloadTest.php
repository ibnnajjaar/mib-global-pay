<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

function samplePayload(): Payload
{
    return Payload::fromArray([
        'result' => 'SUCCESS',
        'order' => ['id' => 'ORDER-1', 'amount' => 100.5, 'currency' => 'MVR', 'totalCapturedAmount' => 0, 'blank' => ''],
        'response' => ['gatewayCode' => 'APPROVED'],
        'transaction' => [
            ['transaction' => ['id' => '1', 'amount' => '100.50'], 'result' => 'SUCCESS'],
            ['transaction' => ['id' => 2], 'result' => 'FAILURE'],
        ],
        'flags' => ['yes' => true, 'no' => 'false'],
        'attempt' => '3',
        'nullValue' => null,
    ]);
}

it('reads nested values with dot notation', function () {
    $p = samplePayload();

    expect($p->string('order.id'))->toBe('ORDER-1')
        ->and($p->string('transaction.1.transaction.id'))->toBe('2')
        ->and($p->requireString('result'))->toBe('SUCCESS')
        ->and($p->int('attempt'))->toBe(3)
        ->and($p->bool('flags.yes'))->toBeTrue()
        ->and($p->bool('flags.no'))->toBeFalse()
        ->and($p->has('order.id'))->toBeTrue();
});

it('returns null for missing keys, null and empty strings without notices', function () {
    $p = samplePayload();

    expect($p->get('order.missing'))->toBeNull()
        ->and($p->string('missing.deeply.nested'))->toBeNull()
        ->and($p->string('order.id.too.deep'))->toBeNull()
        ->and($p->string('nullValue'))->toBeNull()
        ->and($p->string('order.blank'))->toBeNull()
        ->and($p->int('missing'))->toBeNull()
        ->and($p->bool('missing'))->toBeNull()
        ->and($p->has('missing'))->toBeFalse()
        ->and($p->payload('missing'))->toBeNull()
        ->and($p->list('missing'))->toBe([])
        ->and($p->money('missing', 'order.currency'))->toBeNull()
        ->and(Payload::fromArray([])->string('anything'))->toBeNull();
});

it('throws for a missing required value', function () {
    samplePayload()->requireString('order.missing');
})->throws(MalformedPayload::class, 'order.missing');

it('throws for values of the wrong type', function (Closure $read) {
    $read(samplePayload());
})->with([
    'object as string' => [fn (Payload $p) => $p->string('order')],
    'string as int' => [fn (Payload $p) => $p->int('order.id')],
    'string as bool' => [fn (Payload $p) => $p->bool('order.id')],
    'scalar as object' => [fn (Payload $p) => $p->payload('order.id')],
    'object as list' => [fn (Payload $p) => $p->list('order')],
])->throws(MalformedPayload::class);

it('resolves enums, falling back to Unrecognized', function () {
    $p = samplePayload();

    expect($p->enum('result', Result::class))->toBe(Result::Success)
        ->and($p->enum('response.gatewayCode', GatewayCode::class))->toBe(GatewayCode::Approved)
        ->and($p->enum('response.missing', GatewayCode::class))->toBe(GatewayCode::Unrecognized)
        ->and($p->currency('order.currency'))->toBe(Currency::Mvr)
        ->and($p->currency('missing'))->toBe(Currency::Unrecognized);
});

it('reads money from floats, numeric strings and ints', function () {
    $p = samplePayload();

    expect($p->money('order.amount', 'order.currency')?->toDecimal())->toBe('100.50')
        ->and($p->moneyIn('transaction.0.transaction.amount', Currency::Mvr)?->minor)->toBe(10050)
        ->and($p->moneyIn('order.totalCapturedAmount', Currency::Mvr)?->isZero())->toBeTrue();
});

it('rejects amounts with too many decimals', function () {
    Payload::fromArray(['amount' => 100.505, 'currency' => 'MVR'])->money('amount', 'currency');
})->throws(MalformedPayload::class, 'not a valid amount');

it('rejects amounts whose currency is missing or unsupported', function (array $data) {
    Payload::fromArray($data)->money('amount', 'currency');
})->with([
    'missing currency' => [['amount' => 10]],
    'unsupported currency' => [['amount' => 10, 'currency' => 'XYZ']],
])->throws(MalformedPayload::class, 'currency');

it('rejects non-numeric amounts', function (mixed $amount) {
    Payload::fromArray(['amount' => $amount])->moneyIn('amount', Currency::Mvr);
})->with([['abc'], [true], [[1]]])->throws(MalformedPayload::class);

it('wraps nested objects and lists', function () {
    $p = samplePayload();

    $order = $p->payload('order');
    $transactions = $p->list('transaction');

    expect($order?->string('id'))->toBe('ORDER-1')
        ->and($transactions)->toHaveCount(2)
        ->and($transactions[1]->enum('result', Result::class))->toBe(Result::Failure)
        ->and($p->raw()['result'])->toBe('SUCCESS');
});

it('rejects lists that contain non-objects', function () {
    Payload::fromArray(['items' => [['a' => 1], 'oops']])->list('items');
})->throws(MalformedPayload::class, 'items.1');
