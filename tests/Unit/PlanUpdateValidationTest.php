<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;

/**
 * A plan update is held to the same rules as creating a plan.
 *
 * createPlan() receives a SubscriptionPlanDTO, which validates itself.
 * updatePlan() receives a raw array, and nothing checked it. Two consequences
 * were live in the drivers:
 *
 * - An interval outside the four PayZephyr understands - `yearly`,
 *   `quarterly`, or Stripe's own `year` - fell through Stripe's, Square's,
 *   Mollie's and PayPal's interval mappers to a monthly default. A plan meant
 *   to bill once a year billed twelve times.
 * - An amount of zero, which creation refuses, silently produced a free plan.
 *
 * Every driver's updatePlan() now calls assertValidUpdates() before contacting
 * the provider, so a rejected update changes nothing anywhere.
 */
test('every interval PayZephyr documents is accepted', function (string $interval) {
    SubscriptionPlanDTO::assertValidUpdates(['interval' => $interval]);

    expect(true)->toBeTrue();
})->with(SubscriptionPlanDTO::INTERVALS);

test('an interval PayZephyr does not understand is refused rather than billed monthly', function (string $interval) {
    expect(fn () => SubscriptionPlanDTO::assertValidUpdates(['interval' => $interval]))
        ->toThrow(PlanException::class, 'Plan interval must be one of');
})->with([
    'the natural spelling' => ['yearly'],
    'a real cadence PayZephyr cannot express' => ['quarterly'],
    "Stripe's own word" => ['year'],
    'wrong case' => ['Monthly'],
    'empty' => [''],
]);

test('a non-string interval is refused', function () {
    expect(fn () => SubscriptionPlanDTO::assertValidUpdates(['interval' => 12]))
        ->toThrow(PlanException::class);
});

test('an amount of zero is refused rather than creating a free plan', function (mixed $amount) {
    expect(fn () => SubscriptionPlanDTO::assertValidUpdates(['amount' => $amount]))
        ->toThrow(PlanException::class, 'greater than zero');
})->with([
    'zero' => [0],
    'zero as a string' => ['0'],
    'negative' => [-5],
    'not a number' => ['ten'],
    'null' => [null],
]);

test('a positive amount is accepted, as a number or a numeric string', function (mixed $amount) {
    SubscriptionPlanDTO::assertValidUpdates(['amount' => $amount]);

    expect(true)->toBeTrue();
})->with([[25], [25.50], ['25.50']]);

test('a plan cannot be renamed to nothing', function (mixed $name) {
    expect(fn () => SubscriptionPlanDTO::assertValidUpdates(['name' => $name]))
        ->toThrow(PlanException::class, 'empty');
})->with([[''], ['   '], [null]]);

test('an update is partial, so keys that are absent are not checked', function () {
    SubscriptionPlanDTO::assertValidUpdates([]);
    SubscriptionPlanDTO::assertValidUpdates(['description' => 'New copy', 'metadata' => ['a' => 1]]);

    expect(true)->toBeTrue();
});

test('creating a plan and updating one agree on the valid intervals', function () {
    // One list, used by both. If they ever drift, a plan could be created
    // that can never be updated, or updated to something that could never
    // have been created.
    foreach (SubscriptionPlanDTO::INTERVALS as $interval) {
        $plan = new SubscriptionPlanDTO(name: 'Plan', amount: 10, interval: $interval);

        expect($plan->interval)->toBe($interval);
    }

    expect(fn () => new SubscriptionPlanDTO(name: 'Plan', amount: 10, interval: 'yearly'))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// ...and every driver actually applies it
// ---------------------------------------------------------------------------

/**
 * A driver whose transport has nothing queued, so any provider call at all
 * fails with a different error. If a driver ever stops calling
 * assertValidUpdates(), it will reach its provider instead and these tests
 * will see the wrong message - which is the point. Testing the rule alone was
 * how two drivers went on reading unguarded amounts after the guards existed.
 */
function driverThatMustNotCallOut(string $provider): object
{
    $silent = new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([]))]);

    if ($provider === 'stripe') {
        $driver = new KenDeNigerian\PayZephyr\Drivers\StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);
        $driver->setStripeClient(new class
        {
            public function __get(string $name): never
            {
                throw new RuntimeException("Stripe was contacted ($name) for an update that should have been refused.");
            }
        });

        return $driver;
    }

    $driver = match ($provider) {
        'paystack' => new KenDeNigerian\PayZephyr\Drivers\PaystackDriver(['secret_key' => 'sk_test_x', 'currencies' => ['NGN']]),
        'flutterwave' => new KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver(['secret_key' => 'FLWSECK_TEST-x', 'currencies' => ['NGN']]),
        'paypal' => new KenDeNigerian\PayZephyr\Drivers\PayPalDriver(['client_id' => 'C', 'client_secret' => 'S', 'currencies' => ['USD']]),
        'square' => new KenDeNigerian\PayZephyr\Drivers\SquareDriver(['access_token' => 'T', 'location_id' => 'L', 'currencies' => ['USD']]),
        'mollie' => new KenDeNigerian\PayZephyr\Drivers\MollieDriver(['api_key' => 'test_x', 'currencies' => ['EUR']]),
    };

    $driver->setClient($silent);

    return $driver;
}

test('no driver will move a plan to an interval it does not understand', function (string $provider) {
    expect(fn () => driverThatMustNotCallOut($provider)->updatePlan('PLAN_1', ['interval' => 'yearly']))
        ->toThrow(PlanException::class, 'Plan interval must be one of');
})->with(['paystack', 'flutterwave', 'paypal', 'square', 'mollie', 'stripe']);

test('no driver will update a plan to cost nothing', function (string $provider) {
    expect(fn () => driverThatMustNotCallOut($provider)->updatePlan('PLAN_1', ['amount' => 0]))
        ->toThrow(PlanException::class, 'greater than zero');
})->with(['paystack', 'flutterwave', 'paypal', 'square', 'mollie', 'stripe']);
