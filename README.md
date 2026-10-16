# MIB Global Pay SDK

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ibnnajjaar/mib-global-pay.svg?style=flat-square)](https://packagist.org/packages/ibnnajjaar/mib-global-pay)
[![Tests](https://github.com/ibnnajjaar/mib-global-pay/workflows/Tests/badge.svg)](https://github.com/ibnnajjaar/mib-global-pay/actions)
![Code Coverage Badge](./.github/coverage.svg)
[![Total Downloads](https://img.shields.io/packagist/dt/ibnnajjaar/mib-global-pay.svg?style=flat-square)](https://packagist.org/packages/ibnnajjaar/mib-global-pay)

A Framework-agnostic PHP SDK for integrating with MIB Global Pay – enabling merchants to initiate payments and retrieve payment statuses using a clean, developer-friendly interface.

> This SDK targets the Hosted Checkout integration of the Mastercard Payment Gateway (MPGS).
>
> What is Hosted Checkout? Hosted Checkout is a gateway-managed, PCI-compliant checkout page that you redirect shoppers to for entering payment details. The gateway collects and processes sensitive card data on its own secure pages, then redirects the shopper back to your site with the payment result. This reduces your PCI scope and simplifies compliance while keeping your app focused on order creation and result handling.
>
> Further reading: See the official MPGS Hosted Checkout documentation:
> https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/hostedCheckout/integrationModelHostedCheckout.html?locale=en_US

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Setup & Configuration](#setup--configuration)
    - [Environment Setup](#environment-setup)
    - [Client Initialization](#client-initialization)
    - [Environment Variables (Recommended)](#environment-variables-recommended)
- [Implementation Guide](#implementation-guide)
    - [Create Payment](#create-payment)
    - [Redirect to MIB Checkout](#redirect-to-mib-checkout)
    - [Handle Payment Completion](#handle-payment-completion)
    - [Retrieve Payment Status](#retrieve-payment-status)
    - [Handling Webhook Data](#handling-webhook-data)
- [Payment Lifecycle Operations](#payment-lifecycle-operations)
    - [Transaction IDs and Retries](#transaction-ids-and-retries)
    - [Authorize and Capture](#authorize-and-capture)
    - [Refunds](#refunds)
    - [Cancelling (Void)](#cancelling-void)
    - [Update Authorization](#update-authorization)
    - [Retrieve a Transaction](#retrieve-a-transaction)
- [Extra Fields](#extra-fields)
- [Error Handling](#error-handling)
- [API Response Structures](#api-response-structures)
    - [Create Payment Response](#create-payment-response)
    - [Payment Status Response](#payment-status-response)
    - [Response Methods](#response-methods)
    - [Constants](#constants)
- [Security Considerations](#security-considerations)
    - [Credential Management](#credential-management)
    - [HTTPS Requirements](#https-requirements)
    - [Test Card Numbers](#test-card-numbers)
- [Testing](#testing)
    - [Mocking HTTP in your tests](#mocking-http-in-your-tests)
- [Contributing](#contributing)
- [Contributors](#contributors)
- [License](#license)

## Requirements

- PHP 7.2 or higher
- Composer

## Installation

Install the package using Composer:

```bash
composer require ibnnajjaar/mib-global-pay
```

## Setup & Configuration

### Environment Setup

Before using the SDK, obtain your Merchant ID and API Key from the MIB Merchant Portal.

**Environment (Sandbox or Production):**

```php
$merchant_portal_url = 'test-mib.mtf.gateway.mastercard.com';
$merchant_id = 'YOUR_SANDBOX_MERCHANT_ID';
$api_password = 'your-sandbox-api-password';
```

### Client Initialization

Initialize the client:

```php
use IbnNajjaar\MIBGlobalPay\MIBGlobalPayConnector;

$connector = new MIBGlobalPayConnector($merchant_portal_url, $merchant_id, $api_password);
```

### Environment Variables (Recommended)

Store credentials securely using environment variables:

```php
// .env file
MIB_MERCHANT_URL=test-mib.mtf.gateway.mastercard.com
MIB_MERCHANT_ID=your_merchant_id
MIB_API_PASSWORD=your_api_password

// In your code
$connector = new MIBGlobalPayConnector(
    $_ENV['MIB_MERCHANT_URL'],
    $_ENV['MIB_MERCHANT_ID'],
    $_ENV['MIB_API_PASSWORD']
);
```

**Note:** NEVER commit your env file to the repository.

## Implementation Guide

### Create Payment

To initiate a payment, first prepare the order data:

```php
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;

// Minimum required data
$order_id = 'order123'; // max 40 characters
$amount = 100.00;

$payment_details = OrderData::make($order_id, $amount);

// You can chain methods to set other information. All available methods are as follows:
$payment_details->setOrderCurrency('MVR')
                ->setOrderDescription('Test Order') // max 127 characters
                ->setOrderReference('INV-123') // max 40 characters
                ->setMerchantAddressLine1('Sample, Majeedhee Magu')
                ->setMerchantEmail('merchant@example.mv')
                ->setMerchantLogoUrl('https://example.mv/logo.svg')
                // The gateway API marks the merchant name as required (max 40 characters).
                // Set it unless your merchant profile provides a default.
                ->setMerchantName('Merchant Name')
                ->setMerchantPhone('1234567890')
                ->setMerchantUrl('https://example.mv')
                // Return URL is important though not required
                // This is the URL the gateway will redirect the user to after payment
                ->setReturnUrl('https://example.mv/order123/process')
                // NOTE: Although not documented, the redirect page throws errors if the cancel URL is missing.
                // It’s recommended to set a cancel URL (usually same as the return URL) to avoid this issue.
                ->setCancelUrl('https://example.mv/order123/process')
                ->setRedirectMerchantUrl('https://example.mv/order123/process')
                ->setWebHookUrl('https://example.mv/webhook')
                ->setRetryAttemptCount(3);
```

Optional Initiate Checkout settings:

```php
use IbnNajjaar\MIBGlobalPay\Support\Constants\InteractionOperation;

$payment_details
    // PURCHASE (default), AUTHORIZE (capture later), VERIFY or NONE
    ->setInteractionOperation(InteractionOperation::PURCHASE)
    // Sent as order.custom.{key}: up to 50 alphanumeric keys, values up to 250 characters
    ->setCustomField('salesRegion', 'Male')
    // BYPASS, MANDATORY or USE_GATEWAY_RECOMMENDATION
    ->setThreeDSecureAction('MANDATORY')
    // OPTIONAL or MANDATORY
    ->setCardSecurityCodeDisplay('MANDATORY')
    ->setLocale('en_US')
    // INTERNET, MOTO, CALL_CENTRE, ... (requires permission on your merchant profile)
    ->setTransactionSource('INTERNET')
    ->setTransactionReference('TXN-REF-123'); // max 40 characters
```

The amount is sent to the gateway as a decimal string (for example `"100.50"`), and fields you don't set are left out of the request.

Send the request with the data:

```php
try {
    $response = $connector->createTransaction($payment_details);
    $response_data = $response->toDto();

    $session_id = $response_data->getSessionId();
    $success_indicator = $response_data->getSuccessIndicator();

    // Store success indicator for later verification
    // You may store it in your transaction or order record.
    // This will be used later to verify payment

} catch (Exception $e) {
    // Handle error appropriately
    echo $e->getMessage();
}
```
For further information, see the [API documentation: Initiate Checkout](https://test-mib.mtf.gateway.mastercard.com/api/documentation/apiDocumentation/rest-json/version/latest/operation/Hosted%20Checkout%3a%20Initiate%20Checkout.html?locale=en_US).
**Important:** Save the `successIndicator` in your database for later verification after redirection.

### Redirect to MIB Checkout

Once you have the session ID, redirect the user to the page below. `test-mib.mtf.gateway.mastercard.com` is the MIB test gateway; replace it with your production gateway host when you go live, and send the session ID to the view. This view will automatically redirect the user to the MIB Global Pay payment page.

```html
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Checkout</title>
    <script src="https://test-mib.mtf.gateway.mastercard.com/static/checkout/checkout.min.js"
                data-error="errorCallback"
                data-cancel="cancelCallback"></script>
</head>
<body>
    <div id="loading">Processing payment...</div>

    <script>
        Checkout.configure({
            session: {
                id: '<?php echo htmlspecialchars($session_id); ?>'
            }
        });

        // Redirect user to the payment page
        Checkout.showPaymentPage();
    </script>
</body>
</html>
```
For further information, see the [Documentation: Implementing the Hosted Payment Page](https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/hostedCheckout/implementingTheHostedPaymentPage.html?locale=en_US).

### Handle Payment Completion

After the payment process, MIB will redirect the user to the `return_url` you provided earlier. On that page, verify the payment result:

```php
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\HostedCheckoutReturnData;

// Get the result indicator from the redirect
$return_data = HostedCheckoutReturnData::fromArray($_GET);

// Retrieve the stored success indicator, then verify the payment result
// (timing-safe comparison of resultIndicator and successIndicator)
if ($return_data->matchesSuccessIndicator($success_indicator)) {
    // Payment was successful
    // Normally you should make a GET request to get order details
    // to confirm the payment before marking the order as paid
    echo "Payment was successful!";
} else {
    // Payment failed or was cancelled
    echo "Payment was not successful.";
}
```
For further information, see the [Documentation: Interpreting the Response](https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/hostedCheckout/interpretingTheResponse.html?locale=en_US).

### Retrieve Payment Status

To double-check the status of a payment via API:

```php
$order_reference = 'order_12345'; // Use the order ID you saved earlier

try {
    $response = $connector->getOrderDetails($order_reference);
    $response_data = $response->toDto();

    // Available methods on dto
    $response_data->getOrderStatus(); // lowercase, e.g. 'captured', see OrderStatus constants
    $response_data->getOrderId();
    $response_data->getAmount();
    $response_data->getCurrency();
    $response_data->getTotalAuthorizedAmount();
    $response_data->getTotalCapturedAmount();
    $response_data->getTotalRefundedAmount();
    $response_data->getRefundableAmount(); // captured - refunded
    $response_data->getCapturableAmount(); // authorized - captured
    $response_data->getTransactions(); // TransactionResponseData[]
    $response_data->getSuccessfulTransactions();
    $response_data->findTransaction('1');
    $response_data->getLatestTransactionOfType('refund');
    $response_data->getRawResponse();

    // To mark the order as paid
    if ($response_data->isPaid()) {
        // Mark order as paid
    }

} catch (Exception $e) {
    error_log('Failed to retrieve payment status: ' . $e->getMessage());
    echo "Could not retrieve payment status.";
}
```

For further information, see the [API documentation: Retrieve Order](https://test-mib.mtf.gateway.mastercard.com/api/documentation/apiDocumentation/rest-json/version/latest/operation/Transaction%3a%20%20Retrieve%20Order.html?locale=en_US).
**Best Practice:** Always verify payment status with the get order API for critical orders.

**Deprecated:** `paymentSuccessfullyCaptured()` still works, but it returns `false` as soon as any refund is made, because the order status changes to `partially_refunded` or `refunded`. Use `isPaid()` instead. It stays `true` after refunds, as long as an approved payment or capture exists.

### Handling Webhook Data

A webhook notifies you of successful transactions at predefined intervals, which is very useful for marking orders as paid. Sometimes, users may close their browser or interrupt the session before the payment gateway returns the transaction information. In such cases, your order might remain marked as unpaid in your database, even though the payment was completed in the merchant portal.

The webhook sends the successful transaction data to a predefined URL. You can use this data to update and mark the order as paid. Additionally, you can set a secret token in the merchant portal that will be included in the webhook request headers. This token can be used to verify the authenticity of the data.

Webhook notifications are sent as POST requests with a **JSON body**, so `$_POST` is empty. Read the raw request body instead:

```php
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\WebhookResponseData;

// Reads php://input and the request headers
$webhook_data = WebhookResponseData::fromGlobals();

// Or, if your framework gives you the body and headers:
// $webhook_data = WebhookResponseData::fromJson(file_get_contents('php://input'), getallheaders());
// $webhook_data = WebhookResponseData::fromJson($request->getContent(), $request->headers->all()); // Laravel/Symfony

// Reject notifications that don't carry your secret (timing-safe comparison)
if (!$webhook_data->verifySecret(getenv('MIB_WEBHOOK_SECRET'))) {
    http_response_code(401);
    exit;
}
// or: $webhook_data->assertValidSecret($secret); // throws MIBGlobalPayException

// The gateway retries a notification, so deduplicate on its ID
$notification_id = $webhook_data->getNotificationId();
$webhook_data->getNotificationAttempt(); // int|null

// Available methods: returns all strings or null
$webhook_data->getOrderReference();
$webhook_data->getOrderAmount(); // returns float|null
$webhook_data->getOrderCurrency();
$webhook_data->getOrderStatus();
$webhook_data->getNotificationSecret();
$webhook_data->getResult();
$webhook_data->getGatewayCode();
$webhook_data->getTransactionType();
$webhook_data->getTransactionId();
$webhook_data->getTransactionAmount();
$webhook_data->getTargetTransactionId();
$webhook_data->getTransaction(); // TransactionResponseData
$webhook_data->getRawResponse();

$webhook_data->paymentIsSuccessful(); // returns a boolean
$webhook_data->isRefund(); // notifications also fire for refunds,
$webhook_data->isVoid();   // voids and captures

http_response_code(200);
```

Webhook best practices:

- Respond with an HTTP 2xx within **2 seconds**, and do the heavy work asynchronously (for example in a queued job).
- Deduplicate on `getNotificationId()`. The gateway retries failed deliveries up to 20 times over 3 days.
- The notification secret is only sent to HTTPS URLs.
- The gateway doesn't recommend webhooks for critical flows, so confirm the payment with `getOrderDetails()` before you mark an order as paid.
For further information, see the [Documentation: Webhook Notifications](https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/supportedFeatures/pickAdditionalFunctionality/webhookNotifications.html?locale=en_US).

## Payment Lifecycle Operations

After checkout you can refund, void, capture and update authorizations. Every method returns a `Response`. Its `toDto()` returns a `TransactionResponseData`:

```php
$transaction = $response->toDto();

$transaction->isSuccessful(); // result is SUCCESS
$transaction->isFailure();    // result is FAILURE or ERROR (e.g. declined)
$transaction->isPending();
$transaction->isApproved();   // gateway code starts with APPROVED
$transaction->isRefund();     // also isPayment(), isCapture(), isAuthorization(), isVoid()
$transaction->getTransactionId();
$transaction->getAmount();
$transaction->getCurrency();
$transaction->getTargetTransactionId();
$transaction->getOrderStatus();
$transaction->getGatewayCode();
$transaction->getReceipt();
```

A **declined** operation is not an exception. The gateway answers with HTTP 201 and `result: FAILURE`, so always check `isSuccessful()`. Invalid requests (HTTP 4xx/5xx) throw a `GatewayException` (see [Error Handling](#error-handling)).

### Transaction IDs and Retries

Each operation needs a **new** transaction ID: unique within the order, at most 40 characters. Use your own ID or generate one:

```php
use IbnNajjaar\MIBGlobalPay\Support\TransactionId;

$transaction_id = TransactionId::generate('refund'); // e.g. "refund-9f86d081884c7d65"
```

- **Persist the ID before sending the request.**
- If you **don't receive a response** (timeout or connection error), wait 60 seconds and resend the identical request with the **same** ID. The bank transaction isn't repeated.
- If the operation **failed**, use a **new** ID for the retry.

### Authorize and Capture

Set the checkout to authorize only, then capture later (in full or in parts):

```php
use IbnNajjaar\MIBGlobalPay\Support\Constants\InteractionOperation;

$payment_details = OrderData::make('order123', 100.00)
    ->setInteractionOperation(InteractionOperation::AUTHORIZE);

// ... after the payer completes checkout:
$order = $connector->getOrderDetails('order123')->toDto();
$capturable = $order->getCapturableAmount();

$response = $connector->capture('order123', TransactionId::generate('capture'), $capturable, 'MVR');
```

### Refunds

Refunds can be full or partial, and you can refund an order more than once:

```php
$order = $connector->getOrderDetails('order123')->toDto();

$amount = 25.00;
if ($amount > $order->getRefundableAmount()) {
    // The gateway would reject this refund
}

$transaction_id = TransactionId::generate('refund'); // persist this first
$response = $connector->refund(
    'order123',        // the order ID used at checkout
    $transaction_id,   // a new transaction ID
    $amount,
    'MVR',
    null,              // optional: the transaction ID to refund (the gateway picks one if omitted)
    'RMA-1001'         // optional reference, max 40 characters
);

if ($response->toDto()->isSuccessful()) {
    // Refund approved
}
```

The SDK doesn't enforce the refundable amount itself, because the gateway is the source of truth.

### Cancelling (Void)

A void cancels an authorization, capture or payment. It works only **before the transaction is settled**, usually shortly after the payment. After settlement the gateway rejects the void, and you should refund instead.

```php
$response = $connector->void('order123', TransactionId::generate('void'), '1'); // '1' = the transaction to void
```

### Update Authorization

Change the authorized amount, or leave out the amount and currency to only extend the authorization's validity:

```php
$connector->updateAuthorization('order123', TransactionId::generate('update'), 120.00, 'MVR');
$connector->updateAuthorization('order123', TransactionId::generate('update'));
```

### Retrieve a Transaction

```php
$transaction = $connector->getTransactionDetails('order123', $transaction_id)->toDto();
```

Order IDs and transaction IDs are URL-encoded automatically, so IDs like `INV/2024#1` are safe to use.

## Extra Fields

`setExtraFields()` is an escape hatch for Initiate Checkout fields that the SDK doesn't model yet. The array is deep-merged into the request payload **last**, so it overrides SDK fields, and a `null` value removes a field. Use it at your own risk.

```php
$payment_details->setExtraFields([
    'order' => ['customerNote' => 'Leave at the door'],
    'interaction' => ['displayControl' => ['billingAddress' => 'HIDE']],
]);
```

## Error Handling

When the gateway responds with HTTP 4xx/5xx, the SDK throws `GatewayException`. This is a subclass of `MIBGlobalPayException`, so an existing `catch (MIBGlobalPayException $e)` keeps working. The exception code is the HTTP status. Connection errors and timeouts throw a plain `MIBGlobalPayException`. Invalid input (for example an amount with 3 decimals) throws `InvalidArgumentException` before any request is sent.

```php
use IbnNajjaar\MIBGlobalPay\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;

try {
    $response = $connector->createTransaction($payment_details);
    $session_id = $response->toDto()->getSessionId();
} catch (GatewayException $e) {
    $e->getHttpStatus();        // e.g. 400
    $e->getErrorCause();        // e.g. INVALID_REQUEST
    $e->getErrorExplanation();  // human readable explanation (also the exception message)
    $e->getErrorField();        // e.g. order.id
    $e->getValidationType();    // e.g. INVALID
    $e->getSupportCode();
    $e->getRawBody();
    $e->isAuthenticationError(); // 401: check your merchant ID and API password

    error_log('MIB Payment Error: ' . $e->getMessage());
} catch (MIBGlobalPayException $e) {
    // Network error or timeout
    error_log('MIB Payment Error: ' . $e->getMessage());
}
```

You can also change the timeout (the default is 30 seconds) or use your own Guzzle client, for example to add logging middleware:

```php
$connector->setTimeout(60);
$connector->setHttpClient(new \GuzzleHttp\Client(['handler' => $stack]));
```

The base URL, authentication, headers and timeout are applied to each request, so a custom client doesn't need them.

For further information, see the [Documentation: Error Handling](https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/apiReference/apiRefAPIErrorHandling.html?locale=en_US).

## API Response Structures

### Create Payment Response

This is a typical response you will receive when you send a create transaction request:

```php
[
    'checkoutMode' => 'WEBSITE',
    'merchant' => 'YOURMERCHANTID',
    'result' => 'SUCCESS',
    'session' => [
        'id' => 'SESSION_abc123', // session id
        'updateStatus' => 'SUCCESS',
        'version' => 'fasdf3452'
    ],
    'successIndicator' => 'abc123def456'
]
```

### Payment Status Response

This is a typical response you will receive to your return URL:

```php
[
    'order' => 'ORD-9010047',
    'resultIndicator' => '50addc325519453c',
    'sessionVersion' => '1fasdf23f',
    'checkoutVersion' => '1.0.0',
]
```

**Note:** The order ID is present because it was included in the return URL. If you did not include the order ID in the return URL, the order key will not be present.

### Response Methods

All responses have the following methods available:

- getStatusCode(): returns int
- getHeaders(): returns an array
- getBody(): returns json string
- toArray(): returns an array
- getResult(): returns the top-level `result` (SUCCESS, FAILURE, PENDING, UNKNOWN, ERROR) or null
- toDto(): returns a data transfer object defined in the request
- isSuccessful(): returns a boolean
- isFailed(): returns a boolean
- throw(): throws a `GatewayException` on failed response

`HostedCheckoutReturnData` also offers `getSessionVersion()` and `getOrderId()`. The order ID is only present if you added it to the return URL.

### Constants

The DTO getters return lowercase values. Compare them with the constants in `IbnNajjaar\MIBGlobalPay\Support\Constants`:

```php
use IbnNajjaar\MIBGlobalPay\Support\Constants\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Support\Constants\TransactionType;
use IbnNajjaar\MIBGlobalPay\Support\Constants\TransactionResult;

if ($order->getOrderStatus() === OrderStatus::PARTIALLY_REFUNDED) { /* ... */ }
$order->getLatestTransactionOfType(TransactionType::PAYMENT, TransactionType::CAPTURE);
```

`InteractionOperation` holds the uppercase request values (`PURCHASE`, `AUTHORIZE`, `VERIFY`, `NONE`).

## Security Considerations

### Credential Management

- Store API credentials in environment variables
- Never commit credentials to version control
- Use different credentials for sandbox and production

### HTTPS Requirements

- Always use HTTPS for return URLs
- Ensure your checkout page is served over HTTPS
- Verify SSL certificates in production

### Test Card Numbers

Use test card numbers provided by the bank. Test cards will be listed in the documentation. [Test Cards](https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/supportedFeatures/testAndGoLive.html?locale=en_US)

## Testing

```bash
composer test
```

### Run tests in Docker

If you prefer to run the test suite in Docker (no local PHP or Composer required):

```bash
# Build the image
docker compose build

# Install dependencies inside the container (if vendor is not present or is outdated)
docker compose run --rm php composer install

# Run PHPUnit
# Option A: Use the dedicated 'test' service
docker compose run --rm test

# Option B: Run via the php service (overriding the command)
docker compose run --rm php vendor/bin/phpunit -c phpunit.xml --colors=always
```

### Mocking HTTP in your tests

Inject a Guzzle client with a `MockHandler` to test your integration without real HTTP calls:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;

$mock = new MockHandler([
    new Response(201, [], json_encode(['result' => 'SUCCESS', 'session' => ['id' => 'SESSION0001'], 'successIndicator' => 'abc'])),
]);
$connector->setHttpClient(new Client(['handler' => HandlerStack::create($mock)]));
```

## Changelog

See CHANGELOG.md for release notes: [CHANGELOG.md](./CHANGELOG.md)

## Contributing

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## Contributors

- [Hussain Afeef](https://abunooh.com)

## License

MIT License.

---

For additional support, please raise an issue.
