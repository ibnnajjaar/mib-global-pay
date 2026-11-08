<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptGuard;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryAttempt;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryPayable;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Verification\CaptureVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;

/**
 * Every response shape, through the real MibGateway and AttemptGuard over a mocked HTTP client. The
 * attempt is expired, the one case where NotFound lets a new attempt start.
 */
function ruleAmount(): Money
{
    return Money::of('10', Currency::Mvr);
}

/** @return array{AttemptGuard, InMemoryPayable, InMemoryAttempt} */
function expiredAttemptGuard(GatewayHarness $h): array
{
    $attempt = new InMemoryAttempt(1, 'SHOP-R-1-1', ruleAmount(), AttemptState::Expired, new DateTimeImmutable('2026-01-01 00:00:00'));

    return [new AttemptGuard($h->gateway, fn () => new DateTimeImmutable('2026-01-02 00:00:00')), new InMemoryPayable(ruleAmount(), attempts: [$attempt]), $attempt];
}

$notGateway = [
    '404 HTML page' => [404, '<html><body>404 Not Found</body></html>'],
    '404 empty' => [404, ''],
    '404 plain text' => [404, 'Order not found'],
    '404 empty JSON object' => [404, '{}'],
    '404 JSON list' => [404, '[]'],
    '404 other JSON' => [404, '{"message":"Not Found"}'],
    '404 JSON with a string error' => [404, '{"error":"Not Found"}'],
    '404 no result field' => [404, '{"error":{"cause":"INVALID_REQUEST","explanation":"Order not found"}}'],
    '404 another result' => [404, '{"result":"FAILURE","error":{"cause":"INVALID_REQUEST"}}'],
    '404 no cause' => [404, '{"result":"ERROR","error":{"explanation":"Order not found"}}'],
    '404 undocumented cause' => [404, '{"result":"ERROR","error":{"cause":"NOT_FOUND"}}'],
    '404 cause in the wrong case' => [404, '{"result":"ERROR","error":{"cause":"invalid_request"}}'],
    '400 HTML page saying not found' => [400, '<html><body>Order not found</body></html>'],
    '400 empty' => [400, ''],
    '400 other JSON saying not found' => [400, '{"message":"Order not found"}'],
    '400 no result field' => [400, '{"error":{"cause":"INVALID_REQUEST","explanation":"Order not found"}}'],
];

$gatewayButNotNotFound = [
    '404 request rejected' => [404, ['result' => 'ERROR', 'error' => ['cause' => 'REQUEST_REJECTED', 'supportCode' => 'x']]],
    '404 server busy' => [404, ['result' => 'ERROR', 'error' => ['cause' => 'SERVER_BUSY', 'explanation' => 'Order not found']]],
    '404 server failed' => [404, ['result' => 'ERROR', 'error' => ['cause' => 'SERVER_FAILED']]],
    '404 field-level validation' => [404, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'field' => 'order.id', 'validationType' => 'INVALID']]],
    '400 no explanation' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST']]],
    '400 request rejected, saying not found' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'REQUEST_REJECTED', 'explanation' => 'Order not found']]],
    '400 about another field, by field' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order not found', 'field' => 'order.reference']]],
    '400 about another field, by validationType' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order not found', 'validationType' => 'MISSING']]],
    '400 about the order ID field' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order does not exist', 'field' => 'order.id', 'validationType' => 'INVALID']]],
    '400 other wording' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Value is invalid']]],
    '400 another thing not found' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Merchant not found']]],
    '400 another thing not found for the order' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Session not found for order SHOP-R-1-1']]],
    '400 transaction not found' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Transaction not found']]],
    '400 order reference not found' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order reference not found']]],
    '400 order.reference does not exist' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'order.reference does not exist']]],
    '400 unable to find order.reference' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Unable to find order.reference']]],
    '400 echoing a value' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => "Value 'order not found' is invalid"]]],
    '401 saying not found' => [401, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order not found']]],
    '500 saying not found' => [500, ['result' => 'ERROR', 'error' => ['cause' => 'SERVER_FAILED', 'explanation' => 'Order not found']]],
];

$gatewayNotFound = [
    '404 no explanation' => [404, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST']]],
    '404 not-found wording' => [404, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Unable to find order']]],
    '404 no not-found wording' => [404, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Request could not be completed']]],
    '400 unable to find order' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Unable to find order SHOP-R-1-1']]],
    '400 order not found' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order not found']]],
    '400 order with an ID not found' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order SHOP-R-1-1 not found']]],
    '400 the order with id was not found' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'The order with id SHOP-R-1-1 was not found']]],
    '400 order does not exist' => [400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order does not exist']]],
];

it('throws from verifyCapture when the response is not a gateway error', function (int $status, array|string $body) {
    $h = new GatewayHarness([GatewayHarness::json($status, $body)]);

    expect(fn () => $h->gateway->verifyCapture('SHOP-R-1-1', ruleAmount()))->toThrow(GatewayException::class);
})->with($notGateway);

it('allows no new attempt when the response is not a gateway error', function (int $status, array|string $body) {
    $h = new GatewayHarness([GatewayHarness::json($status, $body)]);
    [$guard, $payable] = expiredAttemptGuard($h);

    expect(fn () => $guard->canStartNewAttempt($payable))->toThrow(GatewayException::class);
})->with($notGateway);

it('throws from verifyCapture on a gateway error that is not about a missing order', function (int $status, array $body) {
    $h = new GatewayHarness([GatewayHarness::json($status, $body)]);

    expect(fn () => $h->gateway->verifyCapture('SHOP-R-1-1', ruleAmount()))->toThrow(GatewayException::class);
})->with($gatewayButNotNotFound);

it('allows no new attempt on a gateway error that is not about a missing order', function (int $status, array $body) {
    $h = new GatewayHarness([GatewayHarness::json($status, $body)]);
    [$guard, $payable] = expiredAttemptGuard($h);

    expect(fn () => $guard->canStartNewAttempt($payable))->toThrow(GatewayException::class);
})->with($gatewayButNotNotFound);

it('gives NotFound from verifyCapture for each accepted not-found form', function (int $status, array $body) {
    $h = new GatewayHarness([GatewayHarness::json($status, $body)]);

    expect($h->gateway->verifyCapture('SHOP-R-1-1', ruleAmount())->status)->toBe(VerificationStatus::NotFound);
})->with($gatewayNotFound);

it('lets an expired attempt be superseded for each accepted not-found form', function (int $status, array $body) {
    $h = new GatewayHarness([GatewayHarness::json($status, $body)]);
    [$guard, $payable, $attempt] = expiredAttemptGuard($h);

    $result = $guard->canStartNewAttempt($payable);

    expect($result->allowed)->toBeTrue()
        ->and($result->attemptsToSupersede)->toBe([$attempt])
        ->and($result->verifications['SHOP-R-1-1']->status)->toBe(VerificationStatus::NotFound);
})->with($gatewayNotFound);

it('applies the same rule to a transaction, naming the transaction on a 400', function (int $status, array $body, bool $expected) {
    $e = new GatewayException('x', $status, $body['error'] ?? [], (string) json_encode($body));

    expect(CaptureVerifier::isTransactionNotFound($e))->toBe($expected);
})->with([
    '404' => [404, GatewayHarness::errorBody(), true],
    '400 transaction not found' => [400, GatewayHarness::errorBody(['explanation' => 'Transaction not found']), true],
    '400 unable to find transaction' => [400, GatewayHarness::errorBody(['explanation' => 'Unable to find transaction void-1']), true],
    '400 order not found' => [400, GatewayHarness::errorBody(['explanation' => 'Order not found']), false],
    '400 transaction.reference' => [400, GatewayHarness::errorBody(['explanation' => 'Unable to find transaction.reference']), false],
    '404 no result' => [404, ['error' => ['cause' => 'INVALID_REQUEST']], false],
]);

it('tells the operator what happened, naming the host and no secret', function (int $status, array|string $body) {
    $h = new GatewayHarness([GatewayHarness::json($status, $body)], ['host' => 'wrong-host.example.test']);
    [$guard, $payable] = expiredAttemptGuard($h);

    try {
        $guard->canStartNewAttempt($payable);
        $this->fail('Expected a GatewayException');
    } catch (GatewayException $e) {
        $authorization = $h->request()->getHeaderLine('Authorization');

        expect($authorization)->not->toBe('')
            ->and($e->getMessage())->toContain("HTTP {$status} from wrong-host.example.test")
            ->and($e->getMessage())->toContain('not recognised as a gateway error')
            ->and($e->getMessage())->toContain('gateway host')
            ->and($e->getMessage())->not->toContain('super-secret-password')
            ->and($e->getMessage())->not->toContain(substr($authorization, 6))
            ->and($e->getMessage())->not->toContain('leaked')
            ->and($e->httpStatus())->toBe($status)
            ->and($e->isGatewayError())->toBeFalse();
    }
})->with([
    '404 HTML' => [404, '<html>leaked</html>'],
    '400 HTML' => [400, '<html>leaked</html>'],
    '404 empty' => [404, ''],
    '404 JSON with its own explanation' => [404, ['error' => ['explanation' => 'leaked']]],
]);

it('keeps the gateway explanation as the message of a gateway error', function () {
    $h = new GatewayHarness([GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Value is invalid']))]);

    expect(fn () => $h->gateway->verifyCapture('SHOP-R-1-1', ruleAmount()))->toThrow(GatewayException::class, 'Value is invalid');
});
