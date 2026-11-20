<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\Models\WebhookEvent;

/*
 * PayZephyr's tables live on the host app's own database connection. The models used to switch
 * to a connection named "testing" whenever the app ran in the testing environment: a shortcut
 * for this package's suite that broke every host app's tests (which rarely define one), so a
 * webhook in an app's test failed with "Database connection [testing] not configured".
 */
test('every model uses the app\'s default connection, in the testing environment too', function (string $model): void {
    expect(app()->environment('testing'))->toBeTrue()
        ->and((new $model)->getConnectionName())->toBeNull();
})->with([
    PaymentTransaction::class,
    RefundTransaction::class,
    SubscriptionTransaction::class,
    WebhookEvent::class,
    PaymentTraceEvent::class,
]);
