<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalIds;
use IbnNajjaar\MIBGlobalPay\Core\Support\TransactionId;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalHelper;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalMethod;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;

function approvedVoid(): array
{
    return ['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => 'void-1', 'type' => 'VOID_PAYMENT', 'targetTransactionId' => '1']];
}

function voidResponse(string $result, ?string $code): array
{
    return ['result' => $result, 'response' => ['gatewayCode' => $code], 'transaction' => ['id' => 'void-1', 'type' => 'VOID_PAYMENT', 'targetTransactionId' => '1']];
}

/**
 * order_captured.json with extra transactions listed and, optionally, the captured total changed.
 *
 * @param list<array<string, mixed>> $transactions
 */
function capturedOrderWith(array $transactions, ?float $totalCaptured = null): array
{
    $order = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/v2/order_captured.json'), true);
    $order['transaction'] = [...$order['transaction'], ...$transactions];
    $order['totalCapturedAmount'] = $totalCaptured ?? $order['totalCapturedAmount'];

    return $order;
}

function refundResponse(string $result = 'SUCCESS', string $code = 'APPROVED'): array
{
    return ['result' => $result, 'response' => ['gatewayCode' => $code], 'transaction' => ['id' => 'refund-1', 'type' => 'REFUND', 'amount' => 1250.5, 'currency' => 'MVR']];
}

function voidRejected(): Response
{
    return GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Void not allowed after settlement']));
}

/** A refund as Retrieve Order lists it. */
function listedRefund(string $id, string $result, string $code, float $amount = 1250.5, ?string $target = '1'): array
{
    return ['result' => $result, 'response' => ['gatewayCode' => $code], 'transaction' => ['id' => $id, 'type' => 'REFUND', 'amount' => $amount, 'currency' => 'MVR', 'targetTransactionId' => $target]];
}

function listedCapture(string $id): array
{
    return ['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => $id, 'type' => 'CAPTURE', 'amount' => 10, 'currency' => 'MVR']];
}

/**
 * @param list<array<string, mixed>> $transactions
 */
function refundedOrderWith(array $transactions, float $totalRefunded): array
{
    return ['totalRefundedAmount' => $totalRefunded] + capturedOrderWith($transactions);
}

it('voids the approved payment when the gateway accepts the void', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), GatewayHarness::json(200, approvedVoid())]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1', 'DOUBLE-CAPTURE');

    expect($outcome->method)->toBe(ReversalMethod::Void)
        ->and($outcome->reversed)->toBeTrue()
        ->and($h->history)->toHaveCount(2)
        ->and($h->request(1)->getUri()->getPath())->toEndWith('/order/ORDER-1/transaction/void-1')
        ->and($h->requestBody(1))->toBe(['apiOperation' => 'VOID', 'transaction' => ['targetTransactionId' => '1', 'reference' => 'DOUBLE-CAPTURE']]);
});

it('refunds the refundable amount when the void is declined', function () {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::fixture(200, 'transaction_void_declined.json'),
        GatewayHarness::json(200, refundResponse()),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->reversed)->toBeTrue()
        ->and($outcome->voidAttempt?->isFailure())->toBeTrue()
        ->and($h->request(2)->getUri()->getPath())->toEndWith('/transaction/refund-1')
        ->and($h->requestBody(2))->toBe(['apiOperation' => 'REFUND', 'transaction' => ['amount' => '1250.50', 'currency' => 'MVR', 'targetTransactionId' => '1']]);
});

it('refunds after an HTTP 400 on the void only once a read shows the void absent or definitely failed', function (mixed $read) {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        voidRejected(),
        $read,
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::json(200, refundResponse()),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->voidError?->httpStatus())->toBe(400)
        ->and($outcome->reversed)->toBeTrue()
        ->and($h->history)->toHaveCount(5)
        ->and($h->request(2)->getMethod())->toBe('GET')
        ->and($h->request(2)->getUri()->getPath())->toEndWith('/order/ORDER-1/transaction/void-1')
        ->and($h->request(3)->getMethod())->toBe('GET')
        ->and($h->request(3)->getUri()->getPath())->toEndWith('/order/ORDER-1')
        ->and($h->requestBody(4))->toBe(['apiOperation' => 'REFUND', 'transaction' => ['amount' => '1250.50', 'currency' => 'MVR', 'targetTransactionId' => '1']]);
})->with([
    'read gives a gateway 404' => fn () => GatewayHarness::notFound(),
    'read gives a gateway 400, not found' => fn () => GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Transaction not found'])),
    'read gives the MIB test gateway 400' => fn () => GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Unable to find order ORDER-1 and transaction void-1 for merchant TEST123'])),
    'read gives 200, empty' => fn () => GatewayHarness::json(200, ['merchant' => 'TEST123']),
    'read shows a definite decline' => fn () => GatewayHarness::json(200, voidResponse('FAILURE', 'DECLINED')),
]);

it('reports a declined refund as not reversed', function () {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::fixture(200, 'transaction_void_declined.json'),
        GatewayHarness::json(200, refundResponse('FAILURE', 'DECLINED')),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)->and($outcome->reversed)->toBeFalse();
});

it('does not fall back to a refund on a 5xx void error', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), GatewayHarness::json(503, '')]);

    expect(fn () => (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1'))->toThrow(GatewayException::class)
        ->and($h->history)->toHaveCount(2);
});

it('does nothing when there is no approved capture or nothing left to refund', function (string $fixture) {
    $h = new GatewayHarness([GatewayHarness::fixture(200, $fixture)]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Nothing)
        ->and($outcome->reversed)->toBeFalse()
        ->and($h->history)->toHaveCount(1);
})->with(['order_without_totals.json']);

it('sends no refund and reports unconfirmed when the void may still succeed', function (string $result, ?string $code) {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), GatewayHarness::json(200, voidResponse($result, $code))]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($outcome->reversed)->toBeFalse()
        ->and($outcome->transaction?->transactionId)->toBe('void-1')
        ->and($outcome->voidAttempt?->transactionId)->toBe('void-1')
        ->and($h->history)->toHaveCount(2)
        ->and($h->requestBody(1)['apiOperation'])->toBe('VOID');
})->with([
    'pending' => ['PENDING', 'PENDING'],
    'pending, no code' => ['PENDING', null],
    'unknown' => ['UNKNOWN', 'UNKNOWN'],
    'unknown result, declined code' => ['UNKNOWN', 'DECLINED'],
    'success, submitted' => ['SUCCESS', 'SUBMITTED'],
    'success, approved auto not opted in' => ['SUCCESS', 'APPROVED_AUTO'],
    'failure, timed out' => ['FAILURE', 'TIMED_OUT'],
    'failure, system error' => ['FAILURE', 'SYSTEM_ERROR'],
    'failure, lock failure' => ['FAILURE', 'LOCK_FAILURE'],
    'failure, unspecified failure' => ['FAILURE', 'UNSPECIFIED_FAILURE'],
    'failure, code not recognized' => ['FAILURE', 'SOMETHING_NEW'],
    'result not recognized' => ['SOMETHING_NEW', 'DECLINED'],
]);

it('sends nothing on a resend while the order lists the void as unsettled, even if a VOID would be rejected', function (string $result, string $code) {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::json(200, voidResponse($result, $code)),
        GatewayHarness::json(200, capturedOrderWith([voidResponse($result, $code)])),
        // Never consumed: what a gateway could answer to a duplicate VOID, then to a refund.
        GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Transaction ID already used'])),
        GatewayHarness::json(200, refundResponse()),
    ]);
    $helper = new ReversalHelper($h->gateway);

    $helper->reverse('ORDER-1', 'void-1', 'refund-1');
    $resend = $helper->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($resend->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($resend->reversed)->toBeFalse()
        ->and($resend->transaction?->transactionId)->toBe('void-1')
        ->and($h->history)->toHaveCount(3)
        ->and($h->request(2)->getMethod())->toBe('GET');
})->with([
    'pending' => ['PENDING', 'PENDING'],
    'unknown' => ['UNKNOWN', 'UNKNOWN'],
    'unspecified failure' => ['FAILURE', 'UNSPECIFIED_FAILURE'],
]);

it('reports the void and sends nothing on a resend once the order lists it as approved', function (?float $totalCaptured) {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::json(200, voidResponse('PENDING', 'PENDING')),
        GatewayHarness::json(200, capturedOrderWith([approvedVoid()], $totalCaptured)),
    ]);
    $helper = new ReversalHelper($h->gateway);

    $helper->reverse('ORDER-1', 'void-1', 'refund-1');
    $resend = $helper->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($resend->method)->toBe(ReversalMethod::Void)
        ->and($resend->reversed)->toBeTrue()
        ->and($h->history)->toHaveCount(3);
})->with([
    'captured total unchanged' => [null],
    'captured total reduced to zero' => [0.0],
]);

it('refunds exactly once, without replaying the VOID, on a resend after the pending void was finally declined', function () {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::json(200, voidResponse('PENDING', 'PENDING')),
        GatewayHarness::json(200, capturedOrderWith([voidResponse('FAILURE', 'DECLINED')])),
        GatewayHarness::json(200, refundResponse()),
    ]);
    $helper = new ReversalHelper($h->gateway);

    $helper->reverse('ORDER-1', 'void-1', 'refund-1');
    $resend = $helper->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($resend->method)->toBe(ReversalMethod::Refund)
        ->and($resend->reversed)->toBeTrue()
        ->and($resend->voidAttempt?->transactionId)->toBe('void-1')
        ->and($h->history)->toHaveCount(4)
        ->and($h->requestBody(3)['apiOperation'])->toBe('REFUND')
        ->and($h->request(3)->getUri()->getPath())->toEndWith('/transaction/refund-1');
});

it('sends nothing while a void under another transaction ID is unsettled for the same target', function () {
    $other = voidResponse('PENDING', 'PENDING');
    $other['transaction']['id'] = 'void-other';
    $h = new GatewayHarness([GatewayHarness::json(200, capturedOrderWith([$other]))]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($outcome->transaction?->transactionId)->toBe('void-other')
        ->and($h->history)->toHaveCount(1);
});

it('does not fall back to a refund on a void error that is neither 4xx nor 5xx', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), GatewayHarness::json(302, '')]);

    expect(fn () => (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1'))->toThrow(GatewayException::class)
        ->and($h->history)->toHaveCount(2);
});

it('does not fall back to a refund on any 4xx except 400', function (int $status) {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), GatewayHarness::json($status, ['error' => ['cause' => 'REQUEST_REJECTED']])]);

    expect(fn () => (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1'))->toThrow(GatewayException::class)
        ->and($h->history)->toHaveCount(2);
})->with([401, 403, 404, 405, 408, 409, 422, 425, 429]);

it('reports unconfirmed after an HTTP 400 when the read shows the void is not absent or definitely failed', function (array $read) {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        voidRejected(),
        GatewayHarness::json(200, $read),
        GatewayHarness::fixture(200, 'order_captured.json'),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($outcome->reversed)->toBeFalse()
        ->and($outcome->voidError?->httpStatus())->toBe(400)
        ->and($h->history)->toHaveCount(4);
})->with([
    'pending' => [voidResponse('PENDING', 'PENDING')],
    'unknown' => [voidResponse('UNKNOWN', 'UNKNOWN')],
    'unspecified failure' => [voidResponse('FAILURE', 'UNSPECIFIED_FAILURE')],
    'approved' => [approvedVoid()],
    'a result with no transaction ID' => [['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED']]],
    'another transaction' => [['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED'], 'transaction' => ['id' => 'other', 'type' => 'VOID_PAYMENT']]],
]);

it('reports unconfirmed after an HTTP 400 when the re-read order disagrees, even if the void read found nothing', function (array $order) {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        voidRejected(),
        GatewayHarness::notFound(),
        GatewayHarness::json(200, $order),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($outcome->reversed)->toBeFalse()
        ->and($h->history)->toHaveCount(4);
})->with([
    'void listed as pending' => [capturedOrderWith([voidResponse('PENDING', 'PENDING')])],
    'void listed as approved' => [capturedOrderWith([approvedVoid()])],
    'captured total moved' => [capturedOrderWith([], 1000.0)],
    'refunded total moved' => [refundedOrderWith([listedRefund('host-1', 'SUCCESS', 'APPROVED', 100)], 100)],
    'a new capture is now the latest' => [capturedOrderWith([listedCapture('2')])],
]);

it('sends no refund when a read after an HTTP 400 fails, and rethrows', function (array $responses, string $exception) {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), voidRejected(), ...$responses]);

    expect(fn () => (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1'))->toThrow($exception)
        ->and($h->history)->toHaveCount(2 + count($responses));
})->with([
    'void read: no response' => fn () => [[new ConnectException('timed out', new Psr7Request('GET', 'https://example.test'))], TransportException::class],
    'void read: 500' => fn () => [[GatewayHarness::json(500, '')], GatewayException::class],
    'void read: 401' => fn () => [[GatewayHarness::json(401, GatewayHarness::errorBody(['explanation' => 'not found']))], GatewayException::class],
    'void read: 429' => fn () => [[GatewayHarness::json(429, '')], GatewayException::class],
    'void read: 400 not saying not found' => fn () => [[GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Bad value']))], GatewayException::class],
    'void read: 404 HTML page' => fn () => [[GatewayHarness::json(404, '<html>Not Found</html>')], GatewayException::class],
    'void read: 404 empty' => fn () => [[GatewayHarness::json(404, '')], GatewayException::class],
    'void read: 404 other JSON' => fn () => [[GatewayHarness::json(404, ['message' => 'Not Found'])], GatewayException::class],
    'void read: 404 with no result field' => fn () => [[GatewayHarness::json(404, ['error' => ['cause' => 'INVALID_REQUEST']])], GatewayException::class],
    'void read: 404 for a rejected request' => fn () => [[GatewayHarness::json(404, GatewayHarness::errorBody(cause: 'REQUEST_REJECTED'))], GatewayException::class],
    'void read: 400 HTML page saying transaction not found' => fn () => [[GatewayHarness::json(400, '<html>Transaction not found</html>')], GatewayException::class],
    'void read: 400 saying the order is missing' => fn () => [[GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Order not found']))], GatewayException::class],
    'void read: 400 saying something else is missing' => fn () => [[GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Merchant not found for transaction void-1']))], GatewayException::class],
    'void read: 400 naming another field' => fn () => [[GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Transaction not found', 'field' => 'transaction.reference', 'validationType' => 'INVALID']))], GatewayException::class],
    'void read: 200 that cannot be parsed' => fn () => [[GatewayHarness::json(200, '<html>')], MalformedPayload::class],
    'order read: 500' => fn () => [[GatewayHarness::notFound(), GatewayHarness::json(500, '')], GatewayException::class],
    'order read: no response' => fn () => [[GatewayHarness::notFound(), new ConnectException('timed out', new Psr7Request('GET', 'https://example.test'))], TransportException::class],
]);

it('sends no refund, and reads nothing, when the HTTP 400 on the void is not a gateway error', function (array|string $body) {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), GatewayHarness::json(400, $body)]);

    expect(fn () => (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1'))->toThrow(GatewayException::class)
        ->and($h->history)->toHaveCount(2);
})->with([
    'HTML page' => ['<html>Bad Request</html>'],
    'empty' => [''],
    'other JSON' => [['message' => 'Bad Request']],
    'no result field' => [['error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Void not allowed']]],
]);

it('reports an approved refund and sends nothing on a resend, instead of Nothing', function (?float $totalRefunded) {
    $order = $totalRefunded === null
        ? capturedOrderWith([voidResponse('FAILURE', 'DECLINED'), listedRefund('refund-1', 'SUCCESS', 'APPROVED')])
        : refundedOrderWith([voidResponse('FAILURE', 'DECLINED'), listedRefund('refund-1', 'SUCCESS', 'APPROVED')], $totalRefunded);
    $h = new GatewayHarness([GatewayHarness::json(200, $order)]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->reversed)->toBeTrue()
        ->and($outcome->transaction?->transactionId)->toBe('refund-1')
        ->and($h->history)->toHaveCount(1);
})->with([
    'refunded total not updated yet' => [null],
    'nothing left to refund' => [1250.5],
]);

it('matches our refund by its ID, whatever its amount or target says', function () {
    $h = new GatewayHarness([GatewayHarness::json(200, capturedOrderWith([listedRefund('refund-1', 'SUCCESS', 'APPROVED', 100, null)]))]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->reversed)->toBeTrue()
        ->and($h->history)->toHaveCount(1);
});

it('reports unconfirmed and sends nothing while our refund is unsettled', function (string $result, string $code) {
    $h = new GatewayHarness([GatewayHarness::json(200, capturedOrderWith([voidResponse('FAILURE', 'DECLINED'), listedRefund('refund-1', $result, $code)]))]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($outcome->reversed)->toBeFalse()
        ->and($outcome->transaction?->transactionId)->toBe('refund-1')
        ->and($h->history)->toHaveCount(1);
})->with([
    'pending' => ['PENDING', 'PENDING'],
    'unknown' => ['UNKNOWN', 'UNKNOWN'],
    'unspecified failure' => ['FAILURE', 'UNSPECIFIED_FAILURE'],
]);

it('reports a definitely failed refund as final and sends nothing on a resend', function () {
    $h = new GatewayHarness([GatewayHarness::json(200, capturedOrderWith([voidResponse('FAILURE', 'DECLINED'), listedRefund('refund-1', 'FAILURE', 'DECLINED')]))]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->reversed)->toBeFalse()
        ->and($outcome->transaction?->transactionId)->toBe('refund-1')
        ->and($h->history)->toHaveCount(1);
});

it('lets the host retry a definitely failed refund with a new refund ID, without replaying the void', function () {
    $h = new GatewayHarness([
        GatewayHarness::json(200, capturedOrderWith([voidResponse('FAILURE', 'DECLINED'), listedRefund('refund-1', 'FAILURE', 'DECLINED')])),
        GatewayHarness::json(200, refundResponse()),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-2');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->reversed)->toBeTrue()
        ->and($h->history)->toHaveCount(2)
        ->and($h->requestBody(1)['apiOperation'])->toBe('REFUND')
        ->and($h->request(1)->getUri()->getPath())->toEndWith('/transaction/refund-2');
});

it('reports unconfirmed when the refund it just sent is inconclusive', function (string $result, string $code) {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::fixture(200, 'transaction_void_declined.json'),
        GatewayHarness::json(200, refundResponse($result, $code)),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($outcome->reversed)->toBeFalse()
        ->and($outcome->transaction?->transactionId)->toBe('refund-1');
})->with([
    'pending' => ['PENDING', 'PENDING'],
    'unspecified failure' => ['FAILURE', 'UNSPECIFIED_FAILURE'],
]);

it('does not mistake a partial refund by the host for this reversal', function () {
    $h = new GatewayHarness([
        GatewayHarness::json(200, refundedOrderWith([listedRefund('host-1', 'SUCCESS', 'APPROVED', 100)], 100)),
        GatewayHarness::fixture(200, 'transaction_void_declined.json'),
        GatewayHarness::json(200, refundResponse()),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->transaction?->transactionId)->toBe('refund-1')
        ->and($h->history)->toHaveCount(3)
        ->and($h->requestBody(1)['apiOperation'])->toBe('VOID')
        ->and($h->requestBody(2)['transaction'])->toMatchArray(['amount' => '1150.50', 'targetTransactionId' => '1']);
});

it('treats a refund under another ID as this reversal only if it targets this capture and covers all of it', function () {
    $h = new GatewayHarness([GatewayHarness::json(200, refundedOrderWith([listedRefund('finance-1', 'SUCCESS', 'APPROVED')], 1250.5))]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->reversed)->toBeTrue()
        ->and($outcome->transaction?->transactionId)->toBe('finance-1')
        ->and($h->history)->toHaveCount(1);
});

it('does not count a full refund of another capture, or of no stated capture, as this reversal', function (?string $refundTarget) {
    $h = new GatewayHarness([GatewayHarness::json(200, refundedOrderWith([listedRefund('finance-1', 'SUCCESS', 'APPROVED', target: $refundTarget)], 1250.5))]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Nothing)
        ->and($outcome->reversed)->toBeFalse()
        ->and($h->history)->toHaveCount(1);
})->with([
    'another capture' => ['other'],
    'no target' => [null],
]);

it('sends nothing while another void or refund on the order is unsettled, because the totals may change', function (array $unsettled) {
    $h = new GatewayHarness([GatewayHarness::json(200, capturedOrderWith([$unsettled]))]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($outcome->reversed)->toBeFalse()
        ->and($h->history)->toHaveCount(1);
})->with([
    'partial refund for this capture' => [listedRefund('host-1', 'PENDING', 'PENDING', 100)],
    'refund with no target' => [listedRefund('host-1', 'PENDING', 'PENDING', 100, null)],
    'void of another capture' => [['result' => 'PENDING', 'response' => ['gatewayCode' => 'PENDING'], 'transaction' => ['id' => 'void-old', 'type' => 'VOID_CAPTURE', 'targetTransactionId' => 'old']]],
]);

it('ignores a failed refund or an approved void of another capture', function (array $other) {
    $h = new GatewayHarness([GatewayHarness::json(200, capturedOrderWith([$other])), GatewayHarness::json(200, approvedVoid())]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Void)
        ->and($outcome->transaction?->targetTransactionId)->toBe('1')
        ->and($h->history)->toHaveCount(2);
})->with([
    'failed refund under another ID' => [listedRefund('host-1', 'FAILURE', 'DECLINED')],
    'approved void of another capture' => [['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => 'void-old', 'type' => 'VOID_CAPTURE', 'targetTransactionId' => 'old']]],
]);


it('resends the void and the refund with identical requests after no response', function () {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::fixture(200, 'transaction_void_declined.json'),
        new ConnectException('timed out', new Psr7Request('PUT', 'https://example.test')),
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::fixture(200, 'transaction_void_declined.json'),
        GatewayHarness::json(200, refundResponse()),
    ]);
    $helper = new ReversalHelper($h->gateway);

    expect(fn () => $helper->reverseWithDerivedIds('ORDER-1', 'REF'))->toThrow(TransportException::class);

    $outcome = $helper->reverseWithDerivedIds('ORDER-1', 'REF');

    expect($outcome->reversed)->toBeTrue()
        ->and($outcome->ids)->toEqual(ReversalIds::derive('ORDER-1', '1'))
        ->and($h->request(4)->getUri()->getPath())->toBe($h->request(1)->getUri()->getPath())
        ->and($h->requestBody(4))->toBe($h->requestBody(1))
        ->and($h->request(5)->getUri()->getPath())->toBe($h->request(2)->getUri()->getPath())
        ->and($h->requestBody(5))->toBe($h->requestBody(2));
});

it('recognises its own derived IDs on the order when called again', function () {
    $ids = ReversalIds::derive('ORDER-1', '1');
    $refund = listedRefund($ids->refund, 'SUCCESS', 'APPROVED');
    $h = new GatewayHarness([GatewayHarness::json(200, refundedOrderWith([$refund], 1250.5))]);

    $outcome = (new ReversalHelper($h->gateway))->reverseWithDerivedIds('ORDER-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->reversed)->toBeTrue()
        ->and($h->history)->toHaveCount(1);
});

it('keeps the target on a voided capture, so a second run does not reverse an earlier capture', function () {
    $h = new GatewayHarness([GatewayHarness::json(200, capturedOrderWith([listedCapture('2'), ['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => TransactionId::derive('void', 'ORDER-1', '2'), 'type' => 'VOID_CAPTURE', 'targetTransactionId' => '2']]]))]);

    $outcome = (new ReversalHelper($h->gateway))->reverseWithDerivedIds('ORDER-1');

    expect($outcome->method)->toBe(ReversalMethod::Void)
        ->and($outcome->ids?->target)->toBe('2')
        ->and($h->history)->toHaveCount(1);
});

it('gives a new capture on the order its own IDs, and sends nothing while the old reversal is unsettled', function () {
    $oldVoid = voidResponse('PENDING', 'PENDING');
    $oldVoid['transaction']['id'] = TransactionId::derive('void', 'ORDER-1', '1');
    $h = new GatewayHarness([GatewayHarness::json(200, capturedOrderWith([$oldVoid, listedCapture('2')]))]);

    $outcome = (new ReversalHelper($h->gateway))->reverseWithDerivedIds('ORDER-1');

    expect($outcome->method)->toBe(ReversalMethod::Unconfirmed)
        ->and($outcome->ids)->toEqual(ReversalIds::derive('ORDER-1', '2'))
        ->and($h->history)->toHaveCount(1);
});

it('returns Nothing with no IDs when the order has no approved capture', function () {
    $h = new GatewayHarness([GatewayHarness::json(200, ['id' => 'ORDER-1', 'currency' => 'MVR', 'transaction' => []])]);

    $outcome = (new ReversalHelper($h->gateway))->reverseWithDerivedIds('ORDER-1');

    expect($outcome->method)->toBe(ReversalMethod::Nothing)
        ->and($outcome->ids)->toBeNull();
});
