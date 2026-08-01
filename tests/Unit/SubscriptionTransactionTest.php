<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\PaymentServiceProvider;

function subscriptionTransactionData(array $overrides = []): array
{
    return array_merge([
        'subscription_code' => 'SUB_'.uniqid(),
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_123',
        'customer_email' => 'user@test.com',
        'amount' => 5000,
        'currency' => 'NGN',
    ], $overrides);
}

test('it uses the configured subscriptions table name', function () {
    app()->forgetInstance('payments.config');

    config(['payments.subscriptions.logging.table' => 'custom_subscription_transactions']);

    $provider = new PaymentServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('configureModel');
    $method->setAccessible(true);
    $method->invoke($provider);

    $model = new SubscriptionTransaction;

    expect($model->getTable())->toBe('custom_subscription_transactions');
});

test('it defaults to subscription_transactions table if config is missing', function () {
    config(['payments.subscriptions.logging.table' => null]);

    $model = new SubscriptionTransaction;

    expect($model->getTable())->toBe('subscription_transactions');
});

test('it casts attributes correctly', function () {
    $now = Carbon::now();

    $transaction = SubscriptionTransaction::create([
        'subscription_code' => 'SUB_cast_test',
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_123',
        'customer_email' => 'test@example.com',
        'amount' => 5000.50,
        'currency' => 'NGN',
        'next_payment_date' => $now,
        'metadata' => ['order_id' => 1],
    ]);

    $amount = $transaction->amount;
    expect(is_string($amount) ? $amount : (string) number_format((float) $amount, 2, '.', ''))->toBe('5000.50')
        ->and($transaction->next_payment_date)->toBeInstanceOf(Carbon::class)
        ->and($transaction->metadata)->toBeInstanceOf(ArrayObject::class)
        ->and($transaction->metadata['order_id'])->toBe(1);
});

test('it uses the testing connection while running under the testing environment', function () {
    $model = new SubscriptionTransaction;

    expect($model->getConnectionName())->toBe('testing');
});

test('scope active filters active and non-renewing subscriptions', function () {
    SubscriptionTransaction::create(subscriptionTransactionData(['status' => 'active']));
    SubscriptionTransaction::create(subscriptionTransactionData(['status' => 'non-renewing']));
    SubscriptionTransaction::create(subscriptionTransactionData(['status' => 'cancelled']));
    SubscriptionTransaction::create(subscriptionTransactionData(['status' => 'completed']));

    $active = SubscriptionTransaction::active()->get();

    expect($active)->toHaveCount(2)
        ->and($active->pluck('status')->toArray())->toEqualCanonicalizing(['active', 'non-renewing']);
});

test('scope cancelled filters only cancelled subscriptions', function () {
    SubscriptionTransaction::create(subscriptionTransactionData(['status' => 'cancelled']));
    SubscriptionTransaction::create(subscriptionTransactionData(['status' => 'active']));

    $cancelled = SubscriptionTransaction::cancelled()->get();

    expect($cancelled)->toHaveCount(1)
        ->and($cancelled->first()->status)->toBe('cancelled');
});

test('scope forCustomer filters by customer email', function () {
    SubscriptionTransaction::create(subscriptionTransactionData(['customer_email' => 'alice@example.com']));
    SubscriptionTransaction::create(subscriptionTransactionData(['customer_email' => 'bob@example.com']));

    $result = SubscriptionTransaction::forCustomer('alice@example.com')->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->customer_email)->toBe('alice@example.com');
});

test('scope forPlan filters by plan code', function () {
    SubscriptionTransaction::create(subscriptionTransactionData(['plan_code' => 'PLN_A']));
    SubscriptionTransaction::create(subscriptionTransactionData(['plan_code' => 'PLN_B']));

    $result = SubscriptionTransaction::forPlan('PLN_A')->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->plan_code)->toBe('PLN_A');
});

test('scopes can be chained together', function () {
    SubscriptionTransaction::create(subscriptionTransactionData([
        'status' => 'active',
        'customer_email' => 'combo@example.com',
        'plan_code' => 'PLN_COMBO',
    ]));
    SubscriptionTransaction::create(subscriptionTransactionData([
        'status' => 'cancelled',
        'customer_email' => 'combo@example.com',
        'plan_code' => 'PLN_COMBO',
    ]));

    $result = SubscriptionTransaction::active()
        ->forCustomer('combo@example.com')
        ->forPlan('PLN_COMBO')
        ->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->status)->toBe('active');
});
