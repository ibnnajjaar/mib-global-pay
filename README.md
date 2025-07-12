# MIB Global Pay SDK

[![Tests](https://github.com/ibnnajjaar/mib-global-pay/workflows/Tests/badge.svg)](https://github.com/ibnnajjaar/mib-global-pay/actions)
![Code Coverage Badge](./.github/coverage.svg)

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

// You can chain methods to set other information. All available methods are as follows
$payment_details->setOrderCurrency('MVR')
				        ->setOrderDescription('Test Order')
	              ->setMerchantAddressLine1('Sample, Majeedhee Magu')
	              ->setMerchantEmail('merchant@example.mv')
	              ->setMerchantLogo('https://example.mv/logo.svg')
	              ->setMerchantName('Merchant Name')
	              ->setMerchantPhone('1234567890')
	              ->setMerchantUrl('https://example.mv')
	              // return url is important though not required
	              // this is the url the gateway will redirect user after paym
	              ->setReturnUrl('https://example.mv/order123/process')
	              ->setCancelUrl('https://example.mv/order123/process')
	              ->setRedirectMerchantUrl('https://example.mv/order123/process')
	              ->setWebHookUrl('https://example.mv/webhook')
	              ->setRetryAttemptCount(3);

```

Send the request with the data.

```php
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;

try {
    $response = $connector->createTransaction($payment_details);
    $response_data = $response->toDto();

    $session_id = $response_data->getSessionId();
    $success_indicator = $response_data->getSuccessIndicator();

    // Store success indicator for later verification
    // You may store it in your transaction or order record.
    // This will be used later to verify paymen

} catch (Exception $e) {
    // Handle error appropriately
    echo $e->getMessage();
}

```

**Important:** Save the `successIndicator` in your database for later verification after redirection.

### Redirect to MIB Checkout

Once you have the session ID, redirect user to the below page. You will need to update the [`sandbox.gateway.mastercard.com`](http://sandbox.gateway.mastercard.com) to appropriate url and also send the session ID to the view. This view will automatically redirect user to the MIB Global Pay payment page.

```php
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
$return_data = HostedCheckoutReturnData::fromArray($_GET)
$result_indicator = $return_data->getResultIndicator();

// Retrieve the stored success indicator
// Verify payment result
if ($result_indicator && $result_indicator == $success_indicator) {
    // Payment was successful
    // Normally you should make a get request to get order details
    // to confirm the payment before marking order as paid
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
		
		// to mark the order as paid
		if ($response_data->paymentSuccessfullyCaptured()) {
			// mark order as paid
		}

} catch (Exception $e) {
    error_log('Failed to retrieve payment status: ' . $e->getMessage());
    echo "Could not retrieve payment status.";
}

```

**Best Practice:** Always verify payment status with the get order API for critical orders.

### Handling Webhook Data

Webhook notifies you of successful transactions in predefined intervals. This is very useful to mark the order as paid. Sometimes, users might close the browser or interrupt the session before the gateway returns with the transaction information after payment. In these cases, your order will remain in unpaid status in your database, even though it has been paid in the merchant portal. A webhook will send the successful transaction information to predefined url. You can view these data and then mark your order as paid. You can also set a secret to be sent with the webhook notification from merchant portal and it will be included in headers. You can use this to verify that the data is valid. Webhook notifications are post requests therefore your application should be able to accept post requests on the given endpoint.

You can convert the webhook data to a response data object like below.

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

This is a typical response you will receive when you send create transaction request.

```php
[
		'checkoutMode' => 'WEBSITE',
		'merchant' => 'YOURMERCHANTID',
		'result' => 'SUCCESS',
    'session' => [
        'id' => 'SESSION_abc123', // session id
        'udpateStatus' => 'SUCCESS',
        'version' => 'fasdf3452'
    ],
    'successIndicator' => 'abc123def456'
]

```

### Payment Status Response

This is a typical response you will receive to your return url.

```php
[
  'order' => 'ORD-9010047',
  'resultIndicator' => '50addc325519453c',
  'sessionVersion' => '1fasdf23f',
  'checkoutVersion' => '1.0.0',
]
```

**Note:** order id is present because it was included the order id in the return url. If you did not include order id in the return url, order key will not be present.

### Response Methods

All responses has the following methods available

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

Use test card numbers provided by the bank.  Test cards will be listed in the documentation.

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

## License

MIT License. See LICENSE file for details.

---

For additional support, please raise an issue.
