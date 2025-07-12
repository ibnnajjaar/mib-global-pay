# MIB Global Pay SDK

[![Tests](https://github.com/ibnnajjaar/mib-global-pay/workflows/Tests/badge.svg)](https://github.com/ibnnajjaar/mib-global-pay/actions)

A Framework-agnostic PHP SDK for integrating with MIB Global Pay – enabling merchants to initiate payments and retrieve payment statuses using a clean, developer-friendly interface.

## Table of Contents

- Requirements
- Installation
- Setup & Configuration
- Available Operations
- Error Handling
- API Response Structures
- Security Considerations
- Testing
- Roadmap
- License

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

$client = new MIBGlobalPayConnector($merchant_portal_url, $merchant_id, $api_password);
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

## Available Operations

### Create Payment

To initiate a payment, first prepare the order data:

```php
use IbnNajjaar\MIBGlobalPay\Data\OrderData;

// Construction of OrderData validates the provided values
$payment_details = OrderData::fromArray([
    'id' => 'order_12345',                    // Unique identifier for the order
    'amount' => 1000.00,                      // Amount in decimals
    'currency' => 'MVR',                      // ISO 4217 currency code
    'description' => 'Test Payment',          // Payment description
    'return_url' => 'https://yourwebsite.com/orders/order_12345/process', // gateway will redirect back to this URL after the payment completion
]);

try {
    $response = $connector->createTransaction($payment_details->toArray());
    $response_data = json_decode($response->getBody()->getContents(), true);

    $session_id = $response_data['session']['id'] ?? null;
    $success_indicator = $response_data['successIndicator'] ?? null;

    // Store success indicator for later verification
    // You may store it in your transaction or order record.
    // This will be used later to verify payment
    $_SESSION['success_indicator'] = $success_indicator;

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
$result_indicator = $_GET['resultIndicator'] ?? null;

// Retrieve the stored success indicator
$success_indicator = $_SESSION['success_indicator'] ?? null;

// Verify payment result
if ($result_indicator && $success_indicator == $result_indicator) {
    // Payment was successful
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
    $response = $connector->getTransaction($order_reference);
    $response_data = json_decode($response->getBody()->getContents(), true);

		// You can log the $response_data and use it to determine if
		// the payment was processed, here I am using the status
    $status = $response_data['status'] ?? null;
    
    if ($status === 'CAPTURED') {
	    // Update order as paid
 

} catch (Exception $e) {
    error_log('Failed to retrieve payment status: ' . $e->getMessage());
    echo "Could not retrieve payment status.";
}

```

**Best Practice:** Always verify payment status with the API for critical orders.

## Error Handling

The SDK may encounter various errors during API calls. Always implement proper error handling:

```php
try {

    $response = $connector->createTransaction($payment_details->toArray());
    $response_data = json_decode($response->getBody()->getContents(), true);
    
} catch (Exception $e) {

    // Log the error
    error_log('MIB Payment Error: ' . $e->getMessage());

}

```

## API Response Structures

### Create Payment Response

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

### Payment Status Values

Here are some of the status values you may receive with the order details. Note that these are not all the values you will receive for the status.

- **CAPTURED**: The authorized amount for this order, in full or excess, has been captured successfully.
- **CANCELLED**: The initial transaction for this order has been voided successfully.
- **DISBURSED**: The order amount has successfully been disbursed to the payer.
- **DISPUTED:** The payment has been disputed and is under investigation. A request for information has been received or a chargeback is pending.
- **FAILED**: The payment has not been successful.
- **PARTIALLY_REFUNDED**: The payment has been captured in part, full, or excess, but the captured amount in part has been refunded successfully.
- **REFUNDED**: The payment has been captured in part, full, or excess, but the captured amount in full has been refunded successfully.
- **VERIFIED**: The card details for this order have successfully been verified. No payment has yet been initiated or made.

You may also chose to compare the amount and totalCapturedAmount for verification.

## Security Considerations

### Credential Management

- Store API credentials in environment variables
- Never commit credentials to version control
- Use different credentials for sandbox and production
- Rotate credentials regularly

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
When using docker, you can run the tests using:

```bash
docker-compose run --rm php vendor/bin/phpunit
```

## Roadmap

- [ ]  Support for Webhook verification
- [ ]  Add support for refunds and voids

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
