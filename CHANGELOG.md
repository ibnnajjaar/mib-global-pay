# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning (SemVer).

- Keep a Changelog: https://keepachangelog.com/en/1.1.0/
- Semantic Versioning: https://semver.org/spec/v2.0.0.html

## [Unreleased]

### Fixed
- The package now accepts Guzzle 8 (`guzzlehttp/guzzle` `^7.9 || ^8.0`). A fresh Laravel 13 app ships Guzzle 8, so installing the beta there needed `--with-all-dependencies`, which downgraded Guzzle, `guzzlehttp/promises` and `guzzlehttp/psr7` a major version. The install command no longer needs the flag.
- `CaptureVerifier::isTransactionNotFound()` now accepts the MIB test gateway's unknown-transaction response, HTTP 400 "Unable to find order X and transaction Y for merchant M". It was rejected before, so `ReversalHelper` threw instead of treating an absent void as absent and going on to the refund checks. `isOrderNotFound()` no longer accepts that text, because it names a transaction.
- Payment links now always send `paymentLink.numberOfAllowedAttempts`, 25 (`CheckoutRequest::DEFAULT_PAYMENT_LINK_ATTEMPTS`) when no count is given. The MIB gateway rejects a link without it (HTTP 400 "Missing parameter"), so `startPaymentLink($invoice, now()->addDays(7))`, as shown in the README, always failed.

## [2.0.0-beta.1] - 2026-11-08
This is a beta, not a finished 2.0.0. No authenticated sandbox flow has been run against the gateway, and MIB has not answered our open questions, so some gateway behaviour is still unverified. The list is at the top of [DESIGN-v2.md](https://github.com/ibnnajjaar/mib-global-pay/blob/main/DESIGN-v2.md).

Version 2 is a rewrite; see [UPGRADE.md](./UPGRADE.md). Install it with the command in the [README](./README.md#installation).

### Removed
- **Breaking:** the whole 1.x API: `MIBGlobalPayConnector`, `OrderData` / `BaseOrderData`, the `Requests\*` and `Responses\*` classes (`CheckoutSessionResponseData`, `OrderResponseData`, `TransactionResponseData`, `WebhookResponseData`, `HostedCheckoutReturnData`), `Support\*` (`Connector`, `Request`, `Response`, `Amount`, `Arr`, `Path`, `TransactionId`, the string-constant classes), `Contracts\IsOrderData` / `IsResponseData`, and the 1.x `Exceptions\MIBGlobalPayException` / `GatewayException`. Their replacements are in `IbnNajjaar\MIBGlobalPay\Core` and `\Laravel`; see UPGRADE.md.
- The Docker development setup (`Dockerfile`, `docker-compose.yml`, `.dockerignore`).

### Changed
- `verifyCapture()` now reports `Authorized`, `Verified` and `PartiallyCaptured` instead of `NotCaptured` or `AmountMismatch` for these flows, and the outcome pipeline dispatches `PaymentAuthorized`, `AccountVerified` and `PaymentPartiallyCaptured`. `AttemptGuard` refuses a new attempt with `AuthorizationHeld` while an authorization holds funds. `AttemptState` gains `Authorized` and `Verified`.
- `Gateway` gains `tokens()`, `paymentLinks()` and `agreements()`, so custom implementations need them.
- Double-capture auto-reversal is now on by default and needs a queue worker. Turn it off with `MIB_AUTO_REVERSE_DOUBLE_CAPTURE=false`.
- The pipeline dispatches nothing for a retired attempt (`Superseded` or `Failed`) unless money moved. `AttemptState::Failed` can now move to `Captured`, for a late charge.
- Laravel binds a default `PayableResolver` unless `MIB_MANAGED_ATTEMPTS=false`, so the webhook no longer answers 503 when you have not bound one.
- `GatewayConfig` rejects API versions below 67.
- The merchant logo URL must use HTTPS even when `allow_insecure_urls` is on, because the gateway requires it.
- The checkout component shows the payer a message when Checkout.js reports an error or fails to load. It no longer sets an empty `cancel` callback, so the gateway's own cancel behaviour applies unless you pass one.
- **Breaking:** requires PHP `^8.4`. PHP 7.2 to 8.3 are no longer supported.
- **Breaking:** requires `guzzlehttp/guzzle` `^7.9` and `psr/http-message` `^2.0`. Guzzle 6 and `psr/http-message` 1.x are no longer supported.
- `illuminate/support` (`^12.0 || ^13.0`) is suggested for the optional Laravel 12 and 13 layer. It is not required.
- **Breaking:** `composer.json` now has a `conflict` on `laravel/framework` `<12.0 || >=14.0`, so Laravel 12 and 13 install and Composer refuses any other Laravel version, including for core-only use. Apps without `laravel/framework` are not affected, including ones using `illuminate/*` packages on their own. See DESIGN-v2.md §12 Q22.
- `composer.json`: branch alias `dev-main` → `2.x-dev`.
- Composer dist installs now leave out the tests, CI, tooling configs, `backlog/`, `WORKFLOW.md` and `DESIGN-v2.md` (new `.gitattributes`). They stay in a source checkout. Links to them in the shipped Markdown are now absolute URLs.
- Tooling: the test suite runs on Pest 4 (PHPUnit 12) with Orchestra Testbench. Laravel Pint replaces php-cs-fixer and is checked in CI. PHPStan runs at level max over all of `src`, with Larastan. Rector is added as a development tool, checked in CI, with a config chosen for this package (`rector.php`). CI tests PHP 8.4 and 8.5, each on Laravel 12 and Laravel 13.

### Added
- `CheckoutRequest::withDisplayControl()` with the `FieldDisplay`, `ShippingDisplay` and `PaymentTermsDisplay` enums, for the billing address, customer email, shipping and payment terms.
- `CheckoutRequest::forVerification()`, for a `VERIFY` checkout. It is the only one that may send a zero amount.
- `CheckoutRequest::operation()`.
- The checkout component takes `mode="embedded"` or `mode="modal"`, and `error`, `cancel`, `complete`, `timeout`, `before-redirect` and `after-redirect` callback props.
- `CheckoutJs`, which renders all the Checkout.js for the Payment Page, Embedded Page and modal, for any PHP app.
- A ready-made Laravel integration: the `HasMibPayments` trait, the `CheckoutAttempt` model and table, `EloquentPayableResolver`, `MibGlobalPay::startCheckout()` and `startPaymentLink()`, and routes for the payment page, the payer's return and payment link errors.
- `PaymentNeedsReview`, fired once when a payment stays pending or timed out past `MIB_REVIEW_AFTER_SECONDS` (24 hours by default), and `MibGlobalPay::confirmUnpaid()`, which lets a person release the payer after asking the acquirer.
- `mib:reconcile` keeps checking superseded and confirmed-unpaid attempts for `MIB_LATE_CAPTURE_WATCH_SECONDS` (7 days by default), so a late charge is found even if its webhook is lost.
- Payment Links: `withPaymentLink()`, `createPaymentLink()` and `deletePaymentLink()`.
- Credential on File: `withSaveCard()`, `withTokens()`, `withAgreement()`, `withStoredOnFile()`, `OrderResponse::token()`, and the `tokens()` and `agreements()` clients.
- Server-side `pay()`, `authorize()`, `verifyAccount()` and `referral()`, `planOffers()`, and updated order data on `capture()`.
- `withCustomer()`, `withBillingAddress()`, `withShipping()`, `withLineItems()`, `withStatementDescriptor()`, `withOrderDetails()`, `withAirlineData()`, `withCruiseData()` and `withPaymentPlanConstraints()`.
- An MIT `LICENSE` file, included in the package archive.
- v2 core types under `IbnNajjaar\MIBGlobalPay\Core`: `Money` (minor units, explicit rounding, no float paths), the `Currency` enum (curated ISO 4217 subset), the gateway enums (`OrderStatus`, `TransactionType`, `Result`, `GatewayCode`, `GatewayRecommendation`) with an `Unrecognized` fallback, `ApprovalPolicy`, the null-safe `Payload` reader, `GatewayConfig` with environment presets, and the exception hierarchy (`MibGlobalPayException` marker interface).

- v2 connector `Core\MibGateway` (PSR-18 transport, Guzzle 7 by default, redacted PSR-3 logging, opt-in retries for idempotent operations) with the 2.0 operations: Initiate Checkout, Retrieve Session, Update Session (experimental), Retrieve Order, Retrieve Transaction, Capture, Refund, Void, Update Authorization, Check Gateway and Payment Options Inquiry, plus typed readonly response DTOs. Requires `psr/http-client`, `psr/http-factory` and `psr/log`.

- v2 verification: `CaptureVerifier` and `MibGateway::verifyCapture()` (a missing `totalCapturedAmount` is a mismatch), `HostedCheckoutReturn` with a timing-safe indicator check, `GatewayOrderId` attempt order IDs with reference sanitising, `TransactionId` (`generate()`, and `derive()` for IDs that are stable across resends), and `ReversalHelper`: it voids, and refunds against that capture only after a definite decline, or an HTTP 400 where a read shows the void absent or definitely failed. Other 4xx responses are rethrown. A void or refund with an unknown result, including `UNSPECIFIED_FAILURE`, sends nothing further and returns `ReversalMethod::Unconfirmed`. Each call reads the order first and reports a void or refund already listed, so a resend cannot refund twice. `reverseWithDerivedIds()` derives the IDs from the order and capture (`ReversalIds`). This follows the MIB documentation and has not been observed on the live gateway.

- v2 attempts: host contracts `Payable`, `Attempt`, `PayableResolver`, `PayableState`, `AttemptState`, `ResolvedAttempt` and `Settlement` in `Core\Attempts`, and `AttemptGuard` (with `GuardResult` / `GuardRefusal`), which decides from `verifyCapture()` whether a new checkout attempt may start. Enum helpers `OrderStatus::isTerminalWithoutPayment()`, `TransactionType::movesMoneyIn()`, `isVoid()` and `reversesMoneyIn()`.

- v2 webhooks: `NotificationVerifier` with a mandatory secret (fails closed, timing-safe), the `Notification` types (`TransactionNotification`, `AuthenticationNotification`, `GenericNotification`), and `NotificationStore` with `InMemoryNotificationStore` (dedupe on notification ID, and on order ID plus transaction ID).

- Optional Laravel 12 and 13 layer (`IbnNajjaar\MIBGlobalPay\Laravel`, auto-discovered):
  - service provider, `config/mib-global-pay.php` and the `MibGlobalPay` facade;
  - the webhook route, plus `Route::mibGlobalPayWebhook($uri)` for hosts that register it themselves, with the `VerifyNotificationSecret` middleware, the controller and the queued `ProcessNotification` job;
  - the outcome pipeline and its events (`PaymentCaptured`, `CaptureMismatch`, `PaymentCapturedForUnpayableOrder`, `PaymentPending`, `PaymentFailed`, `PaymentReversed`, `UnknownOrderNotification`, `RefundProcessed`, `VoidProcessed`, `NotificationReceived`), and `PaymentOutcome` for the return URL;
  - database and cache notification stores, with a publishable migration;
  - the `mib:reconcile` and `mib:prune-notifications` commands;
  - the opt-in double-capture auto-reversal: the `ReverseCapture` job derives its transaction IDs from the order and capture, retries `Unconfirmed` and exceptions by itself (6 attempts, about 45 minutes), then logs an error and dispatches the new `ReversalUnconfirmed` event. A refund that definitely failed dispatches the new `ReversalFailed` event without retrying;
  - `ProcessNotification` retries by itself (5 attempts with a backoff of 30 s, 2, 5 and 10 min), marks a notification `Failed` only when it fails for good, and fails permanent errors on the first attempt;
  - money events (`PaymentCaptured`, `PaymentCapturedForUnpayableOrder`, `CaptureMismatch`, `PaymentReversed`, `RefundProcessed`, `VoidProcessed`) go out at least once until one dispatch completes, then not again, so a job retry or `mib:reconcile` sends them again after a listener failure. They carry a stable `deliveryKey`, and listeners must be idempotent on it. `NotificationStore` gains `wasDelivered()` and `markDelivered()`. Advisory events (`NotificationReceived`, `UnknownOrderNotification`, `PaymentPending`, `PaymentFailed`) go out at most once per notification. See the listener contract in the README;
  - the `<x-mib-global-pay::checkout>` component.

- Documentation: v2 README, UPGRADE.md (1.1 → 2.0), and DESIGN-v2.md §6.7 marked final, with the items that still depend on MIB listed.

- Testing support (`IbnNajjaar\MIBGlobalPay\Laravel\Testing`): `MibGlobalPay::fake()` returning `MibGlobalPayFake` (gateway-like defaults, `strict()`, `checkoutSession()`, sequenced `order()` stubs, `respondWith()`, and assertions), `OrderFactory`, `NotificationFactory`, and the `InteractsWithMibGlobalPay` trait with `postMibNotification()`.

### Fixed (before the beta)
- **A 404 or 400 from a wrong host or a proxy no longer reads as an unpaid order.** `CaptureVerifier::isOrderNotFound()` used to accept every HTTP 404, so an HTML page gave `NotFound`, which could let `AttemptGuard` start a new attempt while an earlier one held the money, and could dispatch `PaymentFailed`. `NotFound` now needs the documented error format (`result` `ERROR`, `error.cause` `INVALID_REQUEST`, no `error.field` or `error.validationType`), and then HTTP 404 with any wording, or HTTP 400 saying the order is missing. Other wording, such as "Merchant not found" or a 400 about another field, no longer counts. Anything else throws `GatewayException`, with a message naming the host and saying the response was not recognised as a gateway error. The rule is unverified against the gateway: see [DESIGN-v2.md](https://github.com/ibnnajjaar/mib-global-pay/blob/main/DESIGN-v2.md) §4.2.
- `ReversalHelper` sends no refund when the void's read gets a response like that, or when the void's own HTTP 400 is not in the error format. It reads the void with the new `CaptureVerifier::isTransactionNotFound()`.
- `ProcessNotification` retries a 4xx that is not in the error format instead of failing on the first attempt. A gateway 4xx still fails at once.
- `MibGlobalPayFake` answers an unknown order or transaction in the error format, and follows the same rule. A `GatewayException` a test stubs needs the JSON as its raw body to count as a gateway error.
- New public methods for the above: `GatewayException::isGatewayError()` and `CaptureVerifier::isTransactionNotFound()`.

### Fixed (stage 7.1, from the ISIMS adoption)
- `AttemptGuard` now verifies every attempt that may still be paid, including unexpired ones. An attempt the bank has already captured refuses with `MoneyCaptured`, and `MibGlobalPay::attemptGuard()` runs the capture pipeline for it (`Source::AttemptGuard`). A pending one refuses with `VerificationPending`. Only a live attempt the bank reports as unpaid is offered as `reusableAttempt`.
- `AttemptState` now allows `Created → Superseded`, so every attempt the guard returns in `attemptsToSupersede` can legally be superseded. It also allows `Live → Failed` and `Expired → Failed`, for an attempt the bank confirmed as finally unpaid.
- Explicit short index names, within MySQL's 64-character limit, in the package migration and in the host-table contract (DESIGN-v2.md §6.7 A).
- The webhook URL is found even when `Route::mibGlobalPayWebhook()` is registered in a name-prefixed or domain host group, with or without `route:cache`. Before this, after `route:cache` a prefixed route gave no URL, so `newCheckoutRequest()` had no notification URL and `CheckoutRequest::toBody()` refused it. The macro now marks the route in its action array, which the route cache keeps. With several such routes, the last one registered wins, cached or not.
- `DatabaseNotificationStore`: a duplicate claim inside a host transaction no longer aborts that transaction on PostgreSQL, where later queries failed and the commit was lost. Inside a transaction the insert now runs in a savepoint. Checked on real MySQL, PostgreSQL and SQLite, and CI now runs those tests against MySQL and PostgreSQL service containers.

### Included from the unreleased 1.2 work
Version 1.2.0 was never released. Its additions to the 1.x classes (`MIBGlobalPayConnector`, the `*ResponseData` DTOs, `OrderData`, `Support\*`) went away with those classes, so they are not listed here. The items below carried over to v2, on the new classes. They are compared with 1.1.0.

#### Added
- Lifecycle operations on `MibGateway`: `capture()`, `refund()`, `void()`, `updateAuthorization()` and `retrieveTransaction()`. All of them return `TransactionResponse`.
- `GatewayException` with the gateway error details: `httpStatus()`, `cause()`, `explanation()`, `field()`, `validationType()`, `supportCode()`, `rawBody()`, `error()`, `isClientError()`, `isServerError()` and `isAuthenticationError()`.
- Initiate Checkout options on `CheckoutRequest`: `withOperation()` (`PURCHASE`, `AUTHORIZE`, `VERIFY`, `NONE`), `withCustomField()`, `withThreeDSecure()`, `withCardSecurityCode()`, `withLocale()`, `withTransactionSource()`, `withTransactionReference()` and `withExtraFields()`.

#### Fixed
- The webhook notification secret was lost (or reduced to its first character) when headers came from `getallheaders()`. `NotificationVerifier` looks headers up case-insensitively and accepts both plain and array values.
- PHP warnings ("Undefined array key", "Trying to access array offset on null", `strtolower(null)`) on sparse webhook, order and transaction payloads, for example 3-D Secure `AUTHENTICATION` transactions without a `response`. A missing status or a `null` transaction list no longer fails either.
- Gateway error messages were swallowed. HTTP 4xx/5xx now throw `GatewayException` with the gateway's explanation as the message.
- Connection errors and timeouts are now reported once, as `TransportException` with the message `HTTP request failed: ...`.

#### Changed
- `order.amount` is sent as a decimal string (`"100.50"`), as the API specifies.
- `null` fields and empty objects are no longer sent in the Initiate Checkout payload. The hard-coded `"custom": null` is gone.
- Order and transaction IDs are URL-encoded in endpoint paths. Plain alphanumeric IDs produce the same URLs as before.
- Validation aligned with the gateway's limits. The gateway rejected these values anyway:
  - order ID is at most 40 characters
  - order description is at most 127 characters (was 255)
  - order reference is at most 40 characters (was 255)
  - merchant name is at most 40 characters
  - URLs with the `javascript` scheme are rejected

## [1.1.0] - 2025-08-20
### Added
- Order reference field support in `OrderData` and request payloads.
  - New setter: `setOrderReference(string $reference)`.
  - `CreateTransactionRequest` now includes `order.reference` when provided.
  - Tests updated to cover the new field and expected payload.

### Notes
- This is a backwards-compatible feature addition; thus, a MINOR version bump from 1.0.1 to 1.1.0 is appropriate per SemVer.

## [1.0.1] - 2025-07-13
- Previous patch release (prior to maintaining a formal changelog). Details may be found in Git history.

[2.0.0-beta.1]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.1.0...v2.0.0-beta.1
[1.1.0]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.0.0...v1.0.1
