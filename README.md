# MIB Global Pay SDK

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ibnnajjaar/mib-global-pay.svg?style=flat-square)](https://packagist.org/packages/ibnnajjaar/mib-global-pay)
[![Tests](https://github.com/ibnnajjaar/mib-global-pay/workflows/Tests/badge.svg)](https://github.com/ibnnajjaar/mib-global-pay/actions)
![Code Coverage Badge](./.github/coverage.svg)
[![Total Downloads](https://img.shields.io/packagist/dt/ibnnajjaar/mib-global-pay.svg?style=flat-square)](https://packagist.org/packages/ibnnajjaar/mib-global-pay)

A Framework-agnostic PHP SDK for integrating with MIB Global Pay – enabling merchants to initiate payments and retrieve payment statuses using a clean, developer-friendly interface.

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
- [Error Handling](#error-handling)
- [API Response Structures](#api-response-structures)
    - [Create Payment Response](#create-payment-response)
    - [Payment Status Response](#payment-status-response)
    - [Response Methods](#response-methods)
- [Security Considerations](#security-considerations)
    - [Credential Management](#credential-management)
    - [HTTPS Requirements](#https-requirements)
    - [Test Card Numbers](#test-card-numbers)
- [Testing](#testing)
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
$merchant_portal_url = 'sandbox.gateway.mastercard.com';
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
MIB_MERCHANT_URL=sandbox.gateway.mastercard.com
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
// Minimum required data
$order_id = 'order123';
$amount = 100.00;

$payment_details = OrderData::make($order_id, $amount);

// You can chain methods to set other information. All available methods are as follows:
$payment_details->setOrderCurrency('MVR')
                ->setOrderDescription('Test Order')
                ->setMerchantAddressLine1('Sample, Majeedhee Magu')
                ->setMerchantEmail('merchant@example.mv')
                ->setMerchantLogo('https://example.mv/logo.svg')
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

Send the request with the data:

```php
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;

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

**Important:** Save the `successIndicator` in your database for later verification after redirection.

### Redirect to MIB Checkout

Once you have the session ID, redirect the user to the page below. You will need to update the [`sandbox.gateway.mastercard.com`](http://sandbox.gateway.mastercard.com) to the appropriate URL and also send the session ID to the view. This view will automatically redirect the user to the MIB Global Pay payment page.

```html
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Checkout</title>
    <script src="https://sandbox.gateway.mastercard.com/static/checkout/checkout.min.js"
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

### Handle Payment Completion

After the payment process, MIB will redirect the user to the `return_url` you provided earlier. On that page, verify the payment result:

```php
// Get the result indicator from the redirect
$return_data = HostedCheckoutReturnData::fromArray($_GET);
$result_indicator = $return_data->getResultIndicator();

// Retrieve the stored success indicator
// Verify payment result
if ($result_indicator && $result_indicator == $success_indicator) {
    // Payment was successful
    // Normally you should make a GET request to get order details
    // to confirm the payment before marking the order as paid
    echo "Payment was successful!";
} else {
    // Payment failed or was cancelled
    echo "Payment was not successful.";
}
```

### Retrieve Payment Status

To double-check the status of a payment via API:

```php
$order_reference = 'order_12345'; // Use the order ID you saved earlier

try {
    $response = $connector->getOrderDetails($order_reference);
    $response_data = $response->toDto();

    // Available methods on dto
    $response_data->getOrderStatus();
    $response_data->getTotalCapturedAmount();
    $response_data->getTransactions();
    $response_data->getRawResponse();
    $response_data->paymentSuccessfullyCaptured();
    
    // To mark the order as paid
    if ($response_data->paymentSuccessfullyCaptured()) {
        // Mark order as paid
    }

} catch (Exception $e) {
    error_log('Failed to retrieve payment status: ' . $e->getMessage());
    echo "Could not retrieve payment status.";
}
```

**Best Practice:** Always verify payment status with the get order API for critical orders.

### Handling Webhook Data

A webhook notifies you of successful transactions at predefined intervals, which is very useful for marking orders as paid. Sometimes, users may close their browser or interrupt the session before the payment gateway returns the transaction information. In such cases, your order might remain marked as unpaid in your database, even though the payment was completed in the merchant portal.

The webhook sends the successful transaction data to a predefined URL. You can use this data to update and mark the order as paid. Additionally, you can set a secret token in the merchant portal that will be included in the webhook request headers. This token can be used to verify the authenticity of the data.

Since webhook notifications are sent as POST requests, your application must be able to accept POST requests at the specified endpoint.

You can convert the webhook data into a response data object as shown below:

```php
$webhook_data = WebhookResponseData::fromArray($_POST, getallheaders());

// Available methods: returns all strings or null
$webhook_data->getOrderReference();
$webhook_data->getOrderAmount(); // returns float|null
$webhook_data->getOrderCurrency();
$webhook_data->getOrderStatus();
$webhook_data->getNotificationSecret();
$webhook_data->getResult();
$webhook_data->getGatewayCode();
$webhook_data->getRawResponse();

$webhook_data->paymentIsSuccessful(); // returns a boolean
```

## Error Handling

The SDK may encounter various errors during API calls. Always implement proper error handling:

```php
try {
    $response = $client->transactions->create($payment_details->toArray());
    $response_data = json_decode($response->getBody()->getContents(), true);
    
} catch (Exception $e) {
    // Log the error
    error_log('MIB Payment Error: ' . $e->getMessage());
}
```

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
- toDto(): returns a data transfer object defined in the request
- isSuccessful(): returns a boolean
- isFailed(): returns a boolean
- throw(): throws an exception on failed response

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

Use test card numbers provided by the bank. Test cards will be listed in the documentation.

## Testing

```bash
composer test
```

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
