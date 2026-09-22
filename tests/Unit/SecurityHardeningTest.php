<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Http\Middleware\HealthEndpointMiddleware;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;

uses(RefreshDatabase::class);

// Some of these assert production-only behaviour. The environment is restored
// afterwards because RefreshDatabase tears the database down after the test
// body, and it will stop to ask for confirmation if it believes it is live.
afterEach(function () {
    app()['env'] = 'testing';
});

/**
 * Findings from the adversarial pass of the certification audit.
 */

// ---------------------------------------------------------------------------
// The health endpoint's token is a secret, so it is compared like one
// ---------------------------------------------------------------------------

test('the health token is compared in constant time', function () {
    // in_array() with strict comparison stops at the first differing byte,
    // which leaks a valid token's length and prefix to anyone who can time the
    // response. The package verifies every webhook signature with hash_equals;
    // this is the same problem and now gets the same treatment.
    $method = (new ReflectionClass(HealthEndpointMiddleware::class))->getMethod('tokenIsAllowed');
    $method->setAccessible(true);
    $middleware = new HealthEndpointMiddleware;

    expect($method->invoke($middleware, 'right-token', ['right-token']))->toBeTrue()
        ->and($method->invoke($middleware, 'right-token', ['other', 'right-token']))->toBeTrue()
        ->and($method->invoke($middleware, 'wrong-token', ['right-token']))->toBeFalse()
        // A prefix of a valid token must not be accepted.
        ->and($method->invoke($middleware, 'right', ['right-token']))->toBeFalse()
        ->and($method->invoke($middleware, 'right-token-plus', ['right-token']))->toBeFalse()
        ->and($method->invoke($middleware, 'anything', []))->toBeFalse();
});

test('the health endpoint refuses a wrong token and accepts the right one', function () {
    config([
        'payments.health_check.require_auth' => true,
        'payments.health_check.allowed_tokens' => ['s3cret-health-token'],
    ]);
    app()->forgetInstance('payments.config');

    $this->getJson('/payments/health', ['X-Health-Token' => 'nope'])->assertStatus(401);
    $this->getJson('/payments/health', ['X-Health-Token' => 's3cret-health-token'])->assertOk();
});

// ---------------------------------------------------------------------------
// Disabling signature verification must never be silent
// ---------------------------------------------------------------------------

test('disabling webhook signature verification is logged as an error in production', function () {
    // With verification off, anyone who can reach the webhook URL can POST a
    // charge.success for a reference they guessed or observed. The switch stays
    // supported for local replay, but it must announce itself.
    app()['env'] = 'production';
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');
    Cache::flush();

    $logged = [];
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->andReturnUsing(function ($message, $context = []) use (&$logged) {
        $logged[] = $message;

        return true;
    });
    Log::shouldReceive('info')->andReturnTrue();
    Log::shouldReceive('warning')->andReturnTrue();

    $request = WebhookRequest::create('/payments/webhook/paystack', 'POST', [], [], [], [], '{}');
    $request->setContainer(app());
    $request->setRouteResolver(fn () => new class
    {
        public function parameter(string $name): string
        {
            return 'paystack';
        }
    });

    expect($request->authorize())->toBeTrue()
        ->and(implode(' ', $logged))->toContain('DISABLED');
});

test('the warning is rate limited so an active site does not drown its log', function () {
    app()['env'] = 'production';
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');
    Cache::flush();

    $errors = 0;
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->andReturnUsing(function () use (&$errors) {
        $errors++;

        return true;
    });
    Log::shouldReceive('info')->andReturnTrue();
    Log::shouldReceive('warning')->andReturnTrue();

    $make = function () {
        $request = WebhookRequest::create('/payments/webhook/paystack', 'POST', [], [], [], [], '{}');
        $request->setContainer(app());
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $name): string
            {
                return 'paystack';
            }
        });

        return $request;
    };

    $make()->authorize();
    $make()->authorize();
    $make()->authorize();

    expect($errors)->toBe(1);
});

test('the warning stays quiet in local and testing', function () {
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');
    Cache::flush();

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->never();
    Log::shouldReceive('info')->andReturnTrue();
    Log::shouldReceive('warning')->andReturnTrue();

    $request = WebhookRequest::create('/payments/webhook/paystack', 'POST', [], [], [], [], '{}');
    $request->setContainer(app());
    $request->setRouteResolver(fn () => new class
    {
        public function parameter(string $name): string
        {
            return 'paystack';
        }
    });

    expect($request->authorize())->toBeTrue();
});
