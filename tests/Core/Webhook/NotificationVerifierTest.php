<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Enums\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationKind;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\SecretMismatch;
use IbnNajjaar\MIBGlobalPay\Core\Verification\ApprovalPolicy;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\GenericNotification;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\TransactionNotification;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedNotification;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\AuthenticationNotification;

const WEBHOOK_SECRET = '7C1F3A9E5B2D4F6081A3C5E7092B4D6F';

function webhookBody(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/../../Fixtures/v2/webhooks/' . $name);
}

function webhookHeaders(array $overrides = []): array
{
    return array_merge([
        'X-Notification-Secret' => WEBHOOK_SECRET,
        'X-Notification-ID' => 'NOTIF-0001',
        'X-Notification-Attempt' => '1',
    ], $overrides);
}

it('fails closed when no secret is configured', function (string $secret) {
    new NotificationVerifier($secret);
})->with(['', '   '])->throws(ConfigurationException::class, 'MIB_WEBHOOK_SECRET');

it('verifies the secret and parses a payment notification', function () {
    $n = (new NotificationVerifier(WEBHOOK_SECRET))->verify(webhookBody('payment_captured.json'), webhookHeaders());

    expect($n)->toBeInstanceOf(TransactionNotification::class)
        ->and($n->kind())->toBe(NotificationKind::Transaction)
        ->and($n->id())->toBe('NOTIF-0001')
        ->and($n->attempt())->toBe(1);

    /** @var TransactionNotification $n */
    expect($n->orderId)->toBe('ISIMS-R-IUM-FN-2026-123-1')
        ->and($n->orderStatus)->toBe(OrderStatus::Captured)
        ->and($n->orderTotalCaptured?->toDecimal())->toBe('1250.50')
        ->and($n->transactionId())->toBe('1')
        ->and($n->isMoneyIn())->toBeTrue()
        ->and($n->isRefund())->toBeFalse()
        ->and($n->transaction->amount?->minor)->toBe(125050)
        ->and($n->transaction->isApproved(new ApprovalPolicy()))->toBeTrue()
        ->and($n->payload()->string('order.reference'))->toBe('(R)IUM/FN/2026/123');
});

it('reads headers case-insensitively, in plain or Laravel/Symfony form', function (array $headers) {
    $n = (new NotificationVerifier(WEBHOOK_SECRET))->verify(webhookBody('payment_captured.json'), $headers);

    expect($n->id())->toBe('NOTIF-0001')->and($n->attempt())->toBe(3);
})->with([
    'getallheaders style' => [['X-Notification-Secret' => WEBHOOK_SECRET, 'X-Notification-Id' => 'NOTIF-0001', 'X-Notification-Attempt' => '3']],
    'lowercase arrays' => [['x-notification-secret' => [WEBHOOK_SECRET], 'x-notification-id' => ['NOTIF-0001'], 'x-notification-attempt' => ['3']]],
]);

it('rejects a missing or wrong secret before parsing the body', function (array $headers) {
    (new NotificationVerifier(WEBHOOK_SECRET))->verify('not even json', $headers);
})->with([
    'missing' => [['X-Notification-ID' => 'NOTIF-0001']],
    'empty' => [webhookHeaders(['X-Notification-Secret' => ''])],
    'wrong' => [webhookHeaders(['X-Notification-Secret' => 'wrong'])],
    'case differs' => [webhookHeaders(['X-Notification-Secret' => strtolower(WEBHOOK_SECRET)])],
    'prefix only' => [webhookHeaders(['X-Notification-Secret' => substr(WEBHOOK_SECRET, 0, 16)])],
])->throws(SecretMismatch::class);

it('requires the notification ID', function () {
    (new NotificationVerifier(WEBHOOK_SECRET))->verify(webhookBody('payment_captured.json'), webhookHeaders(['X-Notification-ID' => '']));
})->throws(MalformedNotification::class, 'X-Notification-ID');

it('treats the attempt header as optional but validates it', function () {
    $verifier = new NotificationVerifier(WEBHOOK_SECRET);
    $headers = webhookHeaders();
    unset($headers['X-Notification-Attempt']);

    expect($verifier->verify(webhookBody('payment_captured.json'), $headers)->attempt())->toBeNull()
        ->and(fn () => $verifier->verify(webhookBody('payment_captured.json'), webhookHeaders(['X-Notification-Attempt' => 'two'])))
        ->toThrow(MalformedNotification::class, 'X-Notification-Attempt');
});

it('rejects bodies that are not a JSON object', function (string $body) {
    (new NotificationVerifier(WEBHOOK_SECRET))->verify($body, webhookHeaders());
})->with([
    'NVP' => ['result=SUCCESS&order.id=ORDER-1'],
    'empty' => [''],
    'list' => ['[1,2]'],
    'scalar' => ['"text"'],
])->throws(MalformedNotification::class, 'JSON object');

it('rejects transaction notifications with invalid amounts', function () {
    $body = json_decode(webhookBody('payment_captured.json'), true);
    $body['order']['totalCapturedAmount'] = 1250.505;

    (new NotificationVerifier(WEBHOOK_SECRET))->verify((string) json_encode($body), webhookHeaders());
})->throws(MalformedNotification::class, 'malformed');

it('leaves a missing order total null; verification treats that as a mismatch', function () {
    $body = json_decode(webhookBody('payment_captured.json'), true);
    unset($body['order']['totalCapturedAmount']);

    $n = (new NotificationVerifier(WEBHOOK_SECRET))->verify((string) json_encode($body), webhookHeaders());

    expect($n)->toBeInstanceOf(TransactionNotification::class)
        ->and($n->orderTotalCaptured)->toBeNull();
});

it('classifies refunds, authentication records and other notifications', function () {
    $verifier = new NotificationVerifier(WEBHOOK_SECRET);

    $refund = $verifier->verify(webhookBody('refund.json'), webhookHeaders());
    $auth = $verifier->verify(webhookBody('authentication.json'), webhookHeaders());
    $other = $verifier->verify(webhookBody('payment_alert_placeholder.json'), webhookHeaders());
    $noOrder = $verifier->verify('{"transaction":{"id":"1","type":"PAYMENT"}}', webhookHeaders());

    expect($refund)->toBeInstanceOf(TransactionNotification::class)
        ->and($refund->isRefund())->toBeTrue()
        ->and($refund->isMoneyIn())->toBeFalse()
        ->and($refund->transaction->targetTransactionId)->toBe('1')
        ->and($auth)->toBeInstanceOf(AuthenticationNotification::class)
        ->and($auth->kind())->toBe(NotificationKind::Authentication)
        ->and($auth->transaction->type)->toBe(TransactionType::Authentication)
        ->and($auth->orderId)->toBe('ISIMS-R-IUM-FN-2026-123-1')
        ->and($other)->toBeInstanceOf(GenericNotification::class)
        ->and($other->kind())->toBe(NotificationKind::Other)
        ->and($other->payload()->string('alert.type'))->toBe('RETRY_LIKELY')
        ->and($noOrder)->toBeInstanceOf(GenericNotification::class);
});

it('classifies void notifications', function () {
    $body = '{"order":{"id":"O-1","currency":"MVR","status":"CANCELLED"},"result":"SUCCESS","response":{"gatewayCode":"APPROVED"},"transaction":{"id":"v1","type":"VOID_PAYMENT","targetTransactionId":"1"}}';

    $n = (new NotificationVerifier(WEBHOOK_SECRET))->verify($body, webhookHeaders());

    expect($n)->toBeInstanceOf(TransactionNotification::class)->and($n->isVoid())->toBeTrue();
});

it('can build unverified notifications for tests and fakes only', function () {
    $n = NotificationVerifier::unverified(webhookBody('payment_captured.json'), ['X-Notification-ID' => 'TEST']);

    expect($n->id())->toBe('TEST');
});

it('does not expose the secret when dumped', function () {
    expect(print_r(new NotificationVerifier(WEBHOOK_SECRET), true))->not->toContain(WEBHOOK_SECRET);
});
