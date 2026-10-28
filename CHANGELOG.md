# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning (SemVer).

- Keep a Changelog: https://keepachangelog.com/en/1.1.0/
- Semantic Versioning: https://semver.org/spec/v2.0.0.html

## [Unreleased]
Ready to be released as **2.0.0**: rename this heading to `## [2.0.0] - YYYY-MM-DD` on the day you tag, and update the link at the bottom. Version 2 is a rewrite; see [UPGRADE.md](./UPGRADE.md) and [DESIGN-v2.md](./DESIGN-v2.md). Version 1.2.0 was never released, so the changes listed under "Included from the unreleased 1.2 work" ship as part of 2.0.0.

### Removed
- **Breaking:** the whole 1.x API: `MIBGlobalPayConnector`, `OrderData` / `BaseOrderData`, the `Requests\*` and `Responses\*` classes (`CheckoutSessionResponseData`, `OrderResponseData`, `TransactionResponseData`, `WebhookResponseData`, `HostedCheckoutReturnData`), `Support\*` (`Connector`, `Request`, `Response`, `Amount`, `Arr`, `Path`, `TransactionId`, the string-constant classes), `Contracts\IsOrderData` / `IsResponseData`, and the 1.x `Exceptions\MIBGlobalPayException` / `GatewayException`. Their replacements are in `IbnNajjaar\MIBGlobalPay\Core` and `\Laravel`; see UPGRADE.md.

### Changed
- **Breaking:** requires PHP `^8.4`. PHP 7.2 to 8.3 are no longer supported.
- **Breaking:** requires `guzzlehttp/guzzle` `^7.9` and `psr/http-message` `^2.0`. Guzzle 6 and `psr/http-message` 1.x are no longer supported.
- `illuminate/support` (`^13.0`) is suggested for the optional Laravel 13 layer. It is not required.
- `composer.json`: branch alias `dev-main` → `2.x-dev`.
- Tooling: the test suite runs on Pest 4 (PHPUnit 12) with Orchestra Testbench. Laravel Pint replaces php-cs-fixer and is checked in CI. PHPStan runs at level max over all of `src`, with Larastan. CI and the Docker image use PHP 8.4, and CI tests PHP 8.4 and 8.5.

### Added
- v2 core types under `IbnNajjaar\MIBGlobalPay\Core`: `Money` (minor units, explicit rounding, no float paths), the `Currency` enum (curated ISO 4217 subset), the gateway enums (`OrderStatus`, `TransactionType`, `Result`, `GatewayCode`, `GatewayRecommendation`) with an `Unrecognized` fallback, `ApprovalPolicy`, the null-safe `Payload` reader, `GatewayConfig` with environment presets, and the exception hierarchy (`MibGlobalPayException` marker interface). The 1.x API is unchanged.

- v2 connector `Core\MibGateway` (PSR-18 transport, Guzzle 7 by default, redacted PSR-3 logging, opt-in retries for idempotent operations) with the 2.0 operations: Initiate Checkout, Retrieve Session, Update Session (experimental), Retrieve Order, Retrieve Transaction, Capture, Refund, Void, Update Authorization, Check Gateway and Payment Options Inquiry, plus typed readonly response DTOs. Requires `psr/http-client`, `psr/http-factory` and `psr/log`.

- v2 verification: `CaptureVerifier` and `MibGateway::verifyCapture()` (a missing `totalCapturedAmount` is a mismatch), `HostedCheckoutReturn` with a timing-safe indicator check, `GatewayOrderId` attempt order IDs with reference sanitising, `TransactionId`, and `ReversalHelper` (void, falling back to refund).

- v2 attempts: host contracts `Payable`, `Attempt`, `PayableResolver`, `PayableState`, `AttemptState`, `ResolvedAttempt` and `Settlement` in `Core\Attempts`, and `AttemptGuard` (with `GuardResult` / `GuardRefusal`), which decides from `verifyCapture()` whether a new checkout attempt may start. Enum helpers `OrderStatus::isTerminalWithoutPayment()`, `TransactionType::movesMoneyIn()`, `isVoid()` and `reversesMoneyIn()`.

- v2 webhooks: `NotificationVerifier` with a mandatory secret (fails closed, timing-safe), the `Notification` types (`TransactionNotification`, `AuthenticationNotification`, `GenericNotification`), and `NotificationStore` with `InMemoryNotificationStore` (dedupe on notification ID, and on order ID plus transaction ID).

- Optional Laravel 13 layer (`IbnNajjaar\MIBGlobalPay\Laravel`, auto-discovered):
  - service provider, `config/mib-global-pay.php` and the `MibGlobalPay` facade;
  - the webhook route, plus `Route::mibGlobalPayWebhook($uri)` for hosts that register it themselves, with the `VerifyNotificationSecret` middleware, the controller and the queued `ProcessNotification` job;
  - the outcome pipeline and its events (`PaymentCaptured`, `CaptureMismatch`, `PaymentCapturedForUnpayableOrder`, `PaymentPending`, `PaymentFailed`, `PaymentReversed`, `UnknownOrderNotification`, `RefundProcessed`, `VoidProcessed`, `NotificationReceived`), and `PaymentOutcome` for the return URL;
  - database and cache notification stores, with a publishable migration;
  - the `mib:reconcile` and `mib:prune-notifications` commands;
  - the opt-in double-capture auto-reversal;
  - the `<x-mib-global-pay::checkout>` component.

- Documentation: v2 README, UPGRADE.md (1.1 → 2.0), and DESIGN-v2.md §6.7 marked final, with the items that still depend on MIB listed.

- Testing support (`IbnNajjaar\MIBGlobalPay\Laravel\Testing`): `MibGlobalPay::fake()` returning `MibGlobalPayFake` (gateway-like defaults, `strict()`, `checkoutSession()`, sequenced `order()` stubs, `respondWith()`, and assertions), `OrderFactory`, `NotificationFactory`, and the `InteractsWithMibGlobalPay` trait with `postMibNotification()`.

### Fixed (stage 7.1, from the ISIMS adoption)
- `AttemptGuard` now verifies every attempt that may still be paid, including unexpired ones. An attempt the bank has already captured refuses with `MoneyCaptured`, and `MibGlobalPay::attemptGuard()` runs the capture pipeline for it (`Source::AttemptGuard`). A pending one refuses with `VerificationPending`. Only a live attempt the bank reports as unpaid is offered as `reusableAttempt`.
- `AttemptState` now allows `Created → Superseded`, so every attempt the guard returns in `attemptsToSupersede` can legally be superseded. It also allows `Live → Failed` and `Expired → Failed`, for an attempt the bank confirmed as finally unpaid.
- Explicit short index names, within MySQL's 64-character limit, in the package migration and in the host-table contract (DESIGN-v2.md §6.7 A).
- The webhook URL is found even when `Route::mibGlobalPayWebhook()` is registered in a name-prefixed or domain host group: the macro remembers the `Route` instance.

### Included from the unreleased 1.2 work
#### Added
- Lifecycle operations on `MIBGlobalPayConnector`: `refund()`, `void()`, `capture()`, `updateAuthorization()` and `getTransactionDetails()`, backed by `RefundRequest`, `VoidRequest`, `CaptureRequest`, `UpdateAuthorizationRequest` and `RetrieveTransactionRequest`. All of them return `TransactionResponseData`.
- `Support\TransactionId::generate()` / `validate()`, `Support\Amount::format()`, `Support\Path::segment()` and `Support\Arr`.
- Constants: `OrderStatus`, `TransactionResult`, `TransactionType` and `InteractionOperation` (in `Support\Constants`).
- `GatewayException` (extends `MIBGlobalPayException`) with the gateway error details: `getHttpStatus()`, `getErrorCause()`, `getErrorExplanation()`, `getErrorField()`, `getValidationType()`, `getSupportCode()`, `getRawBody()`, `getErrorData()`, `isClientError()`, `isServerError()` and `isAuthenticationError()`.
- `Connector::setHttpClient()` (for custom middleware and mocking) and `setTimeout()` / `getTimeout()`.
- `Response::getResult()`.
- `TransactionResponseData` getters: `getTransactionId()`, `getAmount()`, `getCurrency()`, `getTargetTransactionId()`, `getReference()`, `getReceipt()`, `getAuthorizationCode()`, `getAcquirerCode()`, `getTimeOfRecord()`, `getOrderId()` and `getOrderStatus()`. Helpers: `isSuccessful()`, `isPending()`, `isFailure()`, `isUnknown()`, `isApproved()`, `isPayment()`, `isRefund()`, `isCapture()`, `isAuthorization()` and `isVoid()`.
- `OrderResponseData` getters: `getOrderId()`, `getAmount()`, `getCurrency()`, `getTotalAuthorizedAmount()`, `getTotalRefundedAmount()`, `getReference()`, `getCreationTime()`, `getRefundableAmount()`, `getCapturableAmount()`, `getSuccessfulTransactions()`, `findTransaction()`, `getLatestTransactionOfType()` and `isPaid()`.
- Webhook helpers: `WebhookResponseData::fromJson()`, `fromGlobals()`, `verifySecret()`, `assertValidSecret()`, `getNotificationId()`, `getNotificationAttempt()`, `getTransactionId()`, `getTransactionAmount()`, `getTransactionCurrency()`, `getTargetTransactionId()`, `getTransaction()`, `isRefund()` and `isVoid()`.
- `HostedCheckoutReturnData::matchesSuccessIndicator()` (timing-safe), `getSessionVersion()` and `getOrderId()`.
- Initiate Checkout options on `OrderData`: `setInteractionOperation()` (`PURCHASE`, `AUTHORIZE`, `VERIFY`, `NONE`), `setCustomField()`, `setThreeDSecureAction()`, `setCardSecurityCodeDisplay()`, `setLocale()`, `setTransactionSource()`, `setTransactionReference()` and `setExtraFields()`, all also supported in `fromArray()`.
- CI job testing the lowest dependencies (Guzzle 6) on PHP 7.2.

#### Fixed
- The webhook notification secret was lost (or reduced to its first character) when headers came from `getallheaders()`. Header lookup is now case-insensitive and accepts both plain and array values.
- PHP warnings ("Undefined array key", "Trying to access array offset on null", `strtolower(null)`) on sparse webhook, order and transaction payloads, for example 3-D Secure `AUTHENTICATION` transactions without a `response`.
- `WebhookResponseData::getOrderStatus()` called `strtolower(null)` when the status was missing.
- `OrderResponseData::hasPaidTransaction()` failed when the transaction list was `null`.
- Gateway error messages were swallowed. HTTP 4xx/5xx now throw `GatewayException` with the gateway's explanation as the message.
- `MIBGlobalPayException`s thrown inside `send()` were wrapped twice.
- `Response::getBody()` returned an empty string on the second call.
- `Response::toDto()` caused a fatal error for requests without a response DTO.
- Guzzle 7 connection errors and timeouts are now reported as `HTTP request failed: ...`.
- `OrderData::fromArray()` ignored the `currency` key, and threw a `TypeError` instead of an `InvalidArgumentException` when `order_id` or `amount` was missing.
- `OrderResponseData`'s constructor declared `$total_captured_amount` as `?string` although it receives a float.
- `MIBGlobalPayException` triggered a deprecation on PHP 8.4 (implicitly nullable `$previous`).
- The `composer format` script pointed to Pint, which isn't installed. It now runs php-cs-fixer.

#### Changed
- `order.amount` is sent as a decimal string (`"100.50"`), as the API specifies.
- `null` fields and empty objects are no longer sent in the Initiate Checkout payload. The hard-coded `"custom": null` was removed.
- Order and transaction IDs are URL-encoded in endpoint paths (`GetTransactionRequest` included). Plain alphanumeric IDs produce the same URLs as before.
- Validation aligned with the gateway's limits. The gateway rejected these values anyway:
  - order ID is at most 40 characters
  - order description is at most 127 characters (was 255)
  - order reference is at most 40 characters (was 255)
  - merchant name is at most 40 characters
  - URLs with the `javascript` scheme are rejected
- Base URL, authentication, headers and timeout are now applied per request instead of on the Guzzle client. Requests are sent with `ClientInterface::request()`.
- `composer.json`: the PHP constraint is simplified to `^7.2|^8.0`, `psr/http-message` is now an explicit dependency, and `minimum-stability: dev` is removed.
- CI: actions/checkout and actions/cache are bumped to v4, and the Composer cache is now per PHP version.

#### Deprecated
- `OrderResponseData::paymentSuccessfullyCaptured()`: it returns `false` once any refund is made. Use `isPaid()`.

#### Removed
- Unused private helpers `MIBGlobalPayConnector::normalizeOrderData()` and `BaseOrderData::validateUrl()` (no public API change).

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

[Unreleased]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.0.0...v1.0.1
