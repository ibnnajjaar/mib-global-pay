<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Address;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Contact;
use IbnNajjaar\MIBGlobalPay\Core\Requests\LineItem;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Agreement;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Pay;
use IbnNajjaar\MIBGlobalPay\Core\Requests\StoredOnFile;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentLink;
use IbnNajjaar\MIBGlobalPay\Core\Requests\AgreementType;
use IbnNajjaar\MIBGlobalPay\Core\Requests\SameAsBilling;
use IbnNajjaar\MIBGlobalPay\Core\Requests\StoredPayment;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Capture;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Referral;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OperationResult;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Authorize;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Token\SaveToken;
use IbnNajjaar\MIBGlobalPay\Core\Requests\AmountVariability;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Token\DeleteToken;
use IbnNajjaar\MIBGlobalPay\Core\Requests\InteractionOperation;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\VerifyAccount;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Token\RetrieveToken;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Checkout\InitiateCheckout;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Agreement\RetrieveAgreement;
use IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentLink\DeletePaymentLink;
use IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentPlan\PlanOffersInquiry;
use IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentLink\InitiatePaymentLink;

function featureConfig(): GatewayConfig
{
    return GatewayConfig::fromArray(['merchant_id' => 'TEST123', 'api_password' => 'x', 'order_prefix' => 'ISIMS']);
}

function featureMvr(string $amount = '100.00'): Money
{
    return Money::of($amount, Currency::Mvr);
}

function featureCheckout(): CheckoutRequest
{
    return CheckoutRequest::create('O-1', featureMvr(), 'Shop', 'https://x.mv/r')->withoutWebhook();
}

it('sends customer, billing and shipping data', function () {
    $body = featureCheckout()
        ->withCustomer(new Contact(email: 'payer@example.mv', firstName: 'Aisha'), taxRegistrationId: 'TIN1')
        ->withBillingAddress(new Address(street: 'Majeedhee Magu', city: 'Male', country: 'MDV'))
        ->withShipping(new Address(city: 'Addu'), new Contact(lastName: 'Ali'), SameAsBilling::Different)
        ->toBody(featureConfig());

    expect($body['customer'])->toBe(['email' => 'payer@example.mv', 'firstName' => 'Aisha', 'taxRegistrationId' => 'TIN1'])
        ->and($body['billing']['address'])->toBe(['street' => 'Majeedhee Magu', 'city' => 'Male', 'country' => 'MDV'])
        ->and($body['shipping'])->toBe(['address' => ['city' => 'Addu', 'sameAsBilling' => 'DIFFERENT'], 'contact' => ['lastName' => 'Ali']]);
});

it('sends order data', function () {
    $body = featureCheckout()
        ->withLineItems(new LineItem('Tuition', 2, featureMvr('50.00'), sku: 'T1'))
        ->withStatementDescriptor('IUM FEES', '3001234', new Address(city: 'Male'))
        ->withOrderDetails(customerReference: 'C1', invoiceNumber: 'INV1', taxAmount: featureMvr('6.00'))
        ->withAirlineData(['bookingReference' => 'ABC'])
        ->withCruiseData(['shipName' => 'Sea'])
        ->toBody(featureConfig());

    expect($body['order']['item'])->toBe([['name' => 'Tuition', 'quantity' => 2, 'unitPrice' => '50.00', 'sku' => 'T1']])
        ->and($body['order']['statementDescriptor'])->toBe(['name' => 'IUM FEES', 'phone' => '3001234', 'address' => ['city' => 'Male']])
        ->and($body['order']['customerReference'])->toBe('C1')
        ->and($body['order']['invoiceNumber'])->toBe('INV1')
        ->and($body['order']['taxAmount'])->toBe('6.00')
        ->and($body['airline'])->toBe(['bookingReference' => 'ABC'])
        ->and($body['cruise'])->toBe(['shipName' => 'Sea']);
});

it('sends payment plan constraints using the reference field names', function () {
    $body = featureCheckout()->withPaymentPlanConstraints(['PLAN1'], 12, 3)->toBody(featureConfig());

    expect($body['constraints'])->toBe(['paymentPlans' => ['supported' => ['PLAN1'], 'numberOfPayments' => 12, 'deferralPeriod' => 3]]);
});

it('sends Credential on File fields', function () {
    $body = featureCheckout()
        ->withSaveCard()
        ->withTokens('9855904494704968')
        ->withStoredOnFile(StoredOnFile::ToBeStored)
        ->withAgreement(new Agreement('AG-1', AgreementType::Recurring, AmountVariability::Fixed, expiryDate: new DateTimeImmutable('2029-08-25'), minimumDaysBetweenPayments: 29))
        ->toBody(featureConfig());

    expect($body['interaction']['saveCardForCredentialOnFile'])->toBe('PAYER_INITIATED_PAYMENTS')
        ->and($body['interaction']['tokens'])->toBe(['9855904494704968'])
        ->and($body['sourceOfFunds'])->toBe(['provided' => ['card' => ['storedOnFile' => 'TO_BE_STORED']]])
        ->and($body['agreement'])->toBe(['id' => 'AG-1', 'type' => 'RECURRING', 'amountVariability' => 'FIXED', 'expiryDate' => '2029-08-25', 'minimumDaysBetweenPayments' => 29]);
});

it('builds a payment link request', function () {
    $body = featureCheckout()
        ->withDescription('Fees')
        ->withMerchantDetails(url: 'https://x.mv')
        ->withPaymentLink(new DateTimeImmutable('2027-01-02 03:04:05', new DateTimeZone('UTC')), 3, 'https://x.mv/error');
    $operation = new InitiatePaymentLink($body);

    expect($operation->body(featureConfig()))->toMatchArray([
        'checkoutMode' => 'PAYMENT_LINK',
        'paymentLink' => ['expiryDateTime' => '2027-01-02T03:04:05.000Z', 'numberOfAllowedAttempts' => 3, 'errorUrl' => 'https://x.mv/error'],
    ])->and($operation->path())->toBe('session');
});

it('always sends the payment link attempt count, 25 when none is given', function (?int $given, int $sent) {
    $body = featureCheckout()
        ->withDescription('Fees')
        ->withMerchantDetails(url: 'https://x.mv')
        ->withPaymentLink(allowedAttempts: $given);

    expect((new InitiatePaymentLink($body))->body(featureConfig())['paymentLink']['numberOfAllowedAttempts'])->toBe($sent);
})->with([
    'none given' => [null, CheckoutRequest::DEFAULT_PAYMENT_LINK_ATTEMPTS],
    'one' => [1, 1],
    'seven' => [7, 7],
]);

it('parses a payment link response', function () {
    $link = PaymentLink::fromPayload(Payload::fromArray([
        'result' => 'SUCCESS',
        'successIndicator' => 'abc',
        'paymentLink' => ['id' => 'PAYLINK1', 'url' => 'https://x/pbl/PAYLINK1', 'numberOfAllowedAttempts' => 3, 'expiryDateTime' => '2027-01-02T03:04:05.000Z'],
    ]));

    expect($link->id)->toBe('PAYLINK1')->and($link->url)->toBe('https://x/pbl/PAYLINK1')
        ->and($link->allowedAttempts)->toBe(3)->and($link->successIndicator)->toBe('abc')
        ->and($link->expiresAt?->format('Y'))->toBe('2027');
});

it('refuses an incomplete or mismatched payment link', function (Closure $build, string $message) {
    expect($build)->toThrow(InvalidRequest::class, $message);
})->with([
    'no description' => [fn () => featureCheckout()->withMerchantDetails(url: 'https://x.mv')->withPaymentLink()->toBody(featureConfig()), 'order description'],
    'no merchant URL' => [fn () => featureCheckout()->withDescription('F')->withPaymentLink()->toBody(featureConfig()), 'merchant URL'],
    'wrong operation' => [fn () => featureCheckout()->withDescription('F')->withMerchantDetails(url: 'https://x.mv')->withOperation(InteractionOperation::Verify)->withPaymentLink()->toBody(featureConfig()), 'AUTHORIZE or PURCHASE'],
    'zero attempts' => [fn () => featureCheckout()->withPaymentLink(allowedAttempts: 0), 'attempts'],
    'link sent as a checkout' => [fn () => (new InitiateCheckout(featureCheckout()->withPaymentLink()))->body(featureConfig()), 'paymentLinks()'],
    'checkout sent as a link' => [fn () => (new InitiatePaymentLink(featureCheckout()))->body(featureConfig()), 'withPaymentLink()'],
]);

it('charges a token, payer-initiated or merchant-initiated', function () {
    $payer = (new Pay('O-1', 'T-1', featureMvr(), StoredPayment::payerInitiated('TOKEN1')))->body(featureConfig());
    $merchant = (new Authorize('O-1', 'T-1', featureMvr(), StoredPayment::merchantInitiated('TOKEN1', 'AG-1')))->body(featureConfig());

    expect($payer['apiOperation'])->toBe('PAY')
        ->and($payer)->not->toHaveKey('transaction')
        ->and($payer['order'])->toBe(['amount' => '100.00', 'currency' => 'MVR'])
        ->and($payer['sourceOfFunds'])->toBe(['token' => 'TOKEN1', 'provided' => ['card' => ['storedOnFile' => 'STORED']]])
        ->and($payer)->not->toHaveKey('agreement')
        ->and($merchant['apiOperation'])->toBe('AUTHORIZE')
        ->and($merchant['agreement'])->toBe(['id' => 'AG-1'])
        ->and($merchant['transaction'])->toBe(['source' => 'MERCHANT']);
});

it('verifies an account and resubmits a referral', function () {
    $verify = (new VerifyAccount('O-1', 'T-1', Currency::Mvr, StoredPayment::payerInitiated('TOKEN1')))->body(featureConfig());
    $referral = new Referral('O-1', 'T-2', 'AB1234');

    expect($verify['apiOperation'])->toBe('VERIFY')
        ->and($verify['order'])->toBe(['currency' => 'MVR'])
        ->and($referral->body(featureConfig()))->toBe(['apiOperation' => 'REFERRAL', 'transaction' => ['authorizationCode' => 'AB1234']])
        ->and($referral->path())->toBe('order/O-1/transaction/T-2');
});

it('sends updated order data with a capture', function () {
    $body = (new Capture('O-1', 'T-1', featureMvr(), extraFields: ['shipping' => ['address' => ['city' => 'Addu']]]))->body(featureConfig());

    expect($body['shipping'])->toBe(['address' => ['city' => 'Addu']])
        ->and($body['transaction'])->toBe(['amount' => '100.00', 'currency' => 'MVR']);
});

it('uses the documented path and method', function (object $operation, HttpMethod $method, string $path) {
    expect($operation->method())->toBe($method)->and($operation->path())->toBe($path);
})->with([
    'retrieve token' => [fn () => new RetrieveToken('TOKEN1'), HttpMethod::Get, 'token/TOKEN1'],
    'delete token' => [fn () => new DeleteToken('TOKEN1'), HttpMethod::Delete, 'token/TOKEN1'],
    'save token' => [fn () => new SaveToken('SESSION1', 'TOKEN1'), HttpMethod::Put, 'token/TOKEN1'],
    'save generated token' => [fn () => new SaveToken('SESSION1'), HttpMethod::Post, 'token'],
    'retrieve agreement' => [fn () => new RetrieveAgreement('AG-1'), HttpMethod::Get, 'agreement/AG-1'],
    'delete link' => [fn () => new DeletePaymentLink('PAYLINK1'), HttpMethod::Delete, 'link/PAYLINK1'],
    'plan offers' => [fn () => new PlanOffersInquiry('PLAN1', featureMvr(), 12, 'TOKEN1'), HttpMethod::Put, 'paymentPlan/PLAN1'],
]);

it('sends a Plan Offers Inquiry', function () {
    expect((new PlanOffersInquiry('PLAN1', featureMvr(), 12, 'TOKEN1'))->body(featureConfig()))->toBe([
        'apiOperation' => 'PLAN_OFFERS_INQUIRY',
        'order' => ['amount' => '100.00', 'currency' => 'MVR'],
        'paymentPlan' => ['numberOfPayments' => '12'],
        'sourceOfFunds' => ['token' => 'TOKEN1'],
    ]);
});

it('treats an empty delete answer as success', function () {
    expect(OperationResult::fromPayload(Payload::fromArray([]))->result->value)->toBe('SUCCESS');
});

it('finds the saved token on an order', function (array $extra) {
    $order = OrderResponse::fromPayload(Payload::fromArray(['id' => 'O-1', 'currency' => 'MVR', 'status' => 'CAPTURED'] + $extra));

    expect($order->token())->toBe('TOKEN1');
})->with([
    'on the order' => [['sourceOfFunds' => ['token' => 'TOKEN1']]],
    'on a transaction' => [['transaction' => [['sourceOfFunds' => ['token' => 'TOKEN1']]]]],
]);

it('rejects bad data', function (Closure $build) {
    expect($build)->toThrow(InvalidRequest::class);
})->with([
    'bad email' => [fn () => new Contact(email: 'nope')],
    'empty item name' => [fn () => new LineItem('', 1, featureMvr())],
    'zero quantity' => [fn () => new LineItem('A', 0, featureMvr())],
    'recurring without variability' => [fn () => new Agreement('A')],
    'bad days' => [fn () => new Agreement('A', AgreementType::Other, minimumDaysBetweenPayments: 0)],
    'bad token' => [fn () => StoredPayment::payerInitiated('not valid!')],
    'bad token list' => [fn () => featureCheckout()->withTokens('not valid!')],
    'bad plan payments' => [fn () => featureCheckout()->withPaymentPlanConstraints(numberOfPayments: 100)],
    'bad deferral' => [fn () => featureCheckout()->withPaymentPlanConstraints(deferralPeriod: 100)],
    'bad referral code' => [fn () => new Referral('O', 'T', 'too-long-code')],
    'bad plan offer count' => [fn () => new PlanOffersInquiry('P', featureMvr(), 0, 'T')],
    'pay with zero' => [fn () => (new Pay('O', 'T', Money::of('0', Currency::Mvr), StoredPayment::payerInitiated('T1')))->body(featureConfig())],
    'verify in a blocked currency' => [fn () => (new VerifyAccount('O', 'T', Currency::Usd, StoredPayment::payerInitiated('T1')))->body(featureConfig())],
]);
