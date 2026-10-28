<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MIB Global Pay (MPGS Hosted Checkout) — keys and defaults per DESIGN-v2.md §6.7 F
|--------------------------------------------------------------------------
*/

return [

    // "test" (preset host test-mib.mtf.gateway.mastercard.com) or "production" (no preset: set MIB_GATEWAY_URL).
    'environment' => env('MIB_ENVIRONMENT', 'test'),

    // Overrides the environment preset. Required for production.
    'host' => env('MIB_GATEWAY_URL'),

    'merchant_id' => env('MIB_MERCHANT_ID'),

    'api_password' => env('MIB_API_PASSWORD'),

    'api_version' => env('MIB_API_VERSION', 100),

    // Seconds, for the default Guzzle client.
    'timeout' => env('MIB_TIMEOUT', 30),

    // Default currency for new checkouts; must be in "currencies".
    'currency' => env('MIB_CURRENCY', 'MVR'),

    // Allow-list (comma-separated in the env).
    'currencies' => env('MIB_CURRENCIES', 'MVR'),

    // Required, no default: 1-10 characters A-Z a-z 0-9. Use different prefixes for test and production.
    'order_prefix' => env('MIB_ORDER_PREFIX'),

    // Defaults to https://{host}/static/checkout/checkout.min.js.
    'checkout_js_url' => env('MIB_CHECKOUT_JS_URL'),

    'webhook' => [
        'enabled' => env('MIB_WEBHOOK_ENABLED', true),

        // Required when the webhook is enabled. MIB_WEBHOOK_SIGNATURE is a deprecated fallback.
        'secret' => env('MIB_WEBHOOK_SECRET', env('MIB_WEBHOOK_SIGNATURE')),
        'secret_uses_deprecated_env' => env('MIB_WEBHOOK_SECRET') === null && env('MIB_WEBHOOK_SIGNATURE') !== null,

        // Auto-registered route: POST {domain}/{path}. Set register_route to false and use
        // Route::mibGlobalPayWebhook($uri) to register it yourself.
        'register_route' => env('MIB_WEBHOOK_REGISTER_ROUTE', true),
        'path' => env('MIB_WEBHOOK_PATH', 'mib/webhook'),
        'domain' => env('MIB_WEBHOOK_DOMAIN'),

        // Queue connection and name for ProcessNotification (app defaults when unset).
        'queue' => env('MIB_WEBHOOK_QUEUE'),
        'connection' => env('MIB_WEBHOOK_CONNECTION'),
    ],

    // "database" (publish the migration) or "cache".
    'notification_store' => env('MIB_NOTIFICATION_STORE', 'database'),

    'approval' => [
        // APPROVED_AUTO = "approved by the gateway, not submitted to the acquirer".
        'accept_approved_auto' => env('MIB_ACCEPT_APPROVED_AUTO', false),
    ],

    'double_capture' => [
        // Void (then refund) a second capture on an already settled payable. Off by default.
        'auto_reverse' => env('MIB_AUTO_REVERSE_DOUBLE_CAPTURE', false),
    ],

    'attempts' => [
        'expiry_grace_seconds' => env('MIB_ATTEMPT_GRACE_SECONDS', 300),
    ],

    'checkout' => [
        // Sent as interaction.timeout (600-1800).
        'timeout_seconds' => env('MIB_CHECKOUT_TIMEOUT', 1800),
    ],

    // Local development only.
    'allow_insecure_urls' => env('MIB_ALLOW_INSECURE_URLS', false),

    'logging' => [
        'channel' => env('MIB_LOG_CHANNEL'),
    ],
];
