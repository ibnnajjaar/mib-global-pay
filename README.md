# MIB Global Pay

[![Tests](https://github.com/ibnnajjaar/mib-global-pay/workflows/Tests/badge.svg)](https://github.com/ibnnajjaar/mib-global-pay/actions)
![Code Coverage Badge](./.github/coverage.svg)

PHP client for **MIB (Maldives Islamic Bank) Global Pay**, the Mastercard Payment Gateway Services
(MPGS) **Hosted Checkout**, with an optional **Laravel 13** layer.

> **Version 2 is in development on `dev-main`.** It requires PHP 8.4+. Upgrading from 1.1: see
> [UPGRADE.md](./UPGRADE.md). The design and every decision are in [DESIGN-v2.md](./DESIGN-v2.md).
> The 1.x classes are still on `dev-main` during the transition and will be removed before 2.0.0.

The rule this package is built around: **a payment is paid only when the gateway says so for the
exact amount you expected.** The return URL and the webhook are triggers; `verifyCapture()` is the
proof.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Core usage (any PHP app)](#core-usage-any-php-app)
- [Laravel](#laravel)
- [Money and currencies](#money-and-currencies)
- [Errors](#errors)
- [Security notes](#security-notes)
- [Development](#development)

## Requirements

- PHP 8.4 or 8.5
- `guzzlehttp/guzzle` ^7.9 (default transport) or any PSR-18 client
- Laravel 13 for the optional Laravel layer

## Installation

```bash
composer require ibnnajjaar/mib-global-pay:dev-main
```

## Core usage (any PHP app)

### Configure

```php
use IbnNajjaar\MIBGlobalPay\Core\Config\Environment;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\MibGateway;

$gateway = new MibGateway(new GatewayConfig(
    merchantId: 'TEST123',
    apiPassword: getenv('MIB_API_PASSWORD'),
    orderPrefix: 'SHOP',                 // required: prefixes every gateway order ID
    environment: Environment::Test,      // test-mib.mtf.gateway.mastercard.com
    // host: 'gateway.example.com',      // required for Environment::Production
));
```

Defaults: API version 100, 30 s timeout, currencies `[MVR]`, HTTPS-only URLs. Pass a PSR-3 logger
(bodies are logged with secrets redacted) and, if you want, an opt-in `RetryPolicy` for idempotent
operations (`RetryPolicy::gatewayRecommended()` resends once after 60 s, as MIB advises).

### Start a checkout

One payable (your invoice/payment) can have several **attempts**; each attempt uses a **new**
gateway order ID. Never reuse an order ID.

```php
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Support\GatewayOrderId;

$orderId = (new GatewayOrderId())->forAttempt('SHOP', '(R)INV/2026/123', 1); // SHOP-R-INV-2026-123-1

$session = $gateway->initiateCheckout(
    CheckoutRequest::create($orderId, Money::of('1250.50', Currency::Mvr), 'My Shop', 'https://shop.example/return')
        ->withReference('(R)INV/2026/123')              // your own reference, any characters
        ->withDescription('Order 123')
        ->withNotificationUrl('https://shop.example/mib/webhook')
        ->withTimeout(1800),
);

// Persist before redirecting: $session->sessionId, $session->successIndicator, $session->sessionVersion
```

Redirect the payer with Checkout.js (`https://{host}/static/checkout/checkout.min.js`):
`Checkout.configure({ session: { id: sessionId } }); Checkout.showPaymentPage();`

### Verify

```php
$result = $gateway->verifyCapture($orderId, Money::of('1250.50', Currency::Mvr));

match ($result->status) {
    VerificationStatus::Captured => /* paid, exactly */,
    VerificationStatus::AmountMismatch, VerificationStatus::CurrencyMismatch => /* money moved, but wrong: never auto-pay */,
    VerificationStatus::Pending => /* ask again later */,
    VerificationStatus::NotCaptured, VerificationStatus::NotFound => /* not paid */,
    VerificationStatus::Reversed => /* refunded or voided */,
};
```

On the return URL, `HostedCheckoutReturn::fromQuery($_GET)->matchesSuccessIndicator($stored)` is a
timing-safe hint for the payer-facing page; still call `verifyCapture()`.

### Webhooks

```php
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;

$notification = (new NotificationVerifier(getenv('MIB_WEBHOOK_SECRET')))   // throws if the secret is empty
    ->verify(file_get_contents('php://input'), getallheaders());           // SecretMismatch / MalformedNotification
```

Answer 2xx within 2 seconds, dedupe on `$notification->id()` (the gateway redelivers up to 20 times
over 3 days; see `NotificationStore`), and confirm with `verifyCapture()`.

### Other operations

```php
$gateway->retrieveOrder($orderId);                    // OrderResponse: Money totals, transactions
$gateway->retrieveTransaction($orderId, $txnId);
$gateway->capture($orderId, TransactionId::generate('capture'), $amount);
$gateway->refund($orderId, TransactionId::generate('refund'), $amount);
$gateway->void($orderId, TransactionId::generate('void'), $targetTxnId);
$gateway->updateAuthorization($orderId, TransactionId::generate('update'));
$gateway->retrieveSession($sessionId);
$gateway->checkGateway()->isOperating();
$gateway->paymentOptionsInquiry();
$gateway->send(new SomeOperation(...));               // any Core\Contracts\Operation
```

Persist each new transaction ID before sending: resending the identical request after a timeout is
safe. A declined operation is a `TransactionResponse` with `Result::Failure`, not an exception.
`(new ReversalHelper($gateway))->reverse(...)` voids, falling back to a refund.

## Laravel

The service provider is auto-discovered. Configure `.env`:

```dotenv
MIB_GATEWAY_URL=test-mib.mtf.gateway.mastercard.com
MIB_MERCHANT_ID=TEST123
MIB_API_PASSWORD=...
MIB_ORDER_PREFIX=SHOP
MIB_WEBHOOK_SECRET=...            # from Merchant Administration (Webhook Notifications)
```

All keys: `config/mib-global-pay.php` (`php artisan vendor:publish --tag=mib-global-pay-config`).

1. **Dedupe table:** `php artisan vendor:publish --tag=mib-global-pay-migrations && php artisan migrate`
   (or `MIB_NOTIFICATION_STORE=cache` with a shared cache).
2. **Implement and bind** `Core\Attempts\PayableResolver`, with your models implementing `Payable`
   and `Attempt` (DESIGN-v2.md §6.7). The webhook answers 503 until the resolver is bound.
3. **Webhook route:** auto-registered at `POST /mib/webhook` (`MIB_WEBHOOK_PATH`, `MIB_WEBHOOK_DOMAIN`).
   To register it yourself, set `MIB_WEBHOOK_REGISTER_ROUTE=false` and:
   ```php
   Route::domain('pay.example.com')->group(function () {
       Route::mibGlobalPayWebhook('payment-gateways/mib/webhook');
   });
   ```
   It answers 200 (accepted or duplicate), 401 (bad secret), 400 (malformed), 503 (not configured).
   Processing runs on the queue (`MIB_WEBHOOK_CONNECTION`, `MIB_WEBHOOK_QUEUE`).
4. **Listen to the events** (`IbnNajjaar\MIBGlobalPay\Laravel\Events`). They are dispatched inside
   `Payable::lock()`; the package never changes your models:
   `PaymentCaptured`, `PaymentCapturedForUnpayableOrder`, `CaptureMismatch`, `PaymentPending`,
   `PaymentFailed`, `PaymentReversed`, `UnknownOrderNotification`, `RefundProcessed`, `VoidProcessed`,
   `NotificationReceived`.
5. **Checkout:**
   ```php
   $request = MibGlobalPay::newCheckoutRequest(MibGlobalPay::attemptOrderId($ref, $n), $amount, 'My Shop', $returnUrl)
       ->withReference($ref);
   $session = MibGlobalPay::initiateCheckout($request);
   ```
   ```blade
   <x-mib-global-pay::checkout :session="$session->sessionId" />
   ```
   Before a retry, `MibGlobalPay::attemptGuard()->canStartNewAttempt($payable)` (inside the lock).
6. **Return URL:** `app(PaymentOutcome::class)->resolveFromReturn($orderId, $request->query())`.
7. **Schedule** `mib:reconcile` (every few minutes) and `mib:prune-notifications` (daily).

Double-capture auto-reversal is available but off: `MIB_AUTO_REVERSE_DOUBLE_CAPTURE=true`.

## Money and currencies

`Money` stores minor units and never uses floats: `Money::of('100.50', Currency::Mvr)`,
`Money::ofMinor(10050, Currency::Mvr)`, `Money::ofRounded('1.005', Currency::Mvr, RoundingMode::HalfEven)`.
Too many decimals throw `InvalidAmount`; mixing currencies throws `CurrencyMismatch`.
`Currency` is a curated ISO 4217 subset (AED, EUR, GBP, INR, JPY, KWD, LKR, MVR, SGD, USD); the
configured allow-list (default `MVR`) decides what a merchant may use.

## Errors

Every exception implements `Core\Exceptions\MibGlobalPayException`:
`InvalidRequest` / `InvalidAmount` / `UnsupportedCurrency` (before sending), `GatewayException`
(HTTP 4xx/5xx, with `cause()`, `explanation()`, `field()`, `supportCode()`), `TransportException`
(no response), `MalformedPayload`, `SecretMismatch`, `MalformedNotification`, `ConfigurationException`.

## Security notes

- Keep the API password and webhook secret in the environment; they are redacted from logs and debug output.
- The gateway sends the webhook secret only to HTTPS URLs; all URLs you send must be HTTPS.
- Never mark anything paid without `verifyCapture()`; never reuse a gateway order ID; keep the
  payable (amount and invoices) frozen while a checkout session is live.

## Development

```bash
composer test       # Pest 4 (PHPUnit 12), with Orchestra Testbench for the Laravel layer
composer analyse    # PHPStan: core and Laravel layer at level max (Larastan)
composer lint       # Pint
```

Docker: `docker compose run --rm test`.

## License

MIT. See the CHANGELOG for release notes: [CHANGELOG.md](./CHANGELOG.md).
