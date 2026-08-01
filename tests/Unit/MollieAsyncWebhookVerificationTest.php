<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Drivers\MollieDriver;

test('mollie requires async verification when no webhook_secret is configured', function () {
    $driver = new MollieDriver(['api_key' => 'test_key']);

    expect($driver->requiresAsyncVerification())->toBeTrue();
});

test('mollie does not require async verification when webhook_secret is configured', function () {
    $driver = new MollieDriver(['api_key' => 'test_key', 'webhook_secret' => 'whsec_test']);

    expect($driver->requiresAsyncVerification())->toBeFalse();
});

test('mollie webhook request defers to the queue when no webhook_secret is configured (ADR-0008)', function () {
    config([
        'payments.webhook.verify_signature' => true,
        'payments.providers.mollie' => [
            'driver' => 'mollie',
            'api_key' => 'test_key',
            'enabled' => true,
        ],
    ]);
    app()->forgetInstance('payments.config');

    $body = json_encode(['id' => 'tr_test']);
    $request = makeWebhookRequestFor('mollie', $body);

    // No signature header, no webhook_secret configured - a synchronous
    // check would have nothing to verify against. authorize() must still
    // return true because verification is deferred to ProcessWebhook.
    expect($request->authorize())->toBeTrue();
});

test('mollie webhook request still verifies synchronously when webhook_secret is configured', function () {
    config([
        'payments.webhook.verify_signature' => true,
        'payments.providers.mollie' => [
            'driver' => 'mollie',
            'api_key' => 'test_key',
            'webhook_secret' => 'whsec_test',
            'enabled' => true,
        ],
    ]);
    app()->forgetInstance('payments.config');

    $body = json_encode(['id' => 'tr_test']);
    // No valid X-Mollie-Signature header - this must be rejected
    // synchronously, exactly as before ADR-0008 (only the no-secret path
    // changed).
    $request = makeWebhookRequestFor('mollie', $body);

    expect($request->authorize())->toBeFalse();
});
