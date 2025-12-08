<?php

return [
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
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'currencies' => ['NGN', 'GHS', 'ZAR', 'USD'],
            'enabled' => env('PAYSTACK_ENABLED', true),
        ],

        'flutterwave' => [
            'driver' => 'flutterwave',
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3/'),
            'currencies' => ['NGN', 'USD', 'EUR', 'GBP', 'KES', 'UGX', 'TZS'],
            'enabled' => env('FLUTTERWAVE_ENABLED', false),
        ],

        'monnify' => [
            'driver' => 'monnify',
            'api_key' => env('MONNIFY_API_KEY'),
            'secret_key' => env('MONNIFY_SECRET_KEY'),
            'contract_code' => env('MONNIFY_CONTRACT_CODE'),
            'base_url' => env('MONNIFY_BASE_URL', 'https://api.monnify.com'), // Sandbox: https://sandbox.monnify.com | Live: https://api.monnify.com,
            'currencies' => ['NGN'],
            'enabled' => env('MONNIFY_ENABLED', false),
        ],

        'stripe' => [
            'driver' => 'stripe',
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'public_key' => env('STRIPE_PUBLIC_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com'),
            'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
            'enabled' => env('STRIPE_ENABLED', false),
        ],

        'paypal' => [
            'driver' => 'paypal',
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'), // Required for webhook validation
            'mode' => env('PAYPAL_MODE', 'sandbox'), // sandbox or live
            'base_url' => env('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com'),
            'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
            'enabled' => env('PAYPAL_ENABLED', false),
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | Cache TTL for health check results (in seconds).
    |
    */
    'health_check' => [
        'cache_ttl' => env('PAYMENTS_HEALTH_CHECK_CACHE_TTL', 300), // 5 minutes
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing Mode
    |--------------------------------------------------------------------------
    |
    | Enable testing mode to disable SSL verification (for local development only).
    |
    */
    'testing_mode' => env('PAYMENTS_TESTING_MODE', false),
];
