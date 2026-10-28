<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Enums\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Responses\GatewayStatus;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentOptions;
use IbnNajjaar\MIBGlobalPay\Core\Responses\SessionDetails;
use IbnNajjaar\MIBGlobalPay\Core\Responses\CheckoutSession;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayRecommendation;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Verification\ApprovalPolicy;
use IbnNajjaar\MIBGlobalPay\Core\Responses\SessionUpdateStatus;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

function fixturePayload(string $name, string $dir = 'v2'): Payload
{
    $path = __DIR__ . '/../../Fixtures/' . ($dir === '' ? '' : $dir . '/') . $name;

    return Payload::fromArray(json_decode((string) file_get_contents($path), true));
}

describe('OrderResponse', function () {
    it('parses a captured order with Money totals', function () {
        $order = OrderResponse::fromPayload(fixturePayload('order_captured.json'));

        expect($order->id)->toBe('ISIMS-R-IUM-FN-2026-123-1')
            ->and($order->reference)->toBe('(R)IUM/FN/2026/123')
            ->and($order->status)->toBe(OrderStatus::Captured)
            ->and($order->currency)->toBe(Currency::Mvr)
            ->and($order->amount->equals(Money::of('1250.50', Currency::Mvr)))->toBeTrue()
            ->and($order->totalCaptured->minor)->toBe(125050)
            ->and($order->totalCaptured->currency)->toBe(Currency::Mvr)
            ->and($order->isTotalCapturedReported())->toBeTrue()
            ->and($order->totalAuthorized->minor)->toBe(125050)
            ->and($order->totalRefunded->isZero())->toBeTrue()
            ->and($order->refundable()->minor)->toBe(125050)
            ->and($order->capturable()->isZero())->toBeTrue()
            ->and($order->creationTime?->format('Y-m-d'))->toBe('2026-10-20')
            ->and($order->transactions)->toHaveCount(2);
    });

    it('treats missing totals as zero and reports that totalCapturedAmount was missing', function () {
        $order = OrderResponse::fromPayload(fixturePayload('order_without_totals.json'));

        expect($order->totalCaptured->isZero())->toBeTrue()
            ->and($order->totalCaptured->currency)->toBe(Currency::Mvr)
            ->and($order->isTotalCapturedReported())->toBeFalse()
            ->and($order->transactions)->toBe([])
            ->and($order->status)->toBe(OrderStatus::Initiated);
    });

    it('parses the 1.x partially refunded fixture', function () {
        $order = OrderResponse::fromPayload(fixturePayload('order_partially_refunded.json', ''));

        expect($order->status)->toBe(OrderStatus::PartiallyRefunded)
            ->and($order->refundable()->toDecimal())->toBe('70.00')
            ->and($order->findTransaction('refund-1')?->type)->toBe(TransactionType::Refund)
            ->and($order->findTransaction('missing'))->toBeNull()
            ->and($order->latestOfType(TransactionType::Payment, TransactionType::Capture)?->transactionId)->toBe('1')
            ->and($order->transactionsOfType(TransactionType::Authentication))->toHaveCount(1);
    });

    it('never reports a negative refundable amount', function () {
        $order = OrderResponse::fromPayload(Payload::fromArray([
            'id' => 'O', 'currency' => 'MVR', 'totalCapturedAmount' => 10, 'totalRefundedAmount' => 12,
        ]));

        expect($order->refundable()->isZero())->toBeTrue();
    });

    it('rejects an order without a supported currency', function (array $data) {
        OrderResponse::fromPayload(Payload::fromArray($data));
    })->with([
        'missing' => [['id' => 'O', 'totalCapturedAmount' => 10]],
        'unsupported' => [['id' => 'O', 'currency' => 'XYZ', 'totalCapturedAmount' => 10]],
    ])->throws(MalformedPayload::class, 'currency');

    it('rejects totals with too many decimals', function () {
        OrderResponse::fromPayload(Payload::fromArray(['id' => 'O', 'currency' => 'MVR', 'totalCapturedAmount' => 10.005]));
    })->throws(MalformedPayload::class);

    it('keeps the raw status for unrecognized values', function () {
        $order = OrderResponse::fromPayload(Payload::fromArray(['id' => 'O', 'currency' => 'MVR', 'status' => 'NEW_STATUS']));

        expect($order->status)->toBe(OrderStatus::Unrecognized)->and($order->rawStatus())->toBe('NEW_STATUS');
    });
});

describe('TransactionResponse', function () {
    it('parses a payment transaction inside an order', function () {
        $payment = OrderResponse::fromPayload(fixturePayload('order_captured.json'))->findTransaction('1');

        expect($payment?->type)->toBe(TransactionType::Payment)
            ->and($payment?->result)->toBe(Result::Success)
            ->and($payment?->gatewayCode)->toBe(GatewayCode::Approved)
            ->and($payment?->gatewayRecommendation)->toBe(GatewayRecommendation::Proceed)
            ->and($payment?->amount?->toDecimal())->toBe('1250.50')
            ->and($payment?->receipt)->toBe('629312345678')
            ->and($payment?->authorizationCode)->toBe('654321')
            ->and($payment?->acquirerMessage)->toBe('Approved')
            ->and($payment?->orderStatus)->toBe(OrderStatus::Captured)
            ->and($payment?->isApproved(new ApprovalPolicy()))->toBeTrue();
    });

    it('treats a 3DS authentication transaction without a response as not approved', function () {
        $auth = OrderResponse::fromPayload(fixturePayload('order_captured.json'))->findTransaction('auth-1');

        expect($auth?->gatewayCode)->toBe(GatewayCode::Unrecognized)
            ->and($auth?->isApproved(new ApprovalPolicy()))->toBeFalse();
    });

    it('parses a capture response', function () {
        $capture = TransactionResponse::fromPayload(fixturePayload('transaction_capture_success.json'));

        expect($capture->type)->toBe(TransactionType::Capture)
            ->and($capture->isSuccessful())->toBeTrue()
            ->and($capture->reference)->toBe('SHIP-1')
            ->and($capture->orderId)->toBe('ORDER-AUTH-1')
            ->and($capture->timeOfRecord?->format(DATE_ATOM))->toBe('2026-10-21T12:00:00+00:00');
    });

    it('parses a declined void as a failure, not an exception', function () {
        $void = TransactionResponse::fromPayload(fixturePayload('transaction_void_declined.json'));

        expect($void->isFailure())->toBeTrue()
            ->and($void->type)->toBe(TransactionType::VoidPayment)
            ->and($void->gatewayCode)->toBe(GatewayCode::Declined)
            ->and($void->targetTransactionId)->toBe('1')
            ->and($void->amount)->toBeNull()
            ->and($void->isApproved(new ApprovalPolicy()))->toBeFalse();
    });

    it('parses the 1.x refund fixtures', function () {
        $ok = TransactionResponse::fromPayload(fixturePayload('transaction_refund_success.json', ''));
        $declined = TransactionResponse::fromPayload(fixturePayload('transaction_refund_declined.json', ''));

        expect($ok->type)->toBe(TransactionType::Refund)
            ->and($ok->isSuccessful())->toBeTrue()
            ->and($declined->isFailure())->toBeTrue();
    });

    it('keeps raw strings for values it does not recognize', function () {
        $t = TransactionResponse::fromPayload(Payload::fromArray([
            'result' => 'SOMETHING', 'response' => ['gatewayCode' => 'APPROVED_NEW'], 'transaction' => ['type' => 'NEW_TYPE'],
        ]));

        expect($t->result)->toBe(Result::Unrecognized)
            ->and($t->rawResult())->toBe('SOMETHING')
            ->and($t->rawGatewayCode())->toBe('APPROVED_NEW')
            ->and($t->rawType())->toBe('NEW_TYPE')
            ->and($t->isApproved(new ApprovalPolicy(acceptApprovedAuto: true)))->toBeFalse();
    });

    it('honours the APPROVED_AUTO opt-in', function () {
        $t = TransactionResponse::fromPayload(Payload::fromArray(['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED_AUTO']]));

        expect($t->isApproved(new ApprovalPolicy()))->toBeFalse()
            ->and($t->isApproved(new ApprovalPolicy(acceptApprovedAuto: true)))->toBeTrue();
    });
});

describe('session and gateway responses', function () {
    it('parses a checkout session', function () {
        $session = CheckoutSession::fromPayload(fixturePayload('checkout_session.json'));

        expect($session->sessionId)->toBe('SESSION0002899787259G30902270H6')
            ->and($session->successIndicator)->toBe('f8e1b1e3c2d54a7b')
            ->and($session->sessionVersion)->toBe('e3f144ce02')
            ->and($session->updateStatus)->toBe(SessionUpdateStatus::Success)
            ->and($session->result)->toBe(Result::Success);
    });

    it('rejects a checkout session without an ID or success indicator', function (array $data) {
        CheckoutSession::fromPayload(Payload::fromArray($data));
    })->with([
        'no session' => [['successIndicator' => 'abc']],
        'no indicator' => [['session' => ['id' => 'SESSION1']]],
    ])->throws(MalformedPayload::class);

    it('parses session details', function () {
        $details = SessionDetails::fromPayload(fixturePayload('session_details.json'));

        expect($details->isUsable())->toBeTrue()
            ->and($details->orderId)->toBe('ISIMS-R-IUM-FN-2026-123-1')
            ->and($details->orderAmount?->toDecimal())->toBe('1250.50')
            ->and($details->orderCurrency)->toBe(Currency::Mvr)
            ->and($details->interactionOperation)->toBe('PURCHASE')
            ->and($details->version)->toBe('e3f144ce02');
    });

    it('parses gateway status', function () {
        expect(GatewayStatus::fromPayload(fixturePayload('check_gateway.json'))->isOperating())->toBeTrue()
            ->and(GatewayStatus::fromPayload(Payload::fromArray(['status' => 'DEGRADED']))->isOperating())->toBeFalse()
            ->and(GatewayStatus::fromPayload(Payload::fromArray([]))->isOperating())->toBeFalse();
    });

    it('parses payment options', function () {
        $options = PaymentOptions::fromPayload(fixturePayload('payment_options.json'));

        expect($options->paymentTypes)->toBe(['card'])
            ->and($options->acceptsCards())->toBeTrue()
            ->and($options->cardTypes)->toBe(['CREDIT', 'DEBIT'])
            ->and($options->cardCurrencies)->toBe(['MVR', 'USD'])
            ->and($options->cardsAcceptCurrency(Currency::Usd))->toBeTrue()
            ->and($options->cardsAcceptCurrency(Currency::Eur))->toBeFalse()
            ->and(PaymentOptions::fromPayload(Payload::fromArray([]))->paymentTypes)->toBe([]);
    });
});
