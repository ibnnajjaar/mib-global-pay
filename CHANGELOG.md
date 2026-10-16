# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning (SemVer).

- Keep a Changelog: https://keepachangelog.com/en/1.1.0/
- Semantic Versioning: https://semver.org/spec/v2.0.0.html

## [1.2.0] - 2026-10-16
### Added
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

### Fixed
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

### Changed
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

### Deprecated
- `OrderResponseData::paymentSuccessfullyCaptured()`: it returns `false` once any refund is made. Use `isPaid()`.

### Removed
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

[1.2.0]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.0.0...v1.0.1
