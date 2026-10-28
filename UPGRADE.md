# Upgrading from 1.1 to 2.0

Version 2.0 is a rewrite for **PHP 8.4+** with an optional **Laravel 13** layer. 1.1.0 is the last
released 1.x version; the unreleased 1.2 work ships as part of 2.0. The full design and every
decision are in [DESIGN-v2.md](./DESIGN-v2.md).

> **The 1.x classes are removed in 2.0** (`MIBGlobalPayConnector`, `OrderData`, the `*ResponseData`
> DTOs, `MIBGlobalPayException`, ...). Migrate with the mapping below; nothing from 1.x remains.

## Requirements

| | 1.1 | 2.0 |
|---|---|---|
| PHP | ^7.2 \| ^8.0 | **^8.4** |
| Guzzle | ~6.0 \| ~7.0 | ^7.9 (or any PSR-18 client) |
| psr/http-message | ^1.0 \| ^2.0 | ^2.0 |
| New | | psr/http-client, psr/http-factory, psr/log ^3 |
| Laravel | none | optional, ^13 (`illuminate/support` is suggested, not required) |

## Method and class mapping

| 1.1 | 2.0 |
|---|---|
| `new MIBGlobalPayConnector($url, $merchantId, $password)` | `new MibGateway(new GatewayConfig(merchantId: …, apiPassword: …, orderPrefix: …, host: …))`, or `MibGlobalPay::gateway()` in Laravel |
| `$connector->createTransaction(OrderData)` | `$gateway->initiateCheckout(CheckoutRequest)` |
| `$connector->getOrderDetails($orderId)` | `$gateway->retrieveOrder($orderId)` — but to decide "paid", use `$gateway->verifyCapture($orderId, $expected)` |
| `OrderData::make($id, 100.50)` + `set*()` | `CheckoutRequest::create($id, Money::of('100.50', Currency::Mvr), $merchantName, $returnUrl)` + immutable `with*()` |
| `setOrderReference()` | `withReference()` (any characters, 1–40) |
| `setWebHookUrl()` | `withNotificationUrl()` — now **required** unless you call `withoutWebhook()` |
| `setOrderDescription()`, `setReturnUrl()`, `setCancelUrl()`, `setRedirectMerchantUrl()` + `setRetryAttemptCount()` | `withDescription()`, `create(..., $returnUrl)`, `withCancelUrl()`, `withRetryRedirect($url, $count)` |
| `setMerchantName()` etc. | `create(..., $merchantName, ...)` (required) and `withMerchantDetails(url:, email:, phone:, logoUrl:, addressLine1:)` |
| `$response->toDto()` | operations return typed DTOs directly (`CheckoutSession`, `OrderResponse`, `TransactionResponse`, …) |
| `CheckoutSessionResponseData::getSessionId()` / `getSuccessIndicator()` | `CheckoutSession::$sessionId` / `$successIndicator` (also `$sessionVersion`) |
| `OrderResponseData::getOrderStatus()` (lowercase string) | `OrderResponse::$status` (`OrderStatus` enum) |
| `OrderResponseData::getTotalCapturedAmount()` (float) | `OrderResponse::$totalCaptured` (`Money`); `isTotalCapturedReported()` |
| `OrderResponseData::paymentSuccessfullyCaptured()` / `hasPaidTransaction()` | **removed** — `verifyCapture($orderId, $expected)->isCapturedExactly()` |
| `HostedCheckoutReturnData::fromArray($_GET)` + `getResultIndicator() === $stored` | `HostedCheckoutReturn::fromQuery($query)->matchesSuccessIndicator($stored)` (timing-safe; a hint only — always verify) |
| `WebhookResponseData::fromArray(...)` + `paymentIsSuccessful()` | `(new NotificationVerifier($secret))->verify($body, $headers)` — the secret is **mandatory**; then `verifyCapture()`. In Laravel, use the package webhook route and listen to events. |
| `MIBGlobalPayException` | `Core\Exceptions\MibGlobalPayException` (marker interface); `GatewayException`, `TransportException`, `InvalidRequest`, `MalformedPayload`, … |
| Floats for amounts | `Money` (minor units; `Money::of('100.50', Currency::Mvr)`) |

## Behaviour changes to check

1. **Paid means verified.** Never mark a payment paid from the return URL or the webhook body.
   Call `verifyCapture()`, which re-reads the order from the gateway and compares the captured
   total with the amount you expected. A missing `totalCapturedAmount` is a mismatch.
2. **One approval rule.** Only `APPROVED` and `APPROVED_PENDING_SETTLEMENT` count; `APPROVED_AUTO`
   only with `MIB_ACCEPT_APPROVED_AUTO=true`. 1.x's prefix match on `approved*` is gone.
3. **Webhook secret is mandatory** and checked before the body is parsed; without a configured
   secret, verification fails closed.
4. **Never reuse an `order.id`.** Each checkout attempt gets a new gateway order ID
   (`{prefix}-{sanitised reference}-{n}`); keep your own reference in `order.reference`.
5. **Currencies** are an allow-list (default `MVR`); amounts must be positive and have at most
   the currency's decimals (`Money::ofRounded()` rounds explicitly).
6. **HTTPS only** for every URL you send, unless `allowInsecureUrls` is on (local development).

## Laravel hosts

1. Remove your own `MIBGlobalPayConnector` binding; the provider is auto-discovered.
2. Env: `MIB_GATEWAY_URL`, `MIB_MERCHANT_ID`, `MIB_API_PASSWORD`, **`MIB_ORDER_PREFIX`** (required),
   **`MIB_WEBHOOK_SECRET`** (`MIB_WEBHOOK_SIGNATURE` still works but is deprecated).
3. Publish and run the dedupe migration: `php artisan vendor:publish --tag=mib-global-pay-migrations`.
4. Implement `Core\Attempts\Payable`, `Attempt` and `PayableResolver`, and bind the resolver.
   The contract (tables, interfaces, events, outcome table) is DESIGN-v2.md §6.7.
5. Use the package webhook route (`MIB_WEBHOOK_PATH` / `MIB_WEBHOOK_DOMAIN`), or set
   `MIB_WEBHOOK_REGISTER_ROUTE=false` and call `Route::mibGlobalPayWebhook($uri)` in your route file.
6. Listen to `PaymentCaptured`, `PaymentCapturedForUnpayableOrder`, `CaptureMismatch`,
   `PaymentPending`, `PaymentFailed`, `PaymentReversed`; the package never changes your models.
7. Schedule `mib:reconcile` (e.g. every five minutes) and `mib:prune-notifications` (daily).
