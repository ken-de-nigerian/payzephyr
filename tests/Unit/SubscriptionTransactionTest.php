<?php

declare(strict_types=1);

/**
 * Coverage for src/Models/SubscriptionTransaction.php, following the same
 * pattern used for PaymentTransaction in PaymentTransactionTest.php /
 * PaymentTransactionCompleteTest.php / PaymentTransactionCoverageTest.php /
 * PaymentTransactionFallbackTest.php.
 *
 * The `subscription_transactions` table is already created by the package's
 * migrations, which Tests\TestCase::setUp() runs for every test in this
 * suite, so no manual Schema::create() is needed here.
 *
 * A small namespaced shadow of the global app() helper is declared below,
 * scoped to KenDeNigerian\PayZephyr\Models (PHP resolves unqualified function
 * calls against the *calling* code's namespace before falling back to the
 * global namespace). SubscriptionTransaction::setAttribute()/getAttribute()
 * branch on `(float) app()->version() < 11.0`, but this suite only runs
 * against Laravel 12, so that legacy branch is otherwise permanently
 * unreachable. The shadow only intercepts app() when a test explicitly sets
 * $GLOBALS['__pz_fake_laravel_version']; every other call (including the
 * plain `app()`/`app(Something::class)` calls made elsewhere in the model)
 * transparently delegates to the real global app().
 */

namespace KenDeNigerian\PayZephyr\Models {
    if (! function_exists(__NAMESPACE__.'\\app')) {
        function app($abstract = null, array $parameters = [])
        {
            if ($abstract === null && array_key_exists('__pz_fake_laravel_version', $GLOBALS)) {
                return new class($GLOBALS['__pz_fake_laravel_version'])
                {
                    public function __construct(private string $version) {}

                    public function version(): string
                    {
                        return $this->version;
                    }
                };
            }

            return \app($abstract, $parameters);
        }
    }
}

namespace {

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

    // ==================== Table name coverage ====================

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

    // ==================== Casts coverage ====================

    test('it casts attributes correctly', function () {
        $now = Carbon::now();
        $laravelVersion = (float) app()->version();

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
            ->and($transaction->next_payment_date)->toBeInstanceOf(Carbon::class);

        if ($laravelVersion >= 11.0) {
            expect($transaction->metadata)->toBeInstanceOf(ArrayObject::class)
                ->and($transaction->metadata['order_id'])->toBe(1);
        } else {
            expect($transaction->metadata)->toBeArray()
                ->and($transaction->metadata['order_id'])->toBe(1);
        }
    });

    // ==================== getConnectionName coverage ====================

    test('it uses the testing connection while running under the testing environment', function () {
        $model = new SubscriptionTransaction;

        expect($model->getConnectionName())->toBe('testing');
    });

    // ==================== Scope coverage ====================

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

    // ==================== Legacy (<11.0) setAttribute/getAttribute coverage ====================
    // See the namespaced app() shadow declared at the top of this file.

    test('setAttribute json-encodes metadata arrays on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new SubscriptionTransaction;
            $model->setAttribute('metadata', ['foo' => 'bar']);

            expect($model->getAttributes()['metadata'])->toBe(json_encode(['foo' => 'bar']));
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    test('setAttribute stores null metadata as-is on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new SubscriptionTransaction;
            $model->setAttribute('metadata', null);

            expect($model->getAttributes()['metadata'])->toBeNull();
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    test('setAttribute stores non-array metadata values as-is on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new SubscriptionTransaction;
            $model->setAttribute('metadata', 'already-a-string');

            expect($model->getAttributes()['metadata'])->toBe('already-a-string');
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    test('setAttribute for non-metadata keys delegates to the parent implementation on pre-Laravel-11 versions', function () {
        $GLOBALS['__pz_fake_laravel_version'] = '10.48.0';

        try {
            $model = new SubscriptionTransaction;
            $model->setAttribute('status', 'active');

            expect($model->getAttributes()['status'])->toBe('active');
        } finally {
            unset($GLOBALS['__pz_fake_laravel_version']);
        }
    });

    // Note: SubscriptionTransaction::getAttribute()'s pre-Laravel-11 branch
    // (is_string($value) && json_decode(...)) is not independently testable even
    // with the app()-version shadow above: casts() unconditionally registers a
    // cast for 'metadata' (AsArrayObject on 11+, plain 'array' below that), and
    // Eloquent's own array cast already json_decodes the raw attribute before
    // this class's getAttribute() ever inspects $value - so by the time the
    // custom check runs, $value is never the raw string it's guarding against.
    // That branch is effectively unreachable via the public API in this
    // environment, so it's intentionally left uncovered rather than asserting
    // behavior that isn't actually exercising the target code path.

}
