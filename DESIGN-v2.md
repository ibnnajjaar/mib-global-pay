# Design: `ibnnajjaar/mib-global-pay` v2.0.0

Status: **draft for review**. No v2 code exists yet.
Supersedes nothing. It builds on `docs/v1.2-implementation-plan.md` §9 (items deferred to v2).

Facts about the gateway in this document come from the MIB test-gateway documentation, read on 2026-10-28. That is the documentation at `https://test-mib.mtf.gateway.mastercard.com/api/documentation/`, API reference REST-JSON v100. §14–§17 hold the feature inventory and the list of sources. Anything not confirmed there is marked **(unverified)**.

---

## 0. Goals and non-goals

### Goals

1. A **PHP 8.4+ only** core. It is strict, typed and immutable, with no floats for money.
2. An **optional Laravel 13 layer** in the same package (`src/Laravel`). It has a service provider, config, a webhook route, a controller and middleware, events, a host contract and a testing fake.
3. Make the ISIMS class of bug **hard to write**. A gateway capture must never become "paid" without three checks:
   - the secret is verified;
   - the amount and currency are verified against what the host expected;
   - the host has confirmed that the payment is still payable.
4. Support the two ISIMS policies directly:
   - **Capture after cancel:** re-link and pay only if all the original invoices are still unpaid and the amount matches. Otherwise flag it for finance.
   - **Frozen while live:** a payment with a live checkout session is frozen until MIB gives a final status, and retries reuse it.

### Non-goals

- The package will **not** own the host's Payment or Invoice models, migrations or business rules. ISIMS keeps those.
- It will not handle direct card capture (PCI scope). Hosted Checkout stays the only payer flow.
- It will not support Laravel < 13 or PHP < 8.4.

---

## 1. Package layout

```
src/
  Core/                         # framework-agnostic, PHP 8.4
    MibGateway.php              # the connector (replaces MIBGlobalPayConnector)
    Config/GatewayConfig.php    # readonly: host, merchantId, apiPassword, apiVersion, timeout
    Http/                       # transport: PSR-18 client interface + Guzzle 7 default
    Requests/                   # one readonly class per API operation
    Responses/                  # readonly DTOs
    Enums/                      # backed enums
    Money/Money.php, Currency.php
    Verification/               # CaptureVerifier, VerificationResult
    Webhook/                    # Notification, NotificationVerifier, SecretMismatch
    Exceptions/
  Laravel/                      # optional, only loaded when Laravel is present
    MibGlobalPayServiceProvider.php
    Facades/MibGlobalPay.php
    Contracts/PayableResolver.php, NotificationStore.php
    Http/Controllers/WebhookController.php
    Http/Middleware/VerifyNotificationSecret.php
    Jobs/ProcessNotification.php
    Events/*.php
    Testing/MibGlobalPayFake.php
config/mib-global-pay.php
routes/webhook.php
database/migrations/        # ONLY the package's own dedupe table, publishable and optional (see §6.4)
```

Namespace: `IbnNajjaar\MIBGlobalPay\` is kept, and `Core\` and `Laravel\` become sub-namespaces. The root namespace stays as it is (decided, §12 Q1).

---

## 2. Language rules (PHP 8.4+)

- `declare(strict_types=1);` in every file.
- All DTOs and value objects are `final readonly class`, built by named constructors (`fromArray`, `fromResponse`).
- No mutable setters. `OrderData` becomes an immutable builder: `with*()` methods return a clone.
- PHP 8.4 features where they help:
  - ~~property hooks for derived values such as `Money::$formatted`~~. PHP does not allow hooks on readonly properties, so readonly value objects use methods (`Money::toDecimal()`) instead (stage 2);
  - asymmetric visibility (`public private(set)`) on the few builders that need it;
  - `new` without parentheses in chains;
  - `array_find` / `array_any` in place of loops (`OrderResponse::findTransaction`).
- No `mixed`, except at the decode boundary. Every payload is parsed once, null-safely, through a small `Payload` reader (`string()`, `?string()`, `money()`, `enum()`). The reader never emits notices. This fixes the ISIMS request about `fromArray` throwing notices when keys are missing.
- Unknown enum values from the gateway map to an `Unrecognized` case (backed by `''`) via a never-throwing `fromGateway(?string): self`, not an exception. The DTO keeps the raw string. The fallback is not named `Unknown`, because `Result` and `GatewayCode` have a real gateway value `UNKNOWN` (decided, stage 2).
- PHPStan level max (or Larastan for `src/Laravel`) in CI. `src/Core` runs at `max` through `phpstan-core.neon.dist`; the 1.x code stays at level 1 until stage 8.

---

## 3. Enums and Money

### 3.0 Decisions taken in stage 2 (core types)

1. **Enum cases** are PascalCase, backed by the gateway's string, e.g. `GatewayCode::ApprovedPendingSettlement = 'APPROVED_PENDING_SETTLEMENT'`.
2. **Fallback.** Every gateway enum (`Core\Enums\GatewayEnum`: OrderStatus, TransactionType, Result, GatewayCode, GatewayRecommendation, plus `Currency`) has `case Unrecognized = ''` and `fromGateway(?string): static`. That method never throws and matches case-insensitively, ignoring surrounding whitespace. `isRecognized()` tells the two apart. Config-only enums (`Environment`) have no fallback.
3. **Currency** is a curated ISO 4217 subset with its minor-unit exponents, and no ICU dependency: AED 2, EUR 2, GBP 2, INR 2, JPY 0, KWD 3, LKR 2, MVR 2, SGD 2, USD 2. The docblock on `Core\Money\Currency` explains how to add one. The config allow-list defaults to `[MVR]`.
4. **Rounding.** `Money::of()` rejects too many decimals with `InvalidAmount`. `Money::ofRounded(string, Currency, \RoundingMode)` rounds explicitly, using PHP 8.4's native `RoundingMode`.
5. **Negative amounts.** `Money` may be negative. Request builders and operations enforce positive amounts.
6. **JSON floats.** `Money::fromGateway(int|float|string)` and `Payload::money()` accept a float only if it is exactly representable with at most the currency's minor units. They check `sprintf('%.{n}F')` round-trips for n = 0…units, so the result does not depend on `serialize_precision`. Floats exist only at this decode step.
7. **Environment presets.** `Test` uses `test-mib.mtf.gateway.mastercard.com`. `Production` has no preset (TODO pending MIB question 14), so `GatewayConfig` throws `ConfigurationException` unless a host is set.
8. **Exceptions** implement the marker interface `Core\Exceptions\MibGlobalPayException` on SPL subclasses:
   - `InvalidAmount`, `InvalidRequest`, `UnsupportedCurrency` extend `InvalidArgumentException`;
   - `CurrencyMismatch`, `ConfigurationException`, `OperationNotEnabled` extend `DomainException`;
   - `SecretMismatch`, `TransportException`, `GatewayException` extend `RuntimeException`;
   - `MalformedNotification`, `MalformedPayload` extend `UnexpectedValueException`.
9. **`GatewayConfig`:**
   - `orderPrefix` is required: 1–10 characters `[A-Za-z0-9]`, no default.
   - Approval is an `ApprovalPolicy` value with `acceptApprovedAuto` defaulting to false.
   - Other defaults: API version 100, timeout 30 s, allow-list `[MVR]`, default currency MVR, `allowInsecureUrls` false.
   - The gateway host must be HTTPS.
   - The password is redacted from `__debugInfo()`.

**Pending (stage 4):** the enum helpers listed under §3.1 (`OrderStatus::isFinal()`, `isMoneyHeld()`, `TransactionType::isVoid()`, `isMoneyIn()`) need exact definitions, and are deferred to the verification stage. `GatewayCode::isApproved()` is replaced by `ApprovalPolicy`, because approval depends on the `APPROVED_AUTO` opt-in.

### 3.1 Backed enums (`Core/Enums`)

| Enum | Values (backed by the gateway's uppercase string) |
|---|---|
| `OrderStatus` | AUTHENTICATED, AUTHENTICATION_INITIATED, AUTHENTICATION_NOT_NEEDED, AUTHENTICATION_UNSUCCESSFUL, AUTHORIZED, CANCELLED, CAPTURED, CHARGEBACK_PROCESSED, DISBURSED, DISPUTED, EXCESSIVELY_REFUNDED, FAILED, FUNDING, INITIATED, PARTIALLY_CAPTURED, PARTIALLY_REFUNDED, REFUNDED, REFUND_REQUESTED, VERIFIED, `Unknown` |
| `TransactionType` | AUTHENTICATION, AUTHORIZATION, AUTHORIZATION_UPDATE, CAPTURE, CHARGEBACK, DISBURSEMENT, FUNDING, PAYMENT, REFUND, REFUND_REQUEST, VERIFICATION, VOID_AUTHORIZATION, VOID_CAPTURE, VOID_PAYMENT, VOID_REFUND, `Unknown` |
| `Result` | SUCCESS, FAILURE, PENDING, UNKNOWN, ERROR |
| `GatewayCode` | APPROVED, APPROVED_AUTO, APPROVED_PENDING_SETTLEMENT, DECLINED, … (full list from the API reference), `Unknown` |
| `InteractionOperation` | PURCHASE, AUTHORIZE, VERIFY, NONE |
| `Currency` | ISO 4217 codes; usable set restricted by the config allow-list `currencies` (`MIB_CURRENCIES`, default `[MVR]`) (decided, §12 Q5) |

Helper methods live on the enums:

- `OrderStatus::isFinal()`, `isMoneyHeld()`
- `TransactionType::isVoid()`, `isMoneyIn()`
- `GatewayCode::isApproved()`

### 3.2 One approval rule

In v1, `paymentIsSuccessful()` and `hasPaidTransaction()` required gatewayCode `=== 'approved'`, while `isApproved()` accepted any `approved*` prefix. v2 replaces both with **one** rule, used everywhere: return URL, webhook and Retrieve Order.

```php
// Core/Verification/ApprovalPolicy.php (implemented in stage 2)
final readonly class ApprovalPolicy
{
    public function __construct(public bool $acceptApprovedAuto = false) {}

    /** APPROVED, APPROVED_PENDING_SETTLEMENT (+ APPROVED_AUTO when opted in). NOT a prefix match. */
    public function approvedCodes(): array;

    public function isApproved(Result $result, GatewayCode $code): bool; // Result::Success && in approvedCodes()
}
```

The prefix match is dropped, because a new gateway code starting with `APPROVED_` must not silently count as money received.

The v100 Retrieve Order reference (`transaction[n].response.gatewayCode`) lists these codes in the approval family:

| Code | Doc description | v2 treatment |
|---|---|---|
| `APPROVED` | "Transaction Approved" | approved |
| `APPROVED_PENDING_SETTLEMENT` | "Transaction Approved - pending batch settlement" | approved |
| `APPROVED_AUTO` | "automatically approved by the gateway. it was not submitted to the acquirer" | **not** money received by default. Opt in with config `approval.accept_approved_auto` / `MIB_ACCEPT_APPROVED_AUTO=false` (decided, §12 Q15). |
| `PARTIALLY_APPROVED` | "approved for a lesser amount than requested. The approved amount is returned in order.totalAuthorizedAmount" | not approved. `verifyCapture` reports `AmountMismatch` if money moved. |
| `PENDING`, `SUBMITTED`, `DEFERRED_TRANSACTION_RECEIVED`, `UNKNOWN`, `TIMED_OUT` | in progress or unknown | `Pending`. Never paid, never failed: ask again later. |

The full v100 enum is: ABORTED, ACQUIRER_SYSTEM_ERROR, APPROVED, APPROVED_AUTO, APPROVED_PENDING_SETTLEMENT, AUTHENTICATION_FAILED, AUTHENTICATION_IN_PROGRESS, BALANCE_AVAILABLE, BALANCE_UNKNOWN, BLOCKED, CANCELLED, DECLINED, DECLINED_AVS, DECLINED_AVS_CSC, DECLINED_CSC, DECLINED_DO_NOT_CONTACT, DECLINED_INVALID_PIN, DECLINED_PAYMENT_PLAN, DECLINED_PIN_REQUIRED, DEFERRED_TRANSACTION_RECEIVED, DUPLICATE_BATCH, EXCEEDED_RETRY_LIMIT, EXPIRED_CARD, INSUFFICIENT_FUNDS, INVALID_CSC, LOCK_FAILURE, NOT_ENROLLED_3D_SECURE, NOT_SUPPORTED, NO_BALANCE, PARTIALLY_APPROVED, PENDING, REFERRED, SUBMITTED, SYSTEM_ERROR, TIMED_OUT, UNKNOWN, UNSPECIFIED_FAILURE.

`GatewayCode` mirrors this list, with `Unrecognized` as the fallback (the real `UNKNOWN` value is `GatewayCode::Unknown`). `Result` in v100 has FAILURE, PENDING, SUCCESS and UNKNOWN; ERROR appears only in older payloads, so it is kept for safety. `gatewayRecommendation` (PROCEED, DO_NOT_PROCEED, RESUBMIT_WITH_3DS, …) is exposed as its own enum.

"Money captured" is a stronger condition than "approved". It means an approved transaction of type `PAYMENT` or `CAPTURE`, and an order whose `totalCapturedAmount` is greater than zero.

### 3.3 Money

`final readonly class Money { int $minor; Currency $currency; }`

- Built from a string, `Money::of('100.50', Currency::Mvr)`, or from minor units, `Money::ofMinor(10050, Currency::Mvr)`. Explicit rounding: `Money::ofRounded('1.005', Currency::Mvr, RoundingMode::HalfEven)`. Gateway values: `Money::fromGateway(int|float|string, Currency)`.
- **There is no float constructor.** A decoded JSON float is accepted only by `fromGateway()`, and only when it is exactly representable at the currency's precision (§3.0 point 6).
- Arithmetic and comparison: `plus()`, `minus()`, `negate()`, `abs()`, `equals()` (never throws), `compare()`, `greaterThan()`, `greaterThanOrEqual()`, `lessThan()`, `lessThanOrEqual()`, `isZero()`, `isPositive()`, `isNegative()`. Combining or ordering different currencies throws `CurrencyMismatch`, and overflow throws `InvalidAmount`.
- Serialised to the API as a decimal string with the currency's precision (2 for MVR and USD).

---

## 4. Core API

### 4.1 Connector

```php
$gateway = new MibGateway(new GatewayConfig(
    host: 'test-mib.mtf.gateway.mastercard.com',
    merchantId: '…',
    apiPassword: '…',
    apiVersion: 100,
    timeout: 30,
), httpClient: null /* PSR-18; defaults to Guzzle 7 */);
```

Method names follow the API operation names. This is a breaking change from v1 (§10).

| Method | API operation | Returns | v2 scope |
|---|---|---|---|
| `initiateCheckout(CheckoutRequest)` | Hosted Checkout: Initiate Checkout | `CheckoutSession` (sessionId, successIndicator, sessionVersion) | in |
| `retrieveSession(string $sessionId)` | Session: Retrieve Session | `SessionDetails` | in |
| `updateSession(string $sessionId, SessionUpdate)` | Session: Update Session | `SessionDetails` | in (see §12, Q4) |
| `retrieveOrder(string $orderId)` | Transaction: Retrieve Order | `OrderResponse` | in |
| `retrieveTransaction(string $orderId, string $txnId)` | Transaction: Retrieve Transaction | `TransactionResponse` | in |
| `refund(…)`, `void(…)`, `capture(…)`, `updateAuthorization(…)` | Transaction ops | `TransactionResponse` | in (unreleased 1.2 work, rewritten) |
| `verifyCapture(…)` | (Retrieve Order + rules) | `VerificationResult` | in, new (§4.2) |
| `checkGateway()` | Gateway: Check Gateway | `GatewayStatus` | in (cheap health check, no auth) |
| everything else | see §14 | | Full gateway coverage. §14 is the complete inventory, and §14.0 sets the 2.0 / 2.x phasing. |
| Automatic 5xx retry middleware | — | — | in, opt-in, idempotent ops only (GET, and PUT with a fixed transaction ID). Never retries Initiate Checkout. |
| Environment presets (`Environment::Test` / `Production` → host) | — | — | in: `Environment::Test` / `Environment::Production` presets, with the host always overridable (decided, §12 Q6) |

A declined operation still returns a DTO with `Result::FAILURE`. HTTP 4xx and 5xx responses throw `GatewayException` (kept from 1.2, now readonly). Transport errors throw `TransportException`.

### 4.2 The single verification call

This is the core of the bug fix. One call answers: "is order X captured for exactly amount Y in currency Z?"

```php
$result = $gateway->verifyCapture(
    orderId: $payment->gateway_order_id,
    expected: Money::of($payment->amount, Currency::MVR),
);

$result->status;      // VerificationStatus enum (below)
$result->captured;    // Money: totalCapturedAmount
$result->refunded;    // Money
$result->order;       // OrderResponse, for audit
$result->isCapturedExactly(); // status === Captured
```

`VerificationStatus` cases:

| Case | Meaning |
|---|---|
| `Captured` | Approved payment or capture, captured amount equals expected, same currency |
| `AmountMismatch` | Money captured, but not equal to expected (over or under) |
| `CurrencyMismatch` | Captured in a different currency |
| `NotCaptured` | No approved money-in transaction (still initiated, declined, authorised only, …) |
| `Pending` | Latest transaction `Result::PENDING` or `UNKNOWN`. Ask again later. |
| `Reversed` | Captured and then fully refunded or voided |
| `NotFound` | Gateway has no such order (HTTP 400/404 with "order not found") |

Rules:

- **Always re-read the order from the gateway.** The return-URL indicator and the webhook body are only triggers. Neither is ever proof.
- It is idempotent and side-effect free, so it can be called any number of times from the return URL, the webhook, the reconciliation job and admin.
- It is also available as a pure function on an already-fetched order, `CaptureVerifier::verify(OrderResponse, Money)`, for tests and the fake.

### 4.3 Return URL

- `HostedCheckoutReturn::fromQuery(array $query)`
- `->matchesSuccessIndicator(string $stored): bool` (timing-safe)

The docs say: a match means "go and call `verifyCapture()`". It does **not** mean paid.

### 4.4 Checkout request

`CheckoutRequest` is immutable. It requires `orderId`, `Money amount`, `merchantName` and `returnUrl`, and has `with*()` for everything else.

- **New requirement:** `notificationUrl` is required unless the host passes `withoutWebhook()` explicitly. ISIMS sends none today.
- `orderId` is validated against the gateway's documented rule ("Data can consist of any characters", 1–40), plus a ban on control characters; identifiers are URL-encoded in paths. *(Stage 3; replaces the earlier `[A-Za-z0-9._-]` rule for request validation. Generated IDs stay within `[A-Za-z0-9._-]`, see §6.7 C. Pending confirmation, see the stage 3 questions.)* The docstring and README state: **never reuse an order ID once a session has been created for it** (§8).
- The description no longer defaults silently. ISIMS hard-codes `'test'`, so the docs will show a real description.

---

## 5. Webhooks (core)

### 5.1 Mandatory secret

```php
$verifier = new NotificationVerifier(secret: $config->webhookSecret); // non-empty string required
$notification = $verifier->verify(body: $rawBody, headers: $headers);  // throws SecretMismatch / MalformedNotification
```

- There is **no** way to build a trusted `Notification` without going through `NotificationVerifier`. `Notification`'s constructor is private, and `Notification::unverified()` exists only for tests and the fake, clearly named.
- An empty or missing configured secret throws at construction time (fail closed).
- Comparison is `hash_equals`. The header lookup is case-insensitive and accepts both plain and array header values (kept from 1.2).
- An optional IP allow-list or extra shared token can be added through middleware in the Laravel layer. It is not in the core.

Facts from the Webhook Notifications guide:

- The secret is a 32-character string generated by the gateway, sent as `X-Notification-Secret`, and **only to HTTPS URLs**. The gateway "mandates" HTTPS endpoints.
- The response must be a 2xx **within 2 seconds**. There are up to 20 attempts over 3 days, at intervals of 10 s, 30 s, 2 min, 5 min, 30 min, 4 h ×4, 8 h and 12 h ×4.
- Notifications are sent for:
  - Initiate Authentication, Authenticate Payer, Authorize/Pay, Capture (and Standalone Capture), Refund (and Standalone Refund), Update Authorization, Void, Verify, Referral and Disbursement;
  - Initiate Browser Payment and Confirm Browser Payment;
  - separately, Payment Alerts.
  So the handler must cope with non-payment notifications (decided, §12 Q18: every type is verified and deduped, and a generic `NotificationReceived` event fires in 2.0; Payment Alerts are modelled in 2.x).
- A notification is sent "in the version used to submit the transaction request". The parser must therefore tolerate any API version the host has ever used.
- `order.notificationUrl` overrides the URL configured in Merchant Administration. Later transactions use the URL they specify, or the last one used for the order.
- The gateway itself says webhooks are not for critical flows ("Instead, you can use the Retrieve Transaction API"). This confirms the design rule that a notification is only a trigger for `verifyCapture()`.

### 5.2 Dedupe and idempotency

- `Notification::$id` comes from `X-Notification-Id` and is required: if it is missing, `MalformedNotification` is thrown. `$attempt` comes from `X-Notification-Attempt`.
- Core interface:

  ```php
  interface NotificationStore {
      /** Atomically record the id; false if it was already recorded. */
      public function claim(string $notificationId, string $orderId): bool;
      public function markProcessed(string $notificationId, NotificationOutcome $outcome): void;
  }
  ```

- Two levels of idempotency:
  1. **Notification level:** dedupe on the notification ID. The gateway retries for up to 3 days.
  2. **Business level:** dedupe on `(orderId, transactionId)` of the capture. A second notification about the same transaction never produces a second `PaymentCaptured`. This also covers the return URL and the webhook racing each other.
- The core ships an `InMemoryNotificationStore` for tests. The Laravel layer ships a cache-lock or database store (§6.4).

---

## 6. Laravel 13 layer (optional)

It is auto-discovered through `extra.laravel.providers`. The core works without it, and `illuminate/*` is listed under `suggest` and `require-dev` only (decided, §12 Q2).

### 6.1 Config (`config/mib-global-pay.php`) and env keys

| Key | Env | Notes |
|---|---|---|
| `host` | `MIB_GATEWAY_URL` | Same name ISIMS already uses |
| `merchant_id` | `MIB_MERCHANT_ID` | |
| `api_password` | `MIB_API_PASSWORD` | |
| `api_version` | `MIB_API_VERSION` (default 100) | |
| `webhook.secret` | `MIB_WEBHOOK_SECRET`, falling back to `MIB_WEBHOOK_SIGNATURE` | Fallback eases the ISIMS migration; it is deprecated |
| `webhook.enabled` | `MIB_WEBHOOK_ENABLED` (default true) | |
| `webhook.path` | `MIB_WEBHOOK_PATH` (default `mib/webhook`) | |
| `webhook.domain` | `MIB_WEBHOOK_DOMAIN` (default none) | **Decided:** route domain support, e.g. ISIMS's `students.<domain>` with path `payment-gateways/mib/webhook` |
| `webhook.register_route` | `MIB_WEBHOOK_REGISTER_ROUTE` (default `true`) | **Proposed (open):** set false to register the route yourself (§6.2) |
| `webhook.queue` / `webhook.connection` | | Notifications are processed on a queue |
| `checkout_js_url` | `MIB_CHECKOUT_JS_URL` | For the optional Blade redirect component |
| `timeout` | `MIB_TIMEOUT` (default 30) | |
| `currency` | `MIB_CURRENCY` (default MVR) | Default currency for new checkouts; must be in `currencies` |
| `notification_store` | | `database` (default) or `cache` |
| `logging.channel` | `MIB_LOG_CHANNEL` | PSR-3; request and response logging with secrets redacted |
| `environment` | `MIB_ENVIRONMENT` (`test` / `production`) | Preset host; `host` overrides it (Q6) |
| `currencies` | `MIB_CURRENCIES` (default `MVR`) | Allow-list over the ISO `Currency` enum (Q5) |
| `order_prefix` | `MIB_ORDER_PREFIX` | Prefix for attempt order IDs (§6.5) |
| `approval.accept_approved_auto` | `MIB_ACCEPT_APPROVED_AUTO` (default `false`) | Q15 |
| `double_capture.auto_reverse` | `MIB_AUTO_REVERSE_DOUBLE_CAPTURE` (default `false`) | Enables the `AutoReverseDoubleCapture` listener (Q19) |
| `allow_insecure_urls` | `MIB_ALLOW_INSECURE_URLS` (default `false`) | Local development only (Q7) |

The provider binds `MibGateway` as a singleton and the `MibGlobalPay` facade. It also offers a `<x-mib-global-pay::checkout :session="$id" />` Blade component that renders the Checkout.js redirect page.

### 6.2 Webhook route, middleware and controller

- `POST /{webhook.path}` on the optional `{webhook.domain}`, named `mib-global-pay.webhook`, exempt from CSRF and with no session middleware. Domain support is **decided** (ISIMS keeps `students.<domain>/payment-gateways/mib/webhook`, configured at MIB, and also sends it per order as `order.notificationUrl`).
- **Proposed host-registered route (exact API open).** With `webhook.register_route = false`, the provider registers nothing, and the host places the route in its own route file and domain group:
  ```php
  Route::domain('students.' . config('app.domain'))->group(function () {
      Route::mibGlobalPayWebhook('payment-gateways/mib/webhook'); // macro: POST, controller, middleware, route name
  });
  ```
  The macro returns the `Illuminate\Routing\Route`, so hosts can chain `->name()` or `->middleware()`. Alternatives: a facade method `MibGlobalPay::webhookRoute($uri)`, or only the auto-registered route with domain and path config.
- `VerifyNotificationSecret` middleware returns 401 if the secret check fails, and 400 for a malformed notification.
- `WebhookController`:
  1. claims the notification ID; a duplicate gets a 200 response and nothing else happens;
  2. dispatches `ProcessNotification` on the configured queue;
  3. returns **200 within the gateway's 2-second budget**.
- `ProcessNotification` job:
  1. dispatches `NotificationReceived` (raw, for audit);
  2. ignores non-money-in types (refund or void notifications dispatch their own events, §6.3);
  3. calls `PayableResolver::resolveAttempt($orderId)`, which works for any attempt of the payable (§6.5);
  4. calls `verifyCapture($orderId, $payable->expectedAmount())`, which re-reads the order from the gateway;
  5. **takes the host's lock** (`$payable->lock()`; see §6.5) and re-checks payability inside the lock;
  6. dispatches exactly one outcome event (§6.3);
  7. marks the notification as processed.
- `PaymentOutcome::resolveFromReturn($orderId, $resultIndicator)` runs the same pipeline for the return URL. ISIMS calls one service from both paths, and the business-level dedupe in §5.2 makes the race harmless.
- `php artisan mib:reconcile {--since=}` runs the pipeline for payables the host reports as "awaiting gateway" (`PayableResolver::awaitingGateway()`). It can be scheduled, and it covers lost webhooks and the frozen-session policy.

### 6.3 Events

All events are `final readonly` classes. Their exact payload properties are listed in §6.7 D.

| Event | When |
|---|---|
| `NotificationReceived` | Every verified, non-duplicate notification |
| `PaymentCaptured` | Verified `Captured` **and** the payable is still payable, after re-checking inside the lock |
| `PaymentCapturedForUnpayableOrder` | Verified `Captured`, but the host says the payable was cancelled or failed. This is the "captured after cancel" case. The host listener decides whether to re-link or flag it for finance (§7). |
| `CaptureMismatch` | `AmountMismatch` or `CurrencyMismatch`. Never auto-paid. |
| `PaymentPending` | `Pending`. The host keeps the payment frozen, and reconciliation retries. |
| `PaymentFailed` | `NotCaptured` after a final gateway status (declined, failed, cancelled) |
| `PaymentReversed` | `Reversed` (refund or void notifications, or verification after a reversal) |
| `UnknownOrderNotification` | `PayableResolver` returned null (no such local payment) |
| `RefundProcessed` / `VoidProcessed` | Lifecycle notifications for those operations |

The package **never** marks anything paid. It only fires events. The host's listeners own all model changes.

### 6.4 Notification store (Laravel)

- Default: a `database` store with the publishable migration `mib_global_pay_notifications`.
  - Columns: `notification_id` (unique), `order_id`, `transaction_id`, `attempt`, `outcome`, `processed_at` and a raw payload, which is optional and can be pruned.
  - A unique index on `(order_id, transaction_id, outcome)` gives the business-level dedupe.
  - This is the package's only table. It stores no host data.
- Alternative: a `cache` store using `Cache::lock` / `Cache::add`, for hosts that don't want the table. Its dedupe window is limited by the TTL, which must be set to 4 days or more.
- Pruning: a `MassPrunable` model, or `mib:prune-notifications --days=30`.

### 6.5 Host contract

> Where §6.5 and §6.7 differ, **§6.7 is authoritative**. It holds the exact signatures, columns, events and config handed to ISIMS.

```php
namespace IbnNajjaar\MIBGlobalPay\Laravel\Contracts;

**Attempt model (decided): one payable, many attempts.**

- A host payable (ISIMS: `InvoicePayment`) keeps its frozen invoices and amount.
- It owns one or more **attempts**. Each attempt is one Initiate Checkout with its own gateway `order.id`.
- A capture on *any* attempt settles that payable.

```php
namespace IbnNajjaar\MIBGlobalPay\Laravel\Contracts;

interface PayableResolver
{
    /**
     * Map ANY attempt's gateway order id to its payable and attempt,
     * or null if the order id is unknown to the host.
     */
    public function resolveAttempt(string $gatewayOrderId): ?ResolvedAttempt; // {Payable $payable, Attempt $attempt}

    /** Attempts that are live or unresolved (no final gateway status yet), for mib:reconcile. */
    public function awaitingGateway(): iterable; // iterable<ResolvedAttempt>
}

interface Payable
{
    /** Host's stable id (ISIMS: InvoicePayment id / receipt_no). Sent as order.reference on every attempt. */
    public function payableReference(): string;

    /** The amount frozen when the payable was frozen, NOT a recomputed invoice total. Same for every attempt. */
    public function expectedAmount(): Money;

    public function payableState(): PayableState; // enum: Open, Frozen, Cancelled, Failed, Paid

    /** The attempt that settled this payable, if any (gateway order id + transaction id). */
    public function settledBy(): ?Settlement;

    /** @return iterable<Attempt> all attempts, newest first */
    public function attempts(): iterable;

    /** Run $callback while holding the host's row lock on the payable (e.g. lockForUpdate in a transaction). */
    public function lock(\Closure $callback): mixed;
}

interface Attempt
{
    public function gatewayOrderId(): string;
    public function sessionId(): ?string;
    public function successIndicator(): ?string;
    public function state(): AttemptState; // enum: Created, Live, Captured, Failed, Expired, Superseded
}
```

#### Attempt order IDs

- Generated by the package: `GatewayOrderId::forAttempt(string $prefix, string $payableReference, int $attemptNumber)`. This produces `{prefix}-{sanitised reference}-{n}` (sanitising rules in §6.7 C). It falls back to `{prefix}-{base32(random 10 bytes)}` only if the result is still over 40 characters or the sanitised reference is empty. The unsanitised reference always travels in `order.reference`.
  - The prefix is configurable (`MIB_ORDER_PREFIX`), so test and production, or several apps on one merchant, never collide.
  - Hosts may supply their own generator by binding `OrderIdGenerator`.
- **Uniqueness is the host's guarantee.** The `attempts.gateway_order_id` column is UNIQUE, and an order ID is never reused, even after failure or expiry. The docs back this: "you must create a new order ID before submitting another transaction for the same order" after a failed initial transaction.
- **Sequence:**
  1. Create the attempt row (state `Created`) **before** calling Initiate Checkout.
  2. Store `sessionId`, `successIndicator` and `sessionVersion` on that row.
  3. Set the row to `Live`.
  - If Initiate Checkout fails, the row becomes `Failed` and the order ID is burned.
- **Starting a new attempt** is allowed only while the payable is `Open` or `Frozen`, and all of these hold:
  - no attempt is `Captured`;
  - every earlier attempt has reached a final state through `verifyCapture()`, **or** has passed `interaction.timeout` plus grace and been verified `NotCaptured`;
  - earlier attempts are then marked `Superseded`.
  - The package helper `AttemptGuard::canStartNewAttempt(Payable, MibGateway)` runs these checks inside `lock()`.

#### Outcomes on capture (inside `lock()`, after `verifyCapture` against `expectedAmount()`)

| Payable state | Attempt captured | Event |
|---|---|---|
| `Open` / `Frozen`, no settlement | any attempt of this payable | `PaymentCaptured` (host marks paid, records `settledBy`) |
| `Paid`, `settledBy` = same order + transaction | same | none (dedupe) |
| `Paid`, `settledBy` = a **different** attempt or transaction | second attempt captured | `CaptureMismatch` with `reason: DoubleCapture`, carrying both attempts. Never auto-paid. The host flags it for refund, and `RefundAdvice` names the order and transaction to refund. |
| `Cancelled` / `Failed` | any | `PaymentCapturedForUnpayableOrder` (Policy A) |
| any | amount or currency differs | `CaptureMismatch` with `reason: AmountMismatch` / `CurrencyMismatch` |
| no payable found | — | `UnknownOrderNotification` |

- **Double-capture refund flow.**
  1. The package never refunds automatically.
  2. A listener, or finance through admin, calls `$gateway->refund($secondOrderId, TransactionId::generate('refund'), $captured)`. A void is tried first, while `void` is still possible, through `ReversalHelper::reverse($orderId)`. It tries VOID and falls back to REFUND, and returns which one it used.
  3. `RefundProcessed` or `VoidProcessed` follows.
  - **Decided (Q19):** `CaptureMismatch(reason: DoubleCapture)` always fires. The package also ships an optional `AutoReverseDoubleCapture` listener (void, falling back to refund) that is **off by default** (`MIB_AUTO_REVERSE_DOUBLE_CAPTURE=false`). With it off, the host or finance reverses manually.
- `CaptureMismatch` and `PaymentCapturedForUnpayableOrder` always carry the `VerificationResult`, so the host can show exact amounts.
- The binding and the full interface signatures are in §6.7. The resolver is bound by the host in a service provider, `$this->app->bind(PayableResolver::class, IsimsPayableResolver::class)`. If it is unbound, the webhook route stays disabled and an exception is thrown with a clear message.

#### Attempts table ISIMS will need (described here, built in ISIMS)

`invoice_payment_attempts` (name is ISIMS's choice):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `invoice_payment_id` | FK → invoice_payments, indexed | the payable |
| `attempt_number` | unsigned int | unique together with `invoice_payment_id` |
| `gateway_order_id` | varchar(40) **UNIQUE** | the `order.id` sent to MIB; never reused |
| `session_id` | varchar(35) nullable | from Initiate Checkout |
| `success_indicator` | varchar(32) nullable | compared timing-safe on return |
| `session_version` | varchar(10) nullable | |
| `amount_minor`, `currency` | int, char(3) | copy of the frozen amount sent to MIB |
| `state` | enum or string | Created, Live, Captured, Failed, Expired, Superseded |
| `gateway_status`, `gateway_result`, `gateway_code` | nullable strings | last verified values |
| `captured_transaction_id` | varchar(40) nullable | set when this attempt captured |
| `expires_at` | timestamp | `created_at` + `interaction.timeout` (+ grace) |
| `last_verified_at` | timestamp nullable | for reconciliation |
| timestamps | | |

The payable (`invoice_payments`) gets `state`, `frozen_amount_minor`, `frozen_currency` and `settled_attempt_id` (nullable FK). The frozen invoice set belongs to the payable, not to the attempts, and is immutable while the payable is frozen (§6.7 A).

### 6.6 Testing fake

```php
MibGlobalPay::fake()
    ->checkoutSession('ORDER-1', sessionId: 'SESSION0001', successIndicator: 'abc')
    ->order('ORDER-1', OrderFactory::captured(Money::of('100.00', Currency::MVR)));

$this->postMibNotification(NotificationFactory::paymentCaptured('ORDER-1', '100.00'));

MibGlobalPay::assertCheckoutInitiated(fn (CheckoutRequest $r) => $r->orderId === 'ORDER-1');
Event::assertDispatched(PaymentCaptured::class);
```

- `MibGlobalPayFake` implements the same interface as `MibGateway` (a `Gateway` contract), so host code type-hints the contract.
- `OrderFactory` and `NotificationFactory` build realistic payloads, with fixtures taken from the sandbox.
- The `postMibNotification()` test helper signs the request with the configured secret.
- Assertions: `assertCheckoutInitiated`, `assertRefunded`, `assertVoided`, `assertNothingSent`.

### 6.7 ISIMS integration contract

This section is self-contained, so it can be handed to the ISIMS side as it stands.

- Status: **PROVISIONAL** until the v2.0 Laravel-layer stage is merged. Items marked **(open)** may still change.
- Namespaces:
  - core: `IbnNajjaar\MIBGlobalPay\Core\…`
  - Laravel: `IbnNajjaar\MIBGlobalPay\Laravel\…`

#### A. Database (built by ISIMS; the package ships no host tables)

**New table `invoice_payment_attempts`** (the name is ISIMS's choice; the package only uses the interfaces in §B)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `bigIncrements` | no | PK |
| `invoice_payment_id` | `foreignId` → `invoice_payments.id` | no | `restrictOnDelete` (never cascade-delete gateway history) |
| `attempt_number` | `unsignedInteger` | no | 1, 2, 3 … per payable |
| `gateway_order_id` | `string(40)` | no | the `order.id` sent to MIB, never reused |
| `session_id` | `string(35)` | yes | from Initiate Checkout |
| `success_indicator` | `string(32)` | yes | compared timing-safe on return |
| `session_version` | `string(10)` | yes | |
| `amount_minor` | `unsignedBigInteger` | no | the frozen amount sent to MIB, in minor units (laari) |
| `currency` | `char(3)` | no | e.g. `MVR` |
| `state` | `string(20)` | no | `AttemptState` value: created, live, captured, failed, expired, superseded |
| `gateway_status` | `string(40)` | yes | last verified `order.status` |
| `gateway_result` | `string(20)` | yes | last verified `result` |
| `gateway_code` | `string(40)` | yes | last verified `response.gatewayCode` |
| `captured_transaction_id` | `string(40)` | yes | set when this attempt captured |
| `captured_amount_minor` | `unsignedBigInteger` | yes | as verified |
| `expires_at` | `timestamp` | no | `created_at` + `interaction.timeout` + grace |
| `last_verified_at` | `timestamp` | yes | |
| `created_at`, `updated_at` | `timestamps` | | |

Indexes and constraints on `invoice_payment_attempts`:

- `UNIQUE (gateway_order_id)`
- `UNIQUE (invoice_payment_id, attempt_number)`
- `INDEX (state, expires_at)`, for `mib:reconcile`
- `INDEX (session_id)`

**New columns on `invoice_payments` (the payable)**

| Column | Type | Null | Notes |
|---|---|---|---|
| `state` | `string(20)` | no, default `open` | `PayableState` value: open, frozen, cancelled, failed, paid. **Decided:** hosts add this column even if they have a legacy status. They may keep that status in sync; ISIMS keeps its int status 1–4 plus a new 5 = `captured_after_cancel`. The package reads only `payableState()`. |
| `frozen_amount_minor` | `unsignedBigInteger` | yes | set when frozen; `expectedAmount()` returns this |
| `frozen_currency` | `char(3)` | yes | |
| `frozen_at` | `timestamp` | yes | |
| `settled_attempt_id` | `foreignId` → `invoice_payment_attempts.id` | yes | `UNIQUE`; set once, on `PaymentCaptured` |
| `settled_transaction_id` | `string(40)` | yes | |

**Frozen invoice set (host-neutral rule).** While a payable is `Frozen`, the host must keep its set of invoices **immutable**. No invoice may be added, removed or moved to another payable until the payable leaves `Frozen`, either because it was paid or because it was cancelled after a final, non-captured verification. The set belongs to the payable, not to the attempts.

- The package never reads invoices. It only relies on `expectedAmount()` and on the host honouring this rule inside `lock()`.
- **ISIMS's model, which is the case to design for:** ISIMS has **no invoice pivot**. Each invoice points to one payment through `invoices.invoice_payment_id`, and `invoice_payments.invoice_ids` (JSON) records the original list. With an FK on the invoice, moving an invoice to another payment overwrites the only live link. That is exactly how a second payment can "take" the invoices of a frozen one.
  - So ISIMS must refuse any change to `invoices.invoice_payment_id` while the owning payable is `Frozen`, and do the check under the payable's lock.
  - It should also treat `invoice_payments.invoice_ids` as the frozen snapshot, which Policy A ("all original invoices still unpaid") reads.
- How and where ISIMS stores the frozen set is an **ISIMS design choice (open)**. Options include the existing `invoice_ids` JSON as the snapshot plus a guard on the FK, a dedicated snapshot table, or a lock column on `invoices`.

`receipt_no` is sent as `order.reference` on every attempt. `transaction_id` is no longer the gateway order ID; that moves to `invoice_payment_attempts.gateway_order_id`.

#### B. Interfaces (implemented by ISIMS)

```php
declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Contracts;

use Closure;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Laravel\Enums\AttemptState;
use IbnNajjaar\MIBGlobalPay\Laravel\Enums\PayableState;
use IbnNajjaar\MIBGlobalPay\Laravel\Data\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Data\Settlement;

interface PayableResolver
{
    /** Any attempt's gateway order id → its payable + attempt; null if unknown to the host. */
    public function resolveAttempt(string $gatewayOrderId): ?ResolvedAttempt;

    /** Attempts in state Created/Live (or Expired without a final verified status), for mib:reconcile. */
    public function awaitingGateway(): iterable; // iterable<ResolvedAttempt>
}

interface Payable
{
    /** Stable host reference (ISIMS: receipt_no). Sent as order.reference. Max 40 chars. */
    public function payableReference(): string;

    /** Frozen amount; identical for every attempt. Never a recomputed invoice total. */
    public function expectedAmount(): Money;

    public function payableState(): PayableState;

    public function settledBy(): ?Settlement;

    /** @return iterable<Attempt> newest first */
    public function attempts(): iterable;

    /**
     * Run $callback holding the host's row lock on the payable
     * (ISIMS: DB::transaction + lockForUpdate on invoice_payments). Must re-read state inside.
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function lock(Closure $callback): mixed;
}

interface Attempt
{
    public function attemptNumber(): int;
    public function gatewayOrderId(): string;
    public function sessionId(): ?string;
    public function successIndicator(): ?string;
    public function amount(): Money;
    public function state(): AttemptState;
    public function expiresAt(): \DateTimeImmutable;
}
```

```php
namespace IbnNajjaar\MIBGlobalPay\Laravel\Enums;

enum PayableState: string { case Open = 'open'; case Frozen = 'frozen'; case Cancelled = 'cancelled'; case Failed = 'failed'; case Paid = 'paid'; }
enum AttemptState: string { case Created = 'created'; case Live = 'live'; case Captured = 'captured'; case Failed = 'failed'; case Expired = 'expired'; case Superseded = 'superseded'; }

namespace IbnNajjaar\MIBGlobalPay\Laravel\Data;

final readonly class ResolvedAttempt { public function __construct(public Payable $payable, public Attempt $attempt) {} }
final readonly class Settlement { public function __construct(public string $gatewayOrderId, public string $transactionId, public Money $amount, public \DateTimeImmutable $settledAt) {} }
```

Binding, in an ISIMS service provider: `$this->app->bind(PayableResolver::class, IsimsPayableResolver::class);`. While this is unbound, the webhook route is disabled.

#### C. Attempt order IDs and AttemptGuard

- **Format (decided, ISIMS feedback):** `{prefix}-{sanitise(payableReference)}-{attemptNumber}`. Example: receipt `(R)IUM/FN/2026/123`, attempt 2 → `ISIMS-R-IUM-FN-2026-123-2`.
  - `sanitise()` turns every character outside `[A-Za-z0-9._-]` into `-`, brackets included. It then collapses runs of `-` and trims `-` from both ends. (The brief said "drop brackets", but its own example keeps `R-IUM`, so brackets become `-` like any other disallowed character, matching the example.)
  - **Random fallback only** if the result is still over 40 characters, or the sanitised reference is empty: `{prefix}-{16 base32 chars}` (80 random bits).
  - The unsanitised reference (`(R)IUM/FN/2026/123`) is always sent as `order.reference`, which allows any characters, 1–40.
  - Collisions are possible in principle: two references that differ only in disallowed characters sanitise to the same string. The host's `UNIQUE(gateway_order_id)` catches this, and the generator must then use the random fallback (stage 4).
  - Generated by `Core\Support\GatewayOrderId::forAttempt(string $prefix, string $payableReference, int $attemptNumber): string`.
  - It can be replaced by binding `Core\Contracts\OrderIdGenerator`.
  - The prefix comes from `MIB_ORDER_PREFIX`, which is required, 1–10 characters. Use different prefixes for test and production. **Decided:** there is no default; the package throws a configuration error if the prefix is unset.
- **Uniqueness** is enforced by the host's `UNIQUE (gateway_order_id)`. An ID is never reused, even after failure or expiry.
- **Sequence:**
  1. Inside `lock()`, insert the attempt row with state `created`.
  2. Call `initiateCheckout`.
  3. On success, store `session_id`, `success_indicator` and `session_version`, and set state to `live`. On failure, set state to `failed`; that order ID is burned.
- **`AttemptGuard::canStartNewAttempt(Payable $p, MibGateway $g): GuardResult`** runs inside `lock()`. It allows a new attempt only when all of these hold:
  1. `payableState()` is `Open` or `Frozen`;
  2. `settledBy()` is null, and no attempt is in state `Captured`;
  3. every earlier attempt is either `Failed`, or has been verified by `verifyCapture()` with a final non-captured status, or is past `expiresAt()` and verified `NotCaptured`;
  4. if any earlier attempt verifies `Captured` or `Pending`, it refuses with the reason. A `Captured` result also triggers the normal capture pipeline.
  - When it allows, earlier `Live` or `Expired` attempts are marked `Superseded`.
  - The `GuardResult` carries `allowed: bool`, `reason: ?GuardRefusal`, and `nextAttemptNumber: int`.
- **Reuse rule:** while the newest attempt is `Live` and not expired, re-display its session instead of creating a new attempt. `retrieveSession()` can confirm the session's `order.id` and `order.amount`.

#### D. Events (`IbnNajjaar\MIBGlobalPay\Laravel\Events\*`, all `final readonly`)

Shared types used in the payloads:

- `Source` is an enum: `Webhook`, `ReturnUrl`, `Reconcile`, `Manual`.
- `VerificationResult` carries `status: VerificationStatus`, `expected: Money`, `captured: Money`, `refunded: Money`, `order: OrderResponse`.
- `Notification` is the verified webhook. It has `id`, `attempt` and `raw()`.

| Event | Properties |
|---|---|
| `NotificationReceived` | `Notification $notification` |
| `PaymentCaptured` | `string $gatewayOrderId, Payable $payable, Attempt $attempt, VerificationResult $verification, string $transactionId, Source $source, ?Notification $notification` |
| `PaymentCapturedForUnpayableOrder` | the same as `PaymentCaptured`, plus `PayableState $stateAtCapture` |
| `CaptureMismatch` | `string $gatewayOrderId, Payable $payable, Attempt $attempt, VerificationResult $verification, MismatchReason $reason /* AmountMismatch, CurrencyMismatch, DoubleCapture */, ?Settlement $existingSettlement /* set for DoubleCapture */, ?string $transactionId, Source $source, ?Notification $notification` |
| `PaymentPending` | `string $gatewayOrderId, Payable $payable, Attempt $attempt, VerificationResult $verification, Source $source, ?Notification $notification` |
| `PaymentFailed` | the same as `PaymentPending`, plus `?GatewayCode $gatewayCode` |
| `PaymentReversed` | the same as `PaymentPending`, plus `Money $reversedAmount` |
| `UnknownOrderNotification` | `string $gatewayOrderId, Notification $notification` |
| `RefundProcessed` / `VoidProcessed` | `string $gatewayOrderId, ?ResolvedAttempt $resolved, TransactionResponse $transaction, Source $source, ?Notification $notification` |

#### E. Outcome table (evaluated inside `Payable::lock()`, after `verifyCapture()` against `expectedAmount()`)

**Decided (ISIMS feedback):** `OrderResponse::$totalCaptured` is always `Money` in the order currency, and `OrderResponse::isTotalCapturedReported()` says whether the gateway sent `totalCapturedAmount`. If it is missing, `verifyCapture()` reports `AmountMismatch` (or `NotCaptured` when no approved money-in transaction exists either), **never** `Captured`.

| Verification | Payable state | Settlement | Event fired |
|---|---|---|---|
| `Captured` | `Open` / `Frozen` | none | `PaymentCaptured`. ISIMS marks the payable paid and sets `settled_attempt_id` / `settled_transaction_id`. |
| `Captured` | `Paid` | same order + transaction | nothing (idempotent) |
| `Captured` | `Paid` | different attempt or transaction | `CaptureMismatch(DoubleCapture)`. If `MIB_AUTO_REVERSE_DOUBLE_CAPTURE=true`, the `AutoReverseDoubleCapture` listener voids that capture, or refunds it if the void fails. |
| `Captured` | `Cancelled` / `Failed` | — | `PaymentCapturedForUnpayableOrder`. ISIMS re-links the original invoices only if they are all still unpaid; otherwise it flags the payable `captured_after_cancel`. |
| `AmountMismatch` / `CurrencyMismatch` | any | — | `CaptureMismatch(AmountMismatch / CurrencyMismatch)`, never auto-paid |
| `Pending` | any | — | `PaymentPending`; the payable stays frozen |
| `NotCaptured`, final | any | — | `PaymentFailed`. ISIMS may mark the attempt failed, and may cancel the payable only if no other attempt is live. |
| `Reversed` | any | — | `PaymentReversed` |
| `NotFound`, or the resolver returns null | — | — | `UnknownOrderNotification` |

#### F. Config and env keys (`config/mib-global-pay.php`)

| Key | Env | Default |
|---|---|---|
| `environment` | `MIB_ENVIRONMENT` | `test` |
| `host` | `MIB_GATEWAY_URL` | from `environment` |
| `merchant_id` | `MIB_MERCHANT_ID` | required |
| `api_password` | `MIB_API_PASSWORD` | required |
| `api_version` | `MIB_API_VERSION` | `100` |
| `timeout` | `MIB_TIMEOUT` | `30` |
| `currency` | `MIB_CURRENCY` | `MVR` |
| `currencies` | `MIB_CURRENCIES` (comma list) | `MVR` |
| `order_prefix` | `MIB_ORDER_PREFIX` | required, no default (decided) |
| `checkout_js_url` | `MIB_CHECKOUT_JS_URL` | from `host` |
| `webhook.enabled` | `MIB_WEBHOOK_ENABLED` | `true` |
| `webhook.secret` | `MIB_WEBHOOK_SECRET`, with `MIB_WEBHOOK_SIGNATURE` as a deprecated fallback | required when the webhook is enabled |
| `webhook.path` | `MIB_WEBHOOK_PATH` | `mib/webhook` |
| `webhook.domain` | `MIB_WEBHOOK_DOMAIN` | none (decided) |
| `webhook.register_route` | `MIB_WEBHOOK_REGISTER_ROUTE` | `true` **(open: exact API, §6.2)** |
| `webhook.queue` / `webhook.connection` | `MIB_WEBHOOK_QUEUE` / `MIB_WEBHOOK_CONNECTION` | app default. Env names are provisional **(open)**. |
| `notification_store` | `MIB_NOTIFICATION_STORE` | `database` |
| `approval.accept_approved_auto` | `MIB_ACCEPT_APPROVED_AUTO` | `false` |
| `double_capture.auto_reverse` | `MIB_AUTO_REVERSE_DOUBLE_CAPTURE` | `false` |
| `attempts.expiry_grace_seconds` | `MIB_ATTEMPT_GRACE_SECONDS` | `300` (decided) |
| `checkout.timeout_seconds` | `MIB_CHECKOUT_TIMEOUT` (600–1800, sent as `interaction.timeout`) | `1800` (decided) |
| `allow_insecure_urls` | `MIB_ALLOW_INSECURE_URLS` | `false` |
| `logging.channel` | `MIB_LOG_CHANNEL` | app default |


---

## 7. How ISIMS's two policies map onto v2

### Policy A: capture after cancel

A capture arriving for a cancelled or failed payment is re-linked and paid only if all the original invoices are still unpaid and the amount matches. Otherwise it is flagged as "captured after cancel" for finance.

1. The package verifies the secret, dedupes, re-reads the order and verifies `Captured` against `expectedAmount()`.
   - If the amount does not match, it fires `CaptureMismatch`, so the payment is never auto-paid.
2. `payableState()` is `Cancelled` or `Failed`, so the package fires `PaymentCapturedForUnpayableOrder` inside `Payable::lock()`.
3. The ISIMS listener checks whether all the original invoices are still unpaid:
   - if so, it re-links them and marks the payment paid;
   - if not, it flags the payment `captured_after_cancel` for finance.
   - The invoice list is ISIMS data. The package only guarantees that the amount was verified and that the lock is held.
4. Finance can then call `$gateway->refund()` or `$gateway->void()` (`VoidProcessed` / `RefundProcessed` follow).

### Policy B: frozen while live

A payment with a live checkout session is frozen until MIB gives a final status, and retries reuse it.

- **Doc constraint:** "If the initial transaction for an order fails … you must create a new order ID before submitting another transaction for the same order" (Getting Started › Orders). `order.id` "must be unique for every order you create" (Initiate Checkout).
  - Within one session, the gateway's own `interaction.retryAttemptCount` (1–3) handles card retries under the same order.
  - After a session fails or expires, a retry needs a **new gateway order ID**. Decided: "retries reuse it" means the *local payable* is reused, and each checkout attempt gets its own gateway order ID (§6.5, attempt model).
- **Doc constraint:** `interaction.timeout` is 600–1800 s and "best effort". There is no API to cancel a Hosted Checkout session; the gateway "automatically expires the session". A frozen payable therefore stays frozen until both of these are true: `interaction.timeout` plus a grace margin has passed, and `verifyCapture()` returns a final status.
- ISIMS sets `PayableState::Frozen` when `initiateCheckout` succeeds, and persists `sessionId`, `successIndicator` and `sessionVersion` in **dedicated columns, not merged meta**. This fixes the meta-overwrite loss.
- A retry reuses the frozen payable:
  - While the current attempt is `Live` and not expired, re-display the same session. `retrieveSession()` confirms that it still carries the expected `order.id` and `order.amount`.
  - Otherwise, start a new attempt through `AttemptGuard` (§6.5).
  - `updateSession()` is experimental (§14). It is not part of the retry flow until it is confirmed (Q4).
- The 30-minute auto-cancel becomes: "after N minutes, run `verifyCapture()`".
  - `Pending` or `NotCaptured` while the session is still valid: stay frozen.
  - Final `NotCaptured`: allow cancel.
  - `Captured`: pay.
  - `mib:reconcile` drives this check.
- Cancelling a frozen payment from other paths (a second payment taking the invoices, admin, course transfer) must go through the same verification first. The package exposes `verifyCapture()` for that, and the host enforces the rule.

---

## 8. What the host must do (documented as a checklist in the README)

1. **Never reuse an `order.id`** once a session has been created for it, even after a cancel. Each checkout attempt gets a fresh ID, stored in a UNIQUE `attempts.gateway_order_id` column (§6.5). ISIMS today sends `InvoicePayment.transaction_id`; that moves to the attempts table. Send the payable's reference (ISIMS `receipt_no`) as `order.reference` on every attempt.
2. **Freeze the payable** (amount, currency and its invoice set) while a session is live. While frozen, no invoice may be added, removed or moved to another payable (§6.7 A). `expectedAmount()` returns the frozen amount, never a recomputed total.
3. **Lock around every state change** to the payable: pay, cancel, re-link and invoice moves, using `lockForUpdate` inside a transaction. `Payable::lock()` is where the package hooks in.
4. **Persist the session data** (`sessionId`, `successIndicator`, `sessionVersion`, the order ID) in dedicated columns before redirecting.
5. **Set `notificationUrl`** on every checkout, and configure the same secret in the MIB merchant portal.
6. **Never cancel locally without asking the gateway** (`verifyCapture`) when a session exists.
7. **Never "force pay"** around the payable check. React to `PaymentCapturedForUnpayableOrder` instead.
8. Schedule `mib:reconcile`.

---

## 9. Other features (scope)

| Feature | v2 |
|---|---|
| PSR-3 logging with secret and card redaction | in |
| Typed exceptions: `GatewayException`, `TransportException`, `SecretMismatch`, `MalformedNotification`, `CurrencyMismatch`, `InvalidRequest` | in |
| Opt-in retry middleware for idempotent operations | in |
| Reconciliation command (Laravel) | in |
| Blade checkout component (Laravel) | in |
| Payment Links, Credential on File, tokenisation, wallets, … | see §14 inventory and §14.0 phasing |
| HTTPS-only URL enforcement | in, always; `allowInsecureUrls()` exists for local development only (decided, §12 Q7) |
| Configurable currency allow-list | in: ISO `Currency` enum plus config allow-list, default `[MVR]` (decided, §12 Q5) |
| Mandatory `merchantName` | in |

---

## 10. Breaking changes from 1.1 (the last released version)

1.2.0 is **not released**. Its work (lifecycle operations, `GatewayException`, the webhook secret check, the null-safe parsing) ships only as part of 2.0, rewritten to the v2 style. The changes below are therefore listed against **1.1.0**.

- PHP `^8.4` only. Guzzle 6 and `psr/http-message` v1 support are dropped. Guzzle 7 or any PSR-18 client works.
- `MIBGlobalPayConnector` is replaced by `Core\MibGateway` with a `GatewayConfig`. The constructor and method names change:
  - `createTransaction` becomes `initiateCheckout`;
  - `getOrderDetails` becomes `retrieveOrder`;
  - `getTransactionDetails` becomes `retrieveTransaction`.
- Methods return typed DTOs directly. The `Response` wrapper and `toDto()` are removed, and `->raw()` is kept on every DTO.
- `OrderData` / `BaseOrderData` are replaced by an immutable `CheckoutRequest`. The fluent `set*()` methods become `with*()`.
- All amounts are `Money`. Float getters such as `getOrderAmount(): ?float` are removed.
- The string-constant classes in `Support\Constants` are replaced by backed enums, and getters return enums, not lowercase strings.
- Approval is decided by an explicit allow-list (§3.2). Code that relied on prefix matching behaves differently.
- Removed:
  - `paymentIsSuccessful()`, `isPaid()`, `paymentSuccessfullyCaptured()` and `hasPaidTransaction()`, replaced by `verifyCapture()` / `CaptureVerifier`;
  - `WebhookResponseData::fromGlobals`, `fromJson` and `fromArray`, replaced by `NotificationVerifier::verify()`. The secret is mandatory.
- `notificationUrl` is required by default on checkout.
- A missing `X-Notification-Id` header is now an error.

## 11. Upgrade path

The path is **1.1 → 2.0 directly**. No 1.2 or other 1.x release is planned (decided), and 1.1.0 stays the last 1.x tag.

1. Hosts on 1.1 stay there until they migrate. There is no 1.x maintenance branch. If a 1.1 security fix is ever needed, a `1.x` branch is cut from v1.1.0 (4688a70) on demand (decided, §12 Q20).
2. Write `UPGRADE.md` with a **1.1 → 2.0** method mapping table, covering the 1.1 methods only (`createTransaction`, `getOrderDetails`, `OrderData`, and the Checkout/Order/Webhook/HostedCheckoutReturn DTOs). It also gets a section that replaces the ISIMS adapter.
   - The 1.2-only APIs (`refund`, `void`, `capture`, `GatewayException`, `verifySecret`, …) were never released, so they need no migration notes.
3. Recommended ISIMS migration order:
   1. add the attempts table and the payable's state columns (§6.5);
   2. implement `PayableResolver` / `Payable` / `Attempt`;
   3. switch the webhook to the package route;
   4. listen to the events;
   5. remove the force-pay override;
   6. schedule reconciliation.
4. No Rector set; `UPGRADE.md` is the only migration aid (decided, §12 Q8).

---

## 12. Decisions and open questions

All rows are decided except Q4 and Q16, which wait on MIB.

**Questions for MIB** (ready to send; numbering is referenced from the code):
1. Which API operations are enabled on our test and production merchant profiles?
2. Can Payment Links (`checkoutMode=PAYMENT_LINK`) be enabled?
3. Does Update Session work on a live Hosted Checkout session (e.g. to change `order.amount`)?
4. What is a Hosted Checkout session's default lifetime, and does it expire at `interaction.timeout`?
5. Can `APPROVED_AUTO` occur for our profile, and does it mean funds moved?
6. Can `PARTIALLY_APPROVED` occur on our acquirer link?
7. Are tokenization, Credential on File, stored credentials and MIT available?
8. Are 3-D Secure, Apple Pay and Google Pay enabled in our Hosted Checkout?
9. Are payment plans, Browser Payments, wallets, risk assessment, surcharging or DCC available?
10. Is the Reporting API available, and how do we get its credentials?
11. Is the Notifications service enabled, which notification types will we receive, and are `X-Notification-Secret` and `X-Notification-Id` always sent to HTTPS URLs?
12. Which currencies are enabled (MVR only, or MVR and USD)?
13. Is certificate (mutual TLS) authentication available, and on which host?
14. What is the production gateway host name?
15. Which API versions are supported, and is v100 recommended for production?
16. **(new)** Which characters are actually accepted in `order.id` and `order.reference`? The reference says "any characters", 1–40. Are `/`, `(`, `)`, spaces and non-ASCII safe in both, including in the acquirer messages?

| # | Question | Options / decision | Recommendation |
|---|---|---|---|
| Q1 | Root namespace | **Decided:** keep `IbnNajjaar\MIBGlobalPay` | — |
| Q2 | Should `illuminate/*` be a hard requirement? | **Decided:** `illuminate/*` in `suggest` (and `require-dev`) only | — |
| Q3 | Approved gateway codes | The v100 docs list the codes (§3.2). The allow-list is APPROVED and APPROVED_PENDING_SETTLEMENT. | Settled by the docs, apart from Q15 |
| Q4 | Retrieve/Update Session on a Hosted Checkout session, and session lifetime | Both operations exist in v100 (`GET/PUT …/session/{id}`), and the Retrieve Session response carries `order.id`, `order.amount` and `interaction.operation`. However, the HCO guide says "you have no need for the Hosted Session-related API operations", and no session lifetime is documented, only `interaction.timeout` (600–1800 s). | Ship `retrieveSession` (useful to check what a live session will charge). Ship `updateSession` marked experimental until it is tested in the sandbox or MIB confirms it works on HCO sessions. |
| Q5 | Currency set | **Decided:** ISO currency enum plus config allow-list, default `[MVR]` | — |
| Q6 | Environment presets (test/prod hosts) | **Decided:** include Test/Production presets; host is overridable | — |
| Q7 | HTTPS-only URLs | **Decided:** HTTPS always, `allowInsecureUrls()` for local use | — |
| Q8 | Rector upgrade rules | **Decided:** no Rector; `UPGRADE.md` only | — |
| Q9 | Code style tool | **Decided:** Laravel Pint | — |
| Q10 | Database dedupe migration in the package | **Decided:** optional, publishable dedupe migration (cache store as an alternative) | — |
| Q11 | Where does this design doc live? | **Decided:** the tracked `DESIGN-v2.md` at the repo root | — |
| Q12 | Branch for v2 work | **Decided:** develop v2 on `main`, composer `branch-alias` `dev-main → 2.x-dev` (added in the skeleton commit). `main` was pushed on 2026-10-28 at 2554273. | — |
| Q13 | Phasing | **Decided:** see §14.0 | — |
| Q14 | Attempt model | **Decided:** one payable, many attempts (§6.5) | — |
| Q15 | `APPROVED_AUTO` ("approved by the gateway, not submitted to the acquirer") | **Decided:** not approved by default; config opt-in flag `MIB_ACCEPT_APPROVED_AUTO` | — |
| Q16 | Features that need MIB to enable them on the merchant profile (§14 "Availability") | Ask MIB for the profile's enabled operations before fixing the 2.0 scope | Ask MIB. The docs say: "To check which operations are available for you to use, contact your payment service provider." |
| Q17 | Payment Links | **Decided:** Payment Links in 2.x, behind "requires MIB enablement" | — |
| Q18 | Webhook types that are not order transactions (Payment Alerts, Authentication) | **Decided:** verify and dedupe every type, with a generic `NotificationReceived` in 2.0; Payment Alerts in 2.x | — |
| Q19 | Double-capture remediation (a second attempt captured after the payable was settled) | **Decided:** always fire `CaptureMismatch(DoubleCapture)`; optional `AutoReverseDoubleCapture` listener (void, then refund), off by default | — |
| Q20 | 1.x maintenance after the 1.2 work was folded into v2 | **Decided:** no 1.x branch; cut one from v1.1.0 on demand | — |
| Q21 | CHANGELOG lists `[1.2.0] - 2026-10-16` as released, and that text is now on the pushed `main` | **Decided:** fixed in the v2 skeleton commit (`[Unreleased]`, compare `v1.1.0...HEAD`; it becomes `[2.0.0]` at release) | — |

---

## 13. Tooling

- **Tests:** Pest 4 on PHPUnit 12. Orchestra Testbench 11 (for Laravel 13) runs `tests/Laravel`, and `tests/Core` never boots Laravel.
  - Gateway payload fixtures live in `tests/Fixtures`, taken from the (unreleased) 1.2 fixtures and sandbox captures.
  - Architecture tests (`arch()`):
    - `Core` must not use `Illuminate`;
    - all DTOs are `readonly`;
    - every file declares `strict_types`;
    - no `float` in public signatures under `Core\Money` or `Core\Responses`.
- **Static analysis:** PHPStan 2 at level max, plus Larastan for the Laravel layer.
- **Style:** Laravel Pint (decided, §12 Q9). It replaces php-cs-fixer when the tooling is swapped.
- **CI matrix:** PHP 8.4 and 8.5 × Laravel 13 (`prefer-stable` and `prefer-lowest`). A separate core-only job runs without `illuminate/*` installed. Jobs: Pest with coverage, PHPStan and style.
- Remove from the 1.x-era tooling: the PHP 7.2–8.3 jobs, the Guzzle 6 lowest-dependency job, the `phpunit.xml` `<whitelist>` schema, and the Docker image pinned to old PHP (update it to 8.4).
- `composer.json`:
  - `"php": "^8.4"`, `"guzzlehttp/guzzle": "^7.9"` (or `psr/http-client` plus a discovery package);
  - `extra.laravel.providers`;
  - `branch-alias` `dev-main → 2.x-dev`.

---

## 14. Gateway feature inventory (from the MIB v100 docs)

**Availability legend.**

- **Doc** means the operation is in the MIB v100 API reference, or the feature is in the MIB integration guides.
- **MIB?** means the docs say the feature must be enabled on the merchant profile, or depends on the acquirer, so only MIB can confirm it.
- **Unlikely** means it is probably not enabled for a Maldivian Hosted Checkout merchant (judgement, unverified).

Every operation "must first have it enabled on your merchant profile by your payment service provider" (HCO Integration Steps), so strictly *all* rows depend on MIB. The column records the extra dependencies the docs call out.

The "Phase" column follows the decision in §14.0.

### 14.0 Phasing (decided)

**2.0.0** covers:

- the Hosted Checkout lifecycle: Initiate Checkout (WEBSITE) with all interaction options, the Checkout.js helper and component, Retrieve Order, Retrieve Transaction, Capture, Refund, Void and Update Authorization;
- Retrieve Session;
- Update Session, **marked experimental**;
- Check Gateway;
- Payment Options Inquiry;
- capture verification (`verifyCapture`, the attempt model, `AttemptGuard`);
- webhooks (mandatory secret, dedupe, every notification type accepted);
- the Laravel layer.

**2.x** covers every other row in §14, each shipped after MIB confirms the operation is enabled for the merchant (§12, MIB questions). The §16 architecture (one `Operation` class per API operation, the public `send()`, sub-clients) must accommodate the entire inventory from 2.0 onwards. Until a 2.x feature has a typed method, a host can already reach any operation through `send()`.

### 14.1 Hosted Checkout and sessions

| Feature | API operation (v100) | v1 | Availability | Proposed v2 API | Dependencies / risks | Phase |
|---|---|---|---|---|---|---|
| Initiate Checkout (`checkoutMode=WEBSITE`) | `POST /session`, apiOperation `INITIATE_CHECKOUT` (v63+; `CREATE_CHECKOUT_SESSION` before v62) | yes (`createTransaction`) | Doc | `MibGateway::initiateCheckout(CheckoutRequest): CheckoutSession` | `successIndicator` (16–32 chars) and `session.version` (10 chars, for optimistic locking) must be persisted. `interaction.timeout` 600–1800 s and `timeoutUrl`. | 2.0 |
| Interaction options: operation PURCHASE / AUTHORIZE / VERIFY / NONE, returnUrl, cancelUrl, redirectMerchantUrl and retryAttemptCount (1–3, v63+), timeout and timeoutUrl, style (accentColor, theme), displayControl, locale, merchant block | same | partial (no timeout, style or displayControl beyond CSC) | Doc | `CheckoutRequest::with*()` | `NONE` stores card data in the session without paying. | 2.0 |
| Payment Links | `INITIATE_CHECKOUT` with `checkoutMode=PAYMENT_LINK`, `paymentLink.{expiryDateTime, numberOfAllowedAttempts, errorUrl}` | no | **MIB?** The MIB reference's `checkoutMode` request enum lists only `WEBSITE` (Q17). | `initiatePaymentLink(PaymentLinkRequest): PaymentLink` (url, id, expiry, successIndicator) | Defaults: expiry 3 months, 25 attempts. Error codes on `errorUrl`: EXPIRED, PAID, USAGE_COUNT_EXCEEDED, DOES_NOT_EXIST, UNEXPECTED_ERROR, DELETED. | 2.x |
| Delete Payment Link | `DELETE /link/{linkId}` | no | Doc / MIB? | `deletePaymentLink(string $linkId)` | Not in the Hosted Checkout nav. The page exists by direct link only. | 2.x (with links) |
| Retrieve Session | `GET /session/{sessionId}` | no | Doc | `retrieveSession(string): SessionDetails` | The response includes `order.id`, `order.amount`, `interaction.operation` and `session.updateStatus`. Useful for confirming what a live session will charge. | 2.0 |
| Update Session | `PUT /session/{sessionId}` | no | Doc, but the HCO guide says Hosted Session ops are "not needed" for HCO (Q4) | `updateSession(string, SessionUpdate): SessionDetails` | Support for changing amount or order on a live HCO session is unconfirmed. Use `session.version` for optimistic locking. | 2.0, experimental |
| Create Session (Hosted Session / session authentication) | `POST /session` (no apiOperation) | no | Doc | `createSession(): Session` | Belongs to the Hosted Session (card-fields) integration and to 3DS session authentication. Not needed for HCO. | 2.x |
| Embedded page / payment page / modal (Checkout.js) | client-side `Checkout.configure({session})`, `showPaymentPage()` / `showEmbeddedPage()` | README snippet only | Doc | Laravel Blade component `<x-mib-global-pay::checkout>`. Core: a `CheckoutJs::scriptUrl($host)` helper. | From v67, `configure()` accepts only `session`; everything else goes in Initiate Checkout. | 2.0 |
| Payment plans / instalments on HCO | `constraints.paymentPlans.*` on Initiate Checkout; `Payment Plan: Plan Offers Inquiry` | no | **MIB?** "agree the available payment plans with your acquirer". Unlikely. | `CheckoutRequest::withPaymentPlanConstraints()`, `planOffersInquiry()` | | 2.x |
| Non-eCommerce source (MOTO, call centre) | `transaction.source` | yes | MIB? (must be on the merchant-acquirer link) | `CheckoutRequest::withTransactionSource(TransactionSource)` | Payer-present options are disabled when the source is not INTERNET. | 2.0 |

### 14.2 Order and transaction operations (`/order/{orderId}/transaction/{transactionId}`)

| Feature | API operation (v100) | v1 | Availability | Proposed v2 API | Dependencies / risks | Phase |
|---|---|---|---|---|---|---|
| Retrieve Order | `GET /order/{orderId}` | yes | Doc | `retrieveOrder(string): OrderResponse` | The source of truth for `verifyCapture`. | 2.0 |
| Retrieve Transaction | `GET /order/{id}/transaction/{txnId}` | no (unreleased 1.2 work, ships in 2.0) | Doc | `retrieveTransaction()` | The webhook guide recommends it over relying on webhooks. | 2.0 |
| Pay (server-side, with token or session) | `PUT …` `PAY` | no | Doc | `pay(PayRequest)` | Needs a token, session or card source. Used with CoF / MIT. Raw card data is PCI scope, so not supported. | 2.x (with tokens) |
| Authorize (server-side) | `PUT …` `AUTHORIZE` | no (HCO only) | Doc | `authorize(AuthorizeRequest)` | As Pay. | 2.x |
| Capture | `CAPTURE` | no (unreleased 1.2 work, ships in 2.0) | Doc | `capture(orderId, txnId, Money)` | Partial captures are allowed, and status becomes PARTIALLY_CAPTURED. | 2.0 |
| Refund | `REFUND` | no (unreleased 1.2 work, ships in 2.0) | Doc | `refund(orderId, txnId, Money, ?targetTxnId)` | Full or partial, repeatable. | 2.0 |
| Void | `VOID` with `transaction.targetTransactionId` | no (unreleased 1.2 work, ships in 2.0) | Doc | `void(orderId, txnId, targetTxnId)` | "Typically voids will only be successful when processed not long after the original transaction." This is the only way to "cancel" with the bank after money has moved. There is **no** API to cancel an unpaid HCO session. | 2.0 |
| Update Authorization | `UPDATE_AUTHORIZATION` | no (unreleased 1.2 work, ships in 2.0) | Doc | `updateAuthorization()` | | 2.0 |
| Verify (account verification) | `VERIFY` (also as HCO `interaction.operation`) | HCO only | Doc / MIB? | `verify(VerifyRequest)` | | 2.x |
| Referral | `REFERRAL` (with the issuer's authorization code) | no | Doc / MIB? | `referral()` | Rare manual flow. | 2.x |
| Disbursement | `DISBURSEMENT` | no | Doc. **Unlikely** (payouts). | `disburse()` | Money out. Needs strong host safeguards. | 2.x |
| Balance Inquiry | `BALANCE_INQUIRY` | no | Doc. **Unlikely** (gift cards). | `balanceInquiry()` | | 2.x |
| Update Application Transaction Counter | `PUT …` | no | Doc. **Unlikely** (card-present / EMV). | none (generic `send()` only) | | not planned; available through §16 |
| Standalone Capture / Refund | mentioned in Transactions guide | no | "not enabled by default due to risk of fraud". **Unlikely.** | `standaloneRefund()` | | 2.x |
| Batch: Close Batch, and Hosted Batch | `Batch: Close Batch`; Hosted Batch guide | no | Doc / MIB? | `closeBatch()`; Hosted Batch out of scope (file upload) | | 2.x / out |

### 14.3 Tokenization, Credential on File and agreements

| Feature | API operation (v100) | v1 | Availability | Proposed v2 API | Dependencies / risks | Phase |
|---|---|---|---|---|---|---|
| CoF in HCO (save card) | `interaction.saveCardForCredentialOnFile=PAYER_INITIATED_PAYMENTS`, `interaction.tokens[]` | no | **MIB?** Needs "Tokenization, Stored credential transactions, MIT"; otherwise "save card requests do not generate a token". | `CheckoutRequest::withSaveCard()`, `withTokens([...])`; `OrderResponse::tokens()` | Not supported for PayPak, Verve, EFTPOS, Jaywan or RuPay. The host must store `token.id`. | 2.x |
| Tokens | `Tokenization:` Create or Update Token (PUT and POST), Delete Token, Retrieve Token, Search, Generate Payment Data, Create or Update Browser Payment Token | no | Doc / MIB? | `TokenClient` sub-API: `$gateway->tokens()->create()/retrieve()/delete()/search()` | Behaviour depends on the token repository strategy. | 2.x |
| Agreements / recurring / MIT | `agreement.{id,type,expiryDate,amountVariability,minimumDaysBetweenPayments}` on checkout; `Agreement: Retrieve Agreement`; `sourceOfFunds.provided.card.storedOnFile`; `storedCredential.initiator` | no | **MIB?** | `AgreementClient::retrieve()`; `CheckoutRequest::withAgreement(Agreement)`; `pay()` with `StoredCredential` | Needs a completed CIT first. | 2.x |
| Create Payment Instrument Reference Id | `Gateway:` operation | no | Doc / MIB? | `tokens()->createInstrumentReference()` | | 2.x |

### 14.4 Authentication, risk and payment methods

| Feature | API operation (v100) | v1 | Availability | Proposed v2 API | Dependencies / risks | Phase |
|---|---|---|---|---|---|---|
| 3DS inside HCO | automatic. `interaction.action.3DSecure` = BYPASS / MANDATORY / USE_GATEWAY_RECOMMENDATION | partial (setter) | Doc / MIB? ("if your merchant profile is configured for it") | `CheckoutRequest::withThreeDSecure(ThreeDSecureAction)` | Adds an `AUTHENTICATION` transaction to the order and a separate webhook. `verifyCapture` must ignore it. | 2.0 |
| 3DS API (Initiate Authentication, Authenticate Payer) | `Authentication:` operations | no | Doc | `AuthenticationClient` | Direct / Hosted Session flows only. Not needed for HCO. | 2.x |
| Standalone risk assessment | `Risk: Create or Update Risk Assessment` | no | MIB? Unlikely. | `RiskClient` | | 2.x |
| Browser payments (PayPal, …) | `Browser Payment:` Initiate, Update, Confirm | no | Doc / MIB? Unlikely in MV. | `BrowserPaymentClient` | Can be offered inside HCO without a server change. Notifications exist. | 2.x |
| Wallets (Click to Pay, Masterpass-style) | `Wallet:` Open Wallet, Pair With Wallet, Retrieve Wallet Options, Retrieve Wallet Pairing Result, Update Session From Wallet | no | Doc / MIB? | `WalletClient` | | 2.x |
| Apple Pay / Google Pay in HCO | config only (v72+). Apple Pay in full-page redirect only. | no | **MIB?** "must be enabled on the merchant profile by your acquirer" | none (HCO handles it); `TransactionResponse::walletProvider()` getter | Apple Pay: Mastercard domain, DPAN only. | 2.0 (getter only) |
| Payment Options Inquiry | `Gateway: Payment Options Inquiry` | no | Doc | `paymentOptionsInquiry()` | Shows which payment types and currencies the profile supports. Useful for confirming Q16 at runtime. | 2.0 |
| Check Gateway | `GET /api/rest/version/{v}/information` (no auth) | no | Doc | `checkGateway(): GatewayStatus` | | 2.0 |
| Surcharging, Dynamic Currency Conversion, Level II/III, airline, cruise and healthcare data, line items, statement descriptor, custom data | request fields on checkout and transactions | `custom` only | MIB? Mostly unlikely. | Typed sub-objects added over time; `withExtraFields()` escape hatch kept | | 2.x |
| Reporting API (CSV transaction reports) | separate Reporting API with its own password | no | **MIB?** ("must be enabled for the Reporting service"); 12 months of data; no certificate auth | `ReportingClient` (separate credentials) | | 2.x |
| Payment Alerts (retry-likely notifications) | webhook | no | MIB? | `Notification` subtype `PaymentAlert` | Arrives on the same webhook endpoint (Q18). | 2.x |
| Webhook notifications | Merchant Admin config, `order.notificationUrl` | partial (parse plus opt-in secret) | Doc. "enable the Notifications service" (MIB?) | §5 / §6.2 | | 2.0 |
| Certificate (mutual TLS) authentication | — | no | Doc. Uses a different host name. | `GatewayConfig::withClientCertificate()` | | 2.x |
| Mobile SDKs (Android, iOS, Flutter, React Native) | client side | no | Doc | out of scope (server package) | | out |

---

## 15. API version

- The MIB reference offers **REST-JSON v100 ("latest")**, plus 85 down to 1. Versions 86–99 are not listed. The reference notes: "From API version 100, integrations must be able to handle non-breaking changes. Any API References for deprecated versions are hidden."
- The package's v1 default is already `100` (`MIBGlobalPayConnector.php:22`).
- **Recommendation: v2 defaults to 100 and pins it.** The version is configurable through `MIB_API_VERSION`, but the package is tested against 100 only.
- Because v100 promises additive changes, every DTO **ignores unknown fields**, and every enum has an `Unknown` fallback (§2). Feature minimums noted in the docs: Initiate Checkout v63+, redirect/retry v63+, `configure()` limited to `session` from v67, Apple/Google Pay v72+, session authentication v55+.
- Webhooks arrive in the API version used to submit the transaction (§5.1). The parser must accept ≥ v63 payloads.

## 16. Architecture for full coverage

Covering everything in §14 without one huge class:

- **`Operation` abstraction.** Each API operation is one `final readonly class` implementing `Operation`, with `method()`, `path()`, `body()`, `responseClass()` and `idempotent()`. Adding a feature means adding an Operation class and a DTO; nothing else changes.
- **A generic call** is a public, supported API: `MibGateway::send(Operation $op): Response` (the DTO). Hosts can therefore use any operation before a convenience method exists, including the rare ones in §14.
- **Sub-clients by API group**, matching the reference's groups:
  - `$gateway->checkout()` (Initiate Checkout, payment links)
  - `->sessions()`
  - `->orders()` (Retrieve Order and Transaction, and the transaction operations)
  - `->tokens()`
  - `->agreements()`
  - `->authentication()`
  - `->browserPayments()`
  - `->wallets()`
  - `->risk()`
  - `->paymentPlans()`
  - `->gateway()` (Check Gateway, Payment Options Inquiry)
  - `->reporting()` (separate credentials)

  The common operations stay as shortcuts on `MibGateway` (`initiateCheckout`, `retrieveOrder`, `refund`, `void`, `verifyCapture`).
- **Payload composition.** The request bodies are large: Initiate Checkout alone has hundreds of fields. Shared sub-objects (`Order`, `Interaction`, `Customer`, `Billing`, `Shipping`, `SourceOfFunds`, `Agreement`, `StoredCredential`, `Airline`, …) are separate readonly value objects, reused across operations. `withExtraFields()` remains for anything not modelled yet.
- **Capabilities.** An optional `Capabilities` config lists the operations MIB has enabled. When it is set, calls to disabled operations throw `OperationNotEnabled` locally instead of getting a gateway rejection. It can be filled from `paymentOptionsInquiry()` where that applies.
- **Notifications** are a sealed hierarchy built by `NotificationVerifier`: `TransactionNotification` (with an order and transaction), `AuthenticationNotification`, `PaymentAlertNotification` and `UnknownNotification`. Laravel events map onto them.
- **Laravel layer** binds `MibGateway` plus the sub-clients. The fake implements the same `Gateway` contract and records every `Operation` it receives, so assertions work for every feature.

## 17. Documentation sources read (2026-10-28)

All of these came from `https://test-mib.mtf.gateway.mastercard.com/api/documentation/`, as static HTML, read only. No gateway API call was made.

- **Hosted Checkout integration guides:** Integration Model, Integration Steps, Establishing a Session, Implementing the Hosted Payment Page, Interpreting the Response, Subsequent Operations, Features, Credential on File for HCO, Payment Enhancements, Customize Redirect and Retry, Apple Pay and Google Pay in HCO, Payment Plans, Non-eCommerce, Go-Live Checklist.
- **Supported features:** Webhook Notifications, Payment Links, Stored Credentials, 3DS with HCO, CIT/MIT, Reporting API, Surcharging, DCC, Payment Alerts.
- **API reference guides:** Authentication Options, Making a Server API Request, Error Handling.
- **Getting started:** Orders, Transactions, Identifiers, Order and Transaction Concepts.
- **API reference v100 operation pages:**
  - Hosted Checkout: Initiate Checkout and Delete Payment Link.
  - Session: Create, Retrieve and Update Session.
  - Transaction: Retrieve Order, Void and Verify (Authorize and Pay used for the group navigation).
  - Gateway: Check Gateway.
  - Agreement: Retrieve Agreement.
  - Tokenization: Create or Update Token.
  - The landing pages of the Authentication, Batch, Browser Payment, Payment Plan, Risk and Wallet groups, used for the operation lists.

**Not read in detail.** I have the operation names for these, but did not read their field-level pages:

- Authentication, Browser Payment, Wallet, Risk, Payment Plan, Batch;
- the remaining Tokenization operations, Payment Options Inquiry and Create Payment Instrument Reference Id;
- Disbursement, Referral, Balance Inquiry, Update Application Transaction Counter;
- the Checkout.js SDK reference.

**Rendering limit.** The API reference's left-hand navigation is built per group. Each group's operation list was taken from that group's own page. The Hosted Checkout group lists only Initiate Checkout, although Delete Payment Link exists by direct URL. If MIB hides other operations, they are not in this inventory.
