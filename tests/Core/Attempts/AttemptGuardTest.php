<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptGuard;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\GuardRefusal;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryAttempt;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryPayable;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;

const GUARD_NOW = '2026-10-28 12:00:00';

function amount(): Money
{
    return Money::of('1250.50', Currency::Mvr);
}

function attempt(int $n, AttemptState $state, string $expires = '2026-10-28 11:00:00'): InMemoryAttempt
{
    return new InMemoryAttempt($n, "ISIMS-R-IUM-FN-2026-123-{$n}", amount(), $state, new DateTimeImmutable($expires));
}

function guard(array $responses = []): array
{
    $h = new GatewayHarness($responses);

    return [new AttemptGuard($h->gateway, fn () => new DateTimeImmutable(GUARD_NOW)), $h];
}

function orderBody(string $code = 'APPROVED', string $result = 'SUCCESS', float $captured = 1250.5): array
{
    return [
        'id' => 'ISIMS-R-IUM-FN-2026-123-1', 'currency' => 'MVR', 'totalCapturedAmount' => $captured,
        'transaction' => [['result' => $result, 'response' => ['gatewayCode' => $code], 'transaction' => ['id' => '1', 'type' => 'PAYMENT', 'amount' => 1250.5, 'currency' => 'MVR']]],
    ];
}

it('allows the first attempt without asking the gateway', function () {
    [$guard, $h] = guard();

    $result = $guard->canStartNewAttempt(new InMemoryPayable(amount(), PayableState::Open));

    expect($result->allowed)->toBeTrue()
        ->and($result->reason)->toBeNull()
        ->and($result->nextAttemptNumber)->toBe(1)
        ->and($result->attemptsToSupersede)->toBe([])
        ->and($h->history)->toBe([]);
});

it('refuses payables that are not Open or Frozen', function (PayableState $state) {
    [$guard] = guard();

    expect($guard->canStartNewAttempt(new InMemoryPayable(amount(), $state))->reason)->toBe(GuardRefusal::PayableNotPayable);
})->with([PayableState::Cancelled, PayableState::Failed, PayableState::Paid]);

it('refuses a settled payable or one with a captured attempt', function () {
    [$guard] = guard();
    $settled = new InMemoryPayable(amount(), settlement: new Settlement('O-1', '1', amount(), new DateTimeImmutable()));
    $captured = new InMemoryPayable(amount(), attempts: [attempt(1, AttemptState::Captured)]);

    expect($guard->canStartNewAttempt($settled)->reason)->toBe(GuardRefusal::AlreadySettled)
        ->and($guard->canStartNewAttempt($captured)->reason)->toBe(GuardRefusal::AttemptCaptured)
        ->and($guard->canStartNewAttempt($captured)->blockingAttempt?->attemptNumber())->toBe(1);
});

it('refuses while an earlier attempt has not expired and offers a live one for reuse', function (AttemptState $state, bool $reusable) {
    [$guard, $h] = guard();
    $live = attempt(1, $state, '2026-10-28 12:30:00');

    $result = $guard->canStartNewAttempt(new InMemoryPayable(amount(), attempts: [$live]));

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toBe(GuardRefusal::AttemptStillLive)
        ->and($result->reusableAttempt)->toBe($reusable ? $live : null)
        ->and($result->blockingAttempt)->toBe($live)
        ->and($h->history)->toBe([]);
})->with([
    'live' => [AttemptState::Live, true],
    'created (response may have been lost)' => [AttemptState::Created, false],
]);

it('allows a new attempt once the earlier one expired and verifies as not captured', function (array $body, int $status, VerificationStatus $verified) {
    [$guard, $h] = guard([GatewayHarness::json($status, $body)]);
    $expired = attempt(1, AttemptState::Expired);

    $result = $guard->canStartNewAttempt(new InMemoryPayable(amount(), attempts: [$expired]));

    expect($result->allowed)->toBeTrue()
        ->and($result->nextAttemptNumber)->toBe(2)
        ->and($result->attemptsToSupersede)->toBe([$expired])
        ->and($result->verifications['ISIMS-R-IUM-FN-2026-123-1']->status)->toBe($verified)
        ->and($h->request()->getUri()->getPath())->toEndWith('/order/ISIMS-R-IUM-FN-2026-123-1');
})->with([
    'declined' => [orderBody('DECLINED', 'FAILURE', 0), 200, VerificationStatus::NotCaptured],
    'never paid (order unknown)' => [[], 404, VerificationStatus::NotFound],
    'reversed' => [orderBody() + ['totalRefundedAmount' => 1250.5], 200, VerificationStatus::Reversed],
]);

it('refuses when an expired attempt turns out to be captured', function (array $body) {
    [$guard] = guard([GatewayHarness::json(200, $body)]);

    $result = $guard->canStartNewAttempt(new InMemoryPayable(amount(), attempts: [attempt(1, AttemptState::Live)]));

    expect($result->reason)->toBe(GuardRefusal::MoneyCaptured)
        ->and($result->blockingAttempt?->gatewayOrderId())->toBe('ISIMS-R-IUM-FN-2026-123-1');
})->with([
    'exact' => [orderBody()],
    'different amount' => [orderBody(captured: 10.0)],
]);

it('refuses while an expired attempt is still pending at the gateway', function () {
    [$guard] = guard([GatewayHarness::json(200, orderBody('PENDING', 'PENDING', 0))]);

    expect($guard->canStartNewAttempt(new InMemoryPayable(amount(), attempts: [attempt(1, AttemptState::Expired)]))->reason)
        ->toBe(GuardRefusal::VerificationPending);
});

it('skips failed and superseded attempts and numbers after the highest attempt', function () {
    [$guard, $h] = guard([GatewayHarness::json(404, [])]);
    $payable = new InMemoryPayable(amount(), attempts: [
        attempt(3, AttemptState::Expired),
        attempt(2, AttemptState::Superseded),
        attempt(1, AttemptState::Failed),
    ]);

    $result = $guard->canStartNewAttempt($payable);

    expect($result->allowed)->toBeTrue()
        ->and($result->nextAttemptNumber)->toBe(4)
        ->and($result->attemptsToSupersede)->toHaveCount(1)
        ->and($h->history)->toHaveCount(1);
});

it('treats an attempt exactly at expiresAt as expired', function () {
    [$guard] = guard([GatewayHarness::json(404, [])]);

    expect($guard->canStartNewAttempt(new InMemoryPayable(amount(), attempts: [attempt(1, AttemptState::Live, GUARD_NOW)]))->allowed)->toBeTrue();
});
