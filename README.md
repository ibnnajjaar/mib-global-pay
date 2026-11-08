# MIB Global Pay

[![Tests](https://github.com/ibnnajjaar/mib-global-pay/workflows/Tests/badge.svg)](https://github.com/ibnnajjaar/mib-global-pay/actions)
![Code Coverage Badge](https://raw.githubusercontent.com/ibnnajjaar/mib-global-pay/main/.github/coverage.svg)

PHP client for **MIB (Maldives Islamic Bank) Global Pay**, the Mastercard Payment Gateway Services
(MPGS) **Hosted Checkout**, with an optional **Laravel 12 and 13** layer.

> **Version 2 is a beta.** See [Beta status](#beta-status). It requires
> PHP 8.4+. Upgrading from 1.1: see [UPGRADE.md](./UPGRADE.md). The design and every decision are in
> [DESIGN-v2.md](https://github.com/ibnnajjaar/mib-global-pay/blob/main/DESIGN-v2.md).

The rule this package is built around: **a payment is paid only when the gateway says so for the
exact amount you expected.** The return URL and the webhook are triggers; `verifyCapture()` is the
proof.

## Beta status

The current tag is `v2.0.0-beta.1`. It is not a finished 2.0.0. No authenticated sandbox flow
has been run against the gateway, and MIB has not answered our open questions. Test your own flow
against the test gateway before you trust it with live payments.

This gateway behaviour is implemented but unverified:

- **Unknown orders:** the exact response for an order the gateway does not have. The package maps
  it to `NotFound`, which can lead to `PaymentFailed` and to an attempt being superseded. The rule
  is under [Verify](#verify), and the format it assumes comes from the docs, which do not describe
  this case.
- **Webhook body:** the format of transaction notifications and of Payment Alerts. Notifications
  are classified by body shape until MIB confirms this.
- **Webhook headers:** whether `X-Notification-Attempt` starts at 1, whether `X-Notification-ID` is
  always present, and which casing is sent.
- **Identifiers:** which characters `order.id` and `order.reference` accept.
- **Production host:** `Environment::Production` has no preset, so you must set the host yourself.
- **New in this beta:** Payment Links, tokens, agreements, server-side payments, Plan Offers Inquiry, `order.item` (which the
  Hosted Checkout guide describes but the Initiate Checkout reference does not list) and where Retrieve Order puts a saved token.
- **Merchant profile:** whether `APPROVED_AUTO` and `PARTIALLY_APPROVED` occur, whether Update Session
  works on a Hosted Checkout session (it ships as experimental), how long a session lasts, and which
  operations, currencies and features are enabled.

The full list is at the top of [DESIGN-v2.md](https://github.com/ibnnajjaar/mib-global-pay/blob/main/DESIGN-v2.md),
with the questions for MIB in its §12.

## Contents

- [Beta status](#beta-status)
- [Requirements](#requirements)
- [Installation](#installation)
- [Core usage (any PHP app)](#core-usage-any-php-app)
- [Laravel](#laravel)
- [Testing your integration](#testing-your-integration)
- [Money and currencies](#money-and-currencies)
- [Errors](#errors)
- [Security notes](#security-notes)
- [Development](#development)

## Requirements

- PHP 8.4 or 8.5
- `guzzlehttp/guzzle` ^7.9 (default transport) or any PSR-18 client
- Laravel 12 or 13 for the optional Laravel layer

### Supported Laravel versions

| Host | Does it install? | What you get |
|---|---|---|
| Laravel 12 or 13 | Yes | The core and the Laravel layer, auto-discovered |
| Any other Laravel (11 or older, 14 or newer) | No, Composer refuses | Nothing is installed and the app is left as it was |
| Any PHP app without `laravel/framework`, including one using `illuminate/*` packages of any version on their own | Yes | The core only; no Illuminate package is pulled in |

The refusal comes from a `conflict` on `laravel/framework` in this package's `composer.json`:

```text
ibnnajjaar/mib-global-pay 2.0.0-beta.1 conflicts with laravel/framework <12.0 || >=14.0.
```

Laravel 12's security support ends on 2027-02-24, so a later major version of this package can drop it.

The core has no framework dependency, but it cannot be installed on its own into an unsupported
Laravel app, because Composer installs a package as a whole. To use the core from such an app, either
upgrade the app to Laravel 12 or 13, or call the gateway from a separate non-Laravel project.

## Installation

```bash
composer require ibnnajjaar/mib-global-pay:^2.0@beta --with-all-dependencies
```

Use this same command for a Laravel 12 or 13 app and for a PHP app without a framework.

- `@beta` is needed because Composer installs only stable versions by default. Plain `^2.0` does not
  find a beta.
- `--with-all-dependencies` is needed on Laravel 13. A fresh Laravel 13 app ships Guzzle 8 and this
  package needs Guzzle 7, so Composer has to move Guzzle to 7.x. Without the flag it stops and reports
  that Guzzle is fixed to 8.x. A fresh Laravel 12 app already has Guzzle 7, so the flag changes nothing
  there.

## Core usage (any PHP app)

On Laravel, the core is available on Laravel 12 and 13 only; see [Supported Laravel versions](#supported-laravel-versions).

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

Defaults: API version 100 (67 is the lowest accepted), 30 s timeout, currencies `[MVR]`, HTTPS-only URLs. Pass a PSR-3 logger
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

`withDisplayControl()` sets how the page shows the billing address, customer email, shipping details
and payment terms. A `READ_ONLY` field needs its data in the request, which `withExtraFields()` can supply.

The request builder covers the rest of the Hosted Checkout features:

| Feature | Method |
|---|---|
| Operation | `withOperation(InteractionOperation::Authorize)`, `CheckoutRequest::forVerification()` (the only one that may send a zero amount) |
| Payer data | `withCustomer()`, `withBillingAddress()`, `withShipping()` |
| What the page shows | `withDisplayControl()`, `withCardSecurityCode()`, `withLocale()`, `withStyle()`, `withMerchantDetails()` |
| Order data | `withLineItems()`, `withStatementDescriptor()`, `withOrderDetails()`, `withAirlineData()`, `withCruiseData()` |
| After payment | `withCancelUrl()`, `withTimeout()`, `withRetryRedirect()` |
| Security and source | `withThreeDSecure()`, `withTransactionSource()` |
| Payment plans | `withPaymentPlanConstraints()` |
| Saved cards | `withSaveCard()`, `withTokens()`, `withAgreement()`, `withStoredOnFile()` |
| Anything else | `withExtraFields()` |

### Show the payment page

`CheckoutJs` writes all the JavaScript. Print a whole page, or `markup()` and `scripts()` inside your own:

```php
use IbnNajjaar\MIBGlobalPay\Core\Checkout\CheckoutDisplay;
use IbnNajjaar\MIBGlobalPay\Core\Checkout\CheckoutJs;

echo CheckoutJs::for($gateway->config(), $session->sessionId)->page();
echo CheckoutJs::for($gateway->config(), $session->sessionId, CheckoutDisplay::Embedded)->page();
echo CheckoutJs::for($gateway->config(), $session->sessionId, CheckoutDisplay::Modal)->page();
```

Apple Pay works with the Payment Page only. If Checkout.js reports an error, the payer sees a message. To handle a callback
yourself, pass `callbacks: ['complete' => 'myFunction']` with the name of a global JavaScript function (`error`, `cancel`,
`complete`, `timeout`, `beforeRedirect` or `afterRedirect`). `cancel` works with the Payment Page only.

### Verify

```php
$result = $gateway->verifyCapture($orderId, Money::of('1250.50', Currency::Mvr));

match ($result->status) {
    VerificationStatus::Captured => /* paid, exactly */,
    VerificationStatus::Authorized => /* funds are held: capture() or void() them */,
    VerificationStatus::PartiallyCaptured => /* part captured, and the rest can still be */,
    VerificationStatus::Verified => /* VERIFY succeeded, and no money moved */,
    VerificationStatus::AmountMismatch, VerificationStatus::CurrencyMismatch => /* money moved, but wrong: never auto-pay */,
    VerificationStatus::Pending => /* ask again later */,
    VerificationStatus::NotCaptured, VerificationStatus::NotFound => /* not paid */,
    VerificationStatus::Reversed => /* refunded or voided */,
};
```

`NotFound` means the gateway itself says it has no such order, and it counts as not paid. It needs a
body in the gateway's error format (`result` `ERROR`, `error.cause` `INVALID_REQUEST`, no `error.field` or
`error.validationType`), and then either HTTP 404 with any wording, or HTTP 400 whose explanation says
the order is missing. Any other 400 or 404, such as an HTML page from a wrong host or a proxy, throws
`GatewayException`, because it says nothing about the money. Its message says the response was not
recognised as a gateway error and names the host, so check your host setting first.

If MIB's real response turns out not to match, `verifyCapture()` throws for orders that were never paid
and `AttemptGuard` throws too, so a payer with an abandoned attempt cannot start a new one. No money moves.
Try an unknown order ID on the test gateway before going live, and report it if it throws.

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
$gateway->pay($orderId, $txnId, $amount, StoredPayment::payerInitiated($token));     // a saved card
$gateway->authorize($orderId, $txnId, $amount, StoredPayment::merchantInitiated($token, $agreementId));
$gateway->verifyAccount($orderId, $txnId, Currency::Mvr, StoredPayment::payerInitiated($token));
$gateway->referral($orderId, $txnId, $authorizationCode);
$gateway->retrieveOrder($orderId)->token();          // the token, if the payer agreed to save the card
$gateway->retrieveToken($token);
$gateway->deleteToken($token);
$gateway->retrieveAgreement($agreementId);
$gateway->planOffers($planId, $amount, 12, $token);
$gateway->retrieveSession($sessionId);
$gateway->checkGateway()->isOperating();
$gateway->paymentOptionsInquiry();
$gateway->send(new SomeOperation(...));               // any Core\Contracts\Operation
```

Persist each new transaction ID before sending: resending the identical request after a timeout is
safe. A declined operation is a `TransactionResponse` with `Result::Failure`, not an exception.

`capture()` also takes updated order data, such as a new shipping address, as its last argument.

### Payment links

```php
$link = $gateway->createPaymentLink(
    CheckoutRequest::create($orderId, $amount, 'My Shop', 'https://shop.example/return')
        ->withoutWebhook()
        ->withDescription('Invoice 123')
        ->withMerchantDetails(url: 'https://shop.example')
        ->withPaymentLink(new DateTimeImmutable('+7 days'), allowedAttempts: 3, errorUrl: 'https://shop.example/link-error'),
);

$link->url;                              // share this
$gateway->deletePaymentLink($link->id);  // if it is no longer needed
```

### Reversing a capture

```php
$outcome = (new ReversalHelper($gateway))->reverseWithDerivedIds($orderId, 'MY-REFERENCE');
```

It voids the latest approved payment or capture, and falls back to a refund of the refundable amount
against that capture. The void and refund IDs are derived from the order and capture
(`ReversalIds::derive()`, built on `TransactionId::derive()`), so calling again sends identical requests.
Keep the reference the same too. To use your own IDs, call `reverse($orderId, $voidId, $refundId)` and
persist them before sending.

| `$outcome->method` | `$reversed` | What it means | What to do |
|---|---|---|---|
| `Void` | true | The capture was voided | Nothing |
| `Refund` | true | The refund was approved | Nothing |
| `Refund` | false | The refund definitely failed | A person decides. Calling again sends nothing |
| `Unconfirmed` | false | The void or refund result is unknown, so no refund was sent | Call again later, with a limit, then escalate |
| `Nothing` | false | No approved capture, or nothing left to refund | Nothing |

- **Refund only when the void definitely failed.** That is a `FAILURE` or `ERROR` result, except for codes
  that do not prove it, such as `TIMED_OUT` or `UNSPECIFIED_FAILURE` ("The gateway does not know the result
  of the transaction ... Contact your acquirer").
- **HTTP 400 on the void:** the void is read back by its ID and the order is re-read. The refund goes out
  only if the void is absent or definitely failed and the order has not moved. Otherwise it is `Unconfirmed`.
  If one of those reads fails, the exception is rethrown and no refund is sent.
- **Any other 4xx, a 400 not in the gateway's error format, a 5xx or no response** is rethrown, and no
  refund is sent. Call again. The same goes for a read of the void that gets one of these.
- **Every call reads the order first.** A void or refund it already lists is reported, and nothing is sent:
  approved means reversed, anything unsettled means `Unconfirmed`. A pending void or refund anywhere on the
  order also gives `Unconfirmed`, because the refund amount comes from the order's totals.
- **Partial refunds:** a refund under another ID counts as this reversal only if it targets this capture and
  covers all of it. Otherwise the remainder is refunded.
- **Retrying after a definite failure:** the docs say a failed operation needs a new ID, and an identical
  request gets the first response. So a failed void is not resent; the next step is the refund. To retry a
  failed refund, call `reverse()` with the outcome's void ID and `TransactionId::generate('refund')`.

Calling `reverse()` yourself gives no retries or escalation. On `Unconfirmed`, call again later with a limit,
and when that runs out, have a person ask the acquirer. Do not refund by other means until then.

All of this follows the MIB documentation (API v100). None of it has been observed on the live gateway,
including what a duplicate request returns and whether Retrieve Order lists a pending void.

## Laravel

The service provider is auto-discovered. Configure `.env`:

```dotenv
MIB_GATEWAY_URL=test-mib.mtf.gateway.mastercard.com
MIB_MERCHANT_ID=TEST123
MIB_API_PASSWORD=...
MIB_ORDER_PREFIX=SHOP
MIB_MERCHANT_NAME="My Shop"
MIB_WEBHOOK_SECRET=...            # from Merchant Administration (Webhook Notifications)
```

All keys: `config/mib-global-pay.php` (`php artisan vendor:publish --tag=mib-global-pay-config`).

### Quick start

The package stores the attempts, shows the payment page, receives the payer back and handles the webhook. You write a model
and a listener.

**1. Create the tables.**

```bash
php artisan vendor:publish --tag=mib-global-pay-migrations && php artisan migrate
```

**2. Make your model payable.**

```php
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Laravel\Concerns\HasMibPayments;

class Invoice extends Model implements Payable
{
    use HasMibPayments;

    public function payableReference(): string
    {
        return $this->number;
    }

    public function expectedAmount(): Money
    {
        return Money::of($this->total, Currency::Mvr);
    }
}
```

**3. Send the payer to pay.**

```php
return redirect(MibGlobalPay::startCheckout($invoice)->paymentUrl());
```

**4. Listen for the result.**

```php
Event::listen(PaymentCaptured::class, fn (PaymentCaptured $event) => $event->payable->update(['paid_at' => now()]));
```

**5. Schedule the reconcile command.** Run `mib:reconcile` every few minutes and `mib:prune-notifications` daily.

The rest is optional:

| To do this | Use this |
|---|---|
| Choose the display | `MIB_CHECKOUT_MODE=payment`, `embedded` or `modal` |
| Land on your own page afterwards | `MIB_CHECKOUT_COMPLETED_URL`, which receives `?order=...&status=...` |
| Reserve funds, then capture | `MibGlobalPay::startCheckout($invoice, InteractionOperation::Authorize)`, listen for `PaymentAuthorized`, then call `capture()` |
| Verify a card | `MibGlobalPay::startCheckout($invoice, InteractionOperation::Verify)`, and listen for `AccountVerified` |
| Share a payment link | `MibGlobalPay::startPaymentLink($invoice, now()->addDays(7))->paymentUrl()` |
| Add to the request | `MibGlobalPay::startCheckout($invoice, customize: fn ($r) => $r->withSaveCard())` |
| Change what counts as paid | Override `payableState()` on your model |

`startCheckout()` reuses a live attempt, retires expired ones, and throws `CheckoutNotAllowed` when a new attempt is unsafe,
such as when money is already captured or held. `$e->result->reason` says why.

### When a payment stays unresolved

A `TIMED_OUT` or pending payment might still have reached the bank, so the package never lets a timer clear it for a new charge:

1. **While it is unresolved,** a new attempt is refused with `VerificationPending`, the payer is told the payment is being
   confirmed, and `mib:reconcile` keeps asking the gateway.
2. **After 24 hours** (`MIB_REVIEW_AFTER_SECONDS`), `PaymentNeedsReview` fires once. Listen for it and have a person ask the acquirer.
3. **If it was not charged,** call `MibGlobalPay::confirmUnpaid($event->attempt, 'finance@example.mv', 'Acquirer confirmed')`. It asks
   the gateway once more, throws `AttemptNotUnpaid` if money moved, records who decided, and lets a new attempt start.
4. **If a charge arrives late,** `mib:reconcile` still finds it for 7 days (`MIB_LATE_CAPTURE_WATCH_SECONDS`), even if the webhook is
   lost. If nothing else was paid, it settles as normal. If a newer attempt was paid, `CaptureMismatch` fires and the late charge is
   reversed automatically, which needs a queue worker.

The review period and the watch period are defaults we chose, not gateway rules. Whether `TIMED_OUT` is final is an open question for MIB.

The webhook stays at `POST /mib/webhook`. The pages are at `/mib/pay/{order}`, `/mib/return/{order}` and `/mib/link-error`,
which `MIB_CHECKOUT_ROUTE_PREFIX` can change. Views can be overridden as `vendor/mib-global-pay/pages`.

### Using your own storage

To keep attempts in your own tables, set `MIB_MANAGED_ATTEMPTS=false`, or bind your own `PayableResolver`, which replaces the
package's. Then:

1. **Dedupe table:** `php artisan vendor:publish --tag=mib-global-pay-migrations && php artisan migrate`
   (or `MIB_NOTIFICATION_STORE=cache` with a shared cache, though the database store is the better choice: see the
   listener contract in step 4).
2. **Implement and bind** `Core\Attempts\PayableResolver`, with your models implementing `Payable`
   and `Attempt` (DESIGN-v2.md §6.7). The webhook answers 503 until the resolver is bound.
3. **Webhook route:** auto-registered at `POST /mib/webhook` (`MIB_WEBHOOK_PATH`, `MIB_WEBHOOK_DOMAIN`).
   To register it yourself, set `MIB_WEBHOOK_REGISTER_ROUTE=false` and:
   ```php
   Route::domain('pay.example.com')->group(function () {
       Route::mibGlobalPayWebhook('payment-gateways/mib/webhook');
   });
   ```
   If the route sits in a group that checks CSRF tokens, such as `web`, add its path to your CSRF exception list in
   `bootstrap/app.php`. Otherwise, on Laravel 13, every notification from the bank gets a 419.
   ```php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->validateCsrfTokens(except: ['payment-gateways/mib/webhook']);
   })
   ```
   The route can sit in a name-prefixed or domain group. The webhook URL is still found (for `newCheckoutRequest()` and
   the tests), with or without `route:cache` or `artisan optimize`, and it is the same URL either way.
   - With more than one route from the macro or auto-registration, the last one registered wins. Give each its own name
     (a group's name prefix does that). `route:cache` refuses two routes with one name, so leaving auto-registration on
     next to a macro route with no name prefix makes it fail.
   - With `MIB_WEBHOOK_ENABLED=false` nothing is auto-registered, so the URL is null. A macro route stays registered, so
     its URL is still returned, and it answers 404.
   - After changing any of these settings or routes, run `route:cache` again, as for any other route change.
   It answers 200 (accepted or duplicate), 401 (bad secret), 400 (malformed), 503 (not configured).
   Processing runs on the queue (`MIB_WEBHOOK_CONNECTION`, `MIB_WEBHOOK_QUEUE`).

   **Queue and retries.** Run a real queue worker. `ProcessNotification` sets its own retries, so the worker's
   `--tries` and `--backoff` do not change them: 5 attempts, waiting 30 seconds, 2, 5 and 10 minutes (about 18 minutes in all).
   - Gateway timeouts, HTTP 5xx, 401, 403, 408, 425 and 429, garbled responses and any other exception are retried.
   - A malformed job, an order ID the package cannot send, and any other HTTP 4xx in the gateway's error format fail on
     the first attempt. A 4xx not in that format, such as a 404 page from a wrong host, is retried like an outage, and
     no `PaymentFailed` is dispatched for it.
   - While retries remain, the notification has no outcome, and a redelivery gets `duplicate` without queueing a second job.
     It becomes `Failed` only when the job fails for good. The job is then in your failed jobs and the notification
     stays claimed, so redeliveries do not reprocess it: use `php artisan queue:retry`, which sets the final outcome.
   - After that, `mib:reconcile` dispatches again for any attempt your `awaitingGateway()` still returns. It cannot reach
     a refund or void event, or an attempt you no longer report as awaiting. For those, use `queue:retry`.
   - On the `sync` queue there are no retries: a failure answers 503 and the bank redelivers.

   **When a listener throws**, what happens next depends on the event:
   - **Money events** (`PaymentCaptured`, `PaymentPartiallyCaptured`, `PaymentAuthorized`, `AccountVerified`, `PaymentNeedsReview`,
     `PaymentCapturedForUnpayableOrder`, `CaptureMismatch`, `PaymentReversed`, `RefundProcessed`, `VoidProcessed`) go out at least once until one dispatch completes, then not again. A failed
     dispatch records nothing, so the retry dispatches again. If the retries run out, the notification is `Failed`,
     never `Processed`.
   - **Advisory events** (`NotificationReceived`, `UnknownOrderNotification`, `PaymentPending`, `PaymentFailed`) go out
     at most once per notification, even if a listener threw. The job carries on and can still end as `Processed`.
     `PaymentPending` and `PaymentFailed` from the return URL or `mib:reconcile` have no notification, so those triggers
     send them each time.
4. **Listen to the events** (`IbnNajjaar\MIBGlobalPay\Laravel\Events`). They are dispatched inside
   `Payable::lock()`; the package never changes your models:
   `PaymentCaptured`, `PaymentPartiallyCaptured`, `PaymentAuthorized`, `AccountVerified`, `PaymentCapturedForUnpayableOrder`,
   `CaptureMismatch`, `PaymentPending`, `PaymentFailed`, `PaymentReversed`, `UnknownOrderNotification`, `RefundProcessed`, `VoidProcessed`,
   `NotificationReceived`, `PaymentNeedsReview`. With double-capture auto-reversal on, also `ReversalUnconfirmed` and `ReversalFailed`.
   `RefundProcessed` and `VoidProcessed` are the exceptions: they go out with no lock, because the order may be unknown.

   **Listener contract.** A completed dispatch is recorded only after `Payable::lock()` has returned, so a money event
   can repeat: after a crash before the record, when a second worker enters the lock before the record (`PaymentCaptured`
   is then stopped by your settlement, the others are not), and for refunds and voids when two workers overlap. So:
   - **Be idempotent on `$event->deliveryKey`**, for example with a unique column. Every money event has it: 64 hex
     characters, the same for one order ID, transaction ID and outcome, whichever trigger sent it.
     - If an approved transaction has no ID, a `CaptureMismatch` or `PaymentReversed` is keyed on the status and totals the
       gateway reports, and a `RefundProcessed` or `VoidProcessed` on its notification ID, so a redelivery under a new
       notification ID would count as a new event.
   - **Send emails and other non-database effects from a `ShouldQueueAfterCommit` listener or `DB::afterCommit`.** A
     retry calls your listeners again, and a rollback does not undo an email.
   - **Hold `Payable::lock()` in a `DB::transaction`, and prefer the database store.** The cache cannot roll back with your
     transaction: the package holds a cache delivery record until your transaction commits, but an eviction or
     `cache:clear` loses it, and advisory claims are not held back at all. Without a rollback, a settlement one listener
     saved outlives a later listener that threw, so the retry calls the first one again. If the process dies mid-dispatch
     instead, that settlement stops `PaymentCaptured` for good and later listeners are not called, so save it last.
   - **Keep returning an attempt from `awaitingGateway()` until your listener has succeeded**, or `mib:reconcile` cannot
     dispatch for it.
5. **Checkout:**
   ```php
   $request = MibGlobalPay::newCheckoutRequest(MibGlobalPay::attemptOrderId($ref, $n), $amount, 'My Shop', $returnUrl)
       ->withReference($ref);
   $session = MibGlobalPay::initiateCheckout($request);
   ```
   ```blade
   <x-mib-global-pay::checkout :session="$session->sessionId" />
   ```
   The component redirects to the Payment Page. Add `mode="embedded"` or `mode="modal"` to show the Embedded Page instead.
   If Checkout.js reports an error, the element shows a message to the payer. To handle a callback yourself, name a global
   JavaScript function with `error`, `cancel`, `complete`, `timeout`, `before-redirect` or `after-redirect`. `cancel` works
   with the Payment Page only, so the component refuses it in the other modes.

   Before a retry, call `MibGlobalPay::attemptGuard()->canStartNewAttempt($payable)` inside the lock. It asks the bank about every
   open attempt. If one is already paid, it refuses with `MoneyCaptured` and dispatches `PaymentCaptured` itself. If a live
   attempt is still unpaid, it returns it as `reusableAttempt`. Otherwise it allows a new attempt and lists the old ones to mark
   `Superseded`. Your `Payable::lock()` must be re-entrant (nested `DB::transaction` is).
6. **Return URL:** `app(PaymentOutcome::class)->resolveFromReturn($orderId, $request->query())`. The gateway only documents
   adding `resultIndicator` to the URL, so put the order ID in your own return URL, for example as a route parameter.
7. **Schedule** `mib:reconcile` (every few minutes) and `mib:prune-notifications` (daily). Pruning keeps money-event
   delivery records, about one small row per event, because `mib:reconcile`, `AttemptGuard` and the return URL can reach an
   attempt at any time. With the cache store they have no expiry either.

Double-capture auto-reversal is on by default, and needs a queue worker. Turn it off with `MIB_AUTO_REVERSE_DOUBLE_CAPTURE=false`. It queues the
`ReverseCapture` job, which uses `reverseWithDerivedIds()`, so a retry or a second job for the same capture
sends identical requests. Retries come from the job, not from the worker's settings:

- `Unconfirmed` and exceptions are retried, up to 6 attempts over about 45 minutes (1, 5, 10, 15 and 15 min).
- When they run out, it logs an error and dispatches `ReversalUnconfirmed` (`gatewayOrderId`, `ids`,
  `transaction`, `error`). The money may or may not have gone back, so listen for this and have a person ask the
  acquirer. After an exception, `ids` is null and `error` is set; `ReversalIds::derive()` gives the IDs from the
  order and capture. That job is also in your failed jobs.
- A refund that definitely failed is not retried. It logs an error and dispatches `ReversalFailed`
  (`gatewayOrderId`, `ids`, `refund`): the money was not returned.
- On the `sync` queue there are no retries, so `Unconfirmed` escalates at once.

## Testing your integration

`MibGlobalPay::fake()` replaces the gateway with an in-memory fake (no network) for the whole app:
the facade, the webhook job, the outcome pipeline, `PaymentOutcome` and `mib:reconcile`. Requests
are still validated against your configuration. Type-hint the `Core\Contracts\Gateway` contract in
your own code; anything that type-hints the concrete `MibGateway` keeps the real client.

```php
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\InteractsWithMibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\NotificationFactory;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\OrderFactory;
use Illuminate\Support\Facades\Event;

uses(InteractsWithMibGlobalPay::class); // Pest; or `use InteractsWithMibGlobalPay;` in a PHPUnit test case

it('pays the invoice when MIB reports the capture', function () {
    $amount = Money::of('1250.50', Currency::Mvr);
    $fake = MibGlobalPay::fake()->order('SHOP-R-INV-2026-123-1', OrderFactory::captured($amount));
    Event::fake([PaymentCaptured::class]); // or let your real listeners run

    $this->postMibNotification(NotificationFactory::paymentCaptured('SHOP-R-INV-2026-123-1', $amount))
        ->assertOk();

    Event::assertDispatched(PaymentCaptured::class);
    $fake->assertOrderRetrieved('SHOP-R-INV-2026-123-1');
});
```

- **Defaults for anything not stubbed:** Initiate Checkout returns a new session, an unknown order
  is a 404 in the gateway's error format (`verifyCapture()` gives `NotFound`), Capture/Refund/Void/Update
  Authorization are approved, Check Gateway is `OPERATING`. Call `->strict()` to make any
  unstubbed call throw a `LogicException` instead.
- **Stubs:** `checkoutSession(?$orderId, sessionId:, successIndicator:)`;
  `order($orderId, ...$payloads)` (returned in sequence, then the last repeats, e.g.
  `OrderFactory::pending($a), OrderFactory::captured($a)`); `respondWith(Capture::class, $payload|$exception|fn ($op) => ...)`. A stubbed `GatewayException` counts
  as a gateway error only if you pass the JSON as its raw body (fourth argument). Without that it behaves like a
  response from a wrong host: `new GatewayException('HTTP 404', 404)` makes `verifyCapture()` throw.
- **Order payloads:** `OrderFactory::captured`, `authorizedOnly`, `partiallyCaptured`, `authorizationVoided`, `verified`,
  `declined`, `pending`, `timedOut`, `partiallyRefunded`, `refunded`, `voided`, `withoutCapturedTotal`.
- **Notifications:** `NotificationFactory::paymentCaptured`, `paymentDeclined`, `authorized`, `verified`, `refund`, `void`,
  `authentication`, `other(array)`; `postMibNotification($n, secret: ..., uri: ...)` posts to the
  `mib-global-pay.webhook` route with your configured `MIB_WEBHOOK_SECRET`.
- **Assertions:** `assertCheckoutInitiated(?fn (CheckoutRequest $r) => ...)`,
  `assertCheckoutNotInitiated()`, `assertOrderRetrieved(?$orderId)`, `assertCaptured(?fn)`,
  `assertRefunded(?fn)`, `assertVoided(?fn)`, `assertSent(Operation::class, ?fn)`,
  `assertNothingSent()`, and `sent(?Operation::class)` to inspect.
- The webhook URL that `newCheckoutRequest()` adds must be HTTPS: make sure your app generates
  `https://` URLs in tests too (`URL::forceScheme('https')`, or an `https://` `APP_URL`).

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
composer refactor-check  # Rector, dry run (`composer refactor` applies the changes)
```

[WORKFLOW.md](https://github.com/ibnnajjaar/mib-global-pay/blob/main/WORKFLOW.md) shows the payment flow.

## License

MIT. See [LICENSE](./LICENSE). See the CHANGELOG for release notes: [CHANGELOG.md](./CHANGELOG.md).
