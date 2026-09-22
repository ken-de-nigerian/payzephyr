<?php

$paymentsHealthCheckAllowedIps = env('PAYMENTS_HEALTH_CHECK_ALLOWED_IPS');
$paymentsHealthCheckAllowedTokens = env('PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS');

return [
    /*
    |--------------------------------------------------------------------------
    | Installed Features
    |--------------------------------------------------------------------------
    |
    | Tracks which optional PayZephyr features `php artisan payzephyr:install`
    | has enabled for this app (payment logging and webhook processing are
    | core and always available, not listed here).
    |
    */
    'features' => [
        'subscriptions' => env('PAYZEPHYR_FEATURE_SUBSCRIPTIONS', false),
        'refunds' => env('PAYZEPHYR_FEATURE_REFUNDS', false),
        'trace' => env('PAYZEPHYR_FEATURE_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Payment Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment provider that will be used
    | when no specific provider is requested. You can change this to any
    | of the configured providers below.
    |
    */
    'default' => env('PAYMENTS_DEFAULT_PROVIDER', 'paystack'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Provider
    |--------------------------------------------------------------------------
    |
    | If the primary provider fails, the system will automatically attempt
    | to use this fallback provider. Set to null to disable fallback.
    |
    */
    'fallback' => env('PAYMENTS_FALLBACK_PROVIDER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Here you can configure all your payment providers. Each provider
    | requires specific credentials and configuration options.
    |
    */
    'providers' => [
        'paystack' => [
            'driver' => 'paystack',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\PaystackDriver::class,
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'currencies' => ['NGN', 'GHS', 'ZAR', 'USD'],
            'enabled' => env('PAYSTACK_ENABLED', true),
        ],

        'flutterwave' => [
            'driver' => 'flutterwave',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver::class,
            'reference_prefix' => 'FLW',
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'webhook_secret' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3/'),
            'currencies' => ['NGN', 'USD', 'EUR', 'GBP', 'KES', 'UGX', 'TZS'],
            'enabled' => env('FLUTTERWAVE_ENABLED', false),
        ],

        'monnify' => [
            'driver' => 'monnify',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\MonnifyDriver::class,
            'reference_prefix' => 'MON',
            'api_key' => env('MONNIFY_API_KEY'),
            'secret_key' => env('MONNIFY_SECRET_KEY'),
            'contract_code' => env('MONNIFY_CONTRACT_CODE'),
            'base_url' => env('MONNIFY_BASE_URL', 'https://api.monnify.com'), // Sandbox: https://sandbox.monnify.com | Live: https://api.monnify.com,
            'currencies' => ['NGN'],
            'enabled' => env('MONNIFY_ENABLED', false),
        ],

        'stripe' => [
            'driver' => 'stripe',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\StripeDriver::class,
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'public_key' => env('STRIPE_PUBLIC_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com'),
            'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
            'enabled' => env('STRIPE_ENABLED', false),
        ],

        'paypal' => [
            'driver' => 'paypal',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\PayPalDriver::class,
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'), // Required for webhook validation
            'mode' => env('PAYPAL_MODE', 'sandbox'), // sandbox or live
            'base_url' => env('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com'),
            'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
            'enabled' => env('PAYPAL_ENABLED', false),
        ],

        'square' => [
            'driver' => 'square',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\SquareDriver::class,
            'access_token' => env('SQUARE_ACCESS_TOKEN'),
            'location_id' => env('SQUARE_LOCATION_ID'),
            'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
            'base_url' => env('SQUARE_BASE_URL', 'https://connect.squareupsandbox.com'), // Sandbox: https://connect.squareupsandbox.com | Live: https://connect.squareup.com,
            'currencies' => ['USD', 'CAD', 'GBP', 'AUD'],
            'enabled' => env('SQUARE_ENABLED', false),
        ],

        'opay' => [
            'driver' => 'opay',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\OPayDriver::class,
            'merchant_id' => env('OPAY_MERCHANT_ID'),
            'public_key' => env('OPAY_PUBLIC_KEY'),
            'secret_key' => env('OPAY_SECRET_KEY'), // Required for webhook validation
            'base_url' => env('OPAY_BASE_URL', 'https://liveapi.opaycheckout.com'), // Test: https://testapi.opaycheckout.com | Live: https://liveapi.opaycheckout.com
            'currencies' => ['NGN'],
            'enabled' => env('OPAY_ENABLED', false),
        ],

        'paddle' => [
            'driver' => 'paddle',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\PaddleDriver::class,
            'api_key' => env('PADDLE_API_KEY'),
            'client_token' => env('PADDLE_CLIENT_TOKEN'), // Paddle.js only; unused server-side
            'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'), // Notification destination secret key
            'base_url' => env('PADDLE_BASE_URL', 'https://sandbox-api.paddle.com'), // Sandbox: https://sandbox-api.paddle.com | Live: https://api.paddle.com
            'tax_category' => env('PADDLE_TAX_CATEGORY', 'standard'),
            'product_name' => env('PADDLE_PRODUCT_NAME'),
            'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SGD', 'SEK'],
            'enabled' => env('PADDLE_ENABLED', false),
        ],

        'mollie' => [
            'driver' => 'mollie',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\MollieDriver::class,
            'reference_prefix' => 'MOLLIE',
            'api_key' => env('MOLLIE_API_KEY'),
            'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET'),
            'base_url' => env('MOLLIE_BASE_URL', 'https://api.mollie.com'),
            'currencies' => ['EUR', 'USD', 'GBP', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF'],
            'enabled' => env('MOLLIE_ENABLED', false),
        ],

        'razorpay' => [
            'driver' => 'razorpay',
            'driver_class' => \KenDeNigerian\PayZephyr\Drivers\RazorpayDriver::class,
            'reference_prefix' => 'RAZORPAY',
            'key_id' => env('RAZORPAY_KEY_ID'),
            'key_secret' => env('RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'), // Required for webhook validation: the secret set on the webhook in the Dashboard, not the key secret
            'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com'), // One host for both modes: rzp_test_ or rzp_live_ keys decide which
            'refund_speed' => env('RAZORPAY_REFUND_SPEED', 'normal'), // normal | optimum
            'currencies' => ['INR'],
            'enabled' => env('RAZORPAY_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Configuration
    |--------------------------------------------------------------------------
    |
    | The default currency code (ISO 4217) used when not specified.
    |
    */
    'currency' => [
        'default' => env('PAYMENTS_DEFAULT_CURRENCY', 'NGN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhook handling for payment notifications.
    |
    */
    'webhook' => [
        'path' => env('PAYMENTS_WEBHOOK_PATH', '/payments/webhook'),
        'verify_signature' => env('PAYMENTS_WEBHOOK_VERIFY_SIGNATURE', true),
        'rate_limit' => env('PAYMENTS_WEBHOOK_RATE_LIMIT', '120,1'), // requests per minute
        'max_payload_size' => env('PAYMENTS_WEBHOOK_MAX_PAYLOAD_SIZE', 1048576), // 1MB in bytes
        'max_retries' => env('PAYMENTS_WEBHOOK_MAX_RETRIES', 3),
        'retry_backoff' => env('PAYMENTS_WEBHOOK_RETRY_BACKOFF', 60), // seconds
        'events' => [
            'table' => env('PAYMENTS_WEBHOOK_EVENTS_TABLE', 'webhook_events'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | Cache TTL for health check results (in seconds).
    | Authentication and IP whitelisting for production security.
    |
    */
    'health_check' => [
        // On by default. PaymentManager has always read this with a `?? true`
        // fallback, but the key was never declared here, so the behaviour was
        // invisible in the published config and could only be found in the
        // source. Turning it off means a charge is attempted against a
        // provider without asking whether it is reachable first.
        'enabled' => env('PAYMENTS_HEALTH_CHECK_ENABLED', true),
        'cache_ttl' => env('PAYMENTS_HEALTH_CHECK_CACHE_TTL', 300), // 5 minutes
        'require_auth' => env('PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH', false),
        'allowed_ips' => $paymentsHealthCheckAllowedIps ? explode(',', $paymentsHealthCheckAllowedIps) : [],
        'allowed_tokens' => $paymentsHealthCheckAllowedTokens ? explode(',', $paymentsHealthCheckAllowedTokens) : [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Logging
    |--------------------------------------------------------------------------
    |
    | Enable automatic logging of all payment transactions to the database.
    |
    */
    'logging' => [
        'enabled' => env('PAYMENTS_LOGGING_ENABLED', true),
        'table' => 'payment_transactions',
        'channel' => env('PAYMENTS_LOG_CHANNEL', 'payments'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Tracing
    |--------------------------------------------------------------------------
    |
    | Records every step of a payment - provider requests and responses,
    | fallback decisions, webhooks, retries - as its own row, so the whole
    | lifecycle can be replayed afterwards. Where 'logging' above keeps a
    | payment's current state, this keeps the sequence that produced it.
    |
    */
    'trace' => [
        'table' => env('PAYZEPHYR_TRACE_TABLE', 'payment_trace_events'),
        'connection' => env('PAYZEPHYR_TRACE_CONNECTION'),

        // Recommended in production: keeps the write off the request path.
        'async' => env('PAYZEPHYR_TRACE_ASYNC', false),

        'queue' => [
            'connection' => env('PAYZEPHYR_TRACE_QUEUE_CONNECTION'),
            'name' => env('PAYZEPHYR_TRACE_QUEUE_NAME', 'default'),
        ],

        'redact_fields' => [
            'card_number',
            'cvv',
            'cvc',
            'card_cvv',
            'card_cvc',
            'secret',
            'password',
            'api_key',
            'secret_key',
            'private_key',
            'authorization',
            'token',
            'access_token',
            'refresh_token',
        ],

        'redaction_max_depth' => env('PAYZEPHYR_TRACE_REDACTION_MAX_DEPTH', 10),
        'record_http_bodies' => env('PAYZEPHYR_TRACE_RECORD_HTTP_BODIES', true),
        'retention_days' => env('PAYZEPHYR_TRACE_RETENTION_DAYS', 90),
        'slow_response_ms' => env('PAYZEPHYR_TRACE_SLOW_RESPONSE_MS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Configuration
    |--------------------------------------------------------------------------
    |
    | Duplicate prevention, request validation, and the subscription
    | transaction log. Renewal retry, grace periods and notifications are the
    | application's responsibility: subscribe to SubscriptionRenewed and
    | SubscriptionPaymentFailed and decide there.
    |
    */
    'subscriptions' => [
        'prevent_duplicates' => env('PAYMENTS_SUBSCRIPTIONS_PREVENT_DUPLICATES', false),
        'validation' => [
            'enabled' => env('PAYMENTS_SUBSCRIPTIONS_VALIDATION_ENABLED', true),
        ],
        'logging' => [
            'enabled' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_ENABLED', true),
            'table' => env('PAYMENTS_SUBSCRIPTIONS_LOGGING_TABLE', 'subscription_transactions'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Refund Configuration
    |--------------------------------------------------------------------------
    |
    | Configure refund-specific settings including logging, webhooks,
    | and business logic rules.
    |
    */
    'refunds' => [
        'prevent_duplicates' => env('PAYMENTS_REFUNDS_PREVENT_DUPLICATES', true),
        'validation' => [
            'enabled' => env('PAYMENTS_REFUNDS_VALIDATION_ENABLED', true),
        ],
        'logging' => [
            'enabled' => env('PAYMENTS_REFUNDS_LOGGING_ENABLED', true),
            'table' => env('PAYMENTS_REFUNDS_LOGGING_TABLE', 'refund_transactions'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Cache TTL for session data (in seconds).
    |
    */
    'cache' => [
        'session_ttl' => env('PAYMENTS_CACHE_SESSION_TTL', 3600), // 1 hour in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    */
    'security' => [
        'webhook_timestamp_tolerance' => env('PAYMENTS_WEBHOOK_TIMESTAMP_TOLERANCE', 300),
        'rate_limit' => [
            'enabled' => env('PAYMENTS_RATE_LIMIT_ENABLED', true),
            'max_attempts' => env('PAYMENTS_RATE_LIMIT_ATTEMPTS', 10),
            'decay_seconds' => env('PAYMENTS_RATE_LIMIT_DECAY', 60),
        ],
        'sanitize_logs' => env('PAYMENTS_SANITIZE_LOGS', true),
        'cache_isolation' => env('PAYMENTS_CACHE_ISOLATION', true),
    ],
];
