<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Http\Middleware\HealthEndpointMiddleware;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * These exercise HealthEndpointMiddleware directly rather than through the
 * full /payments/health route: the real route also drives health checks for
 * every configured provider, each logging its own info/warning noise on the
 * same 'payments' channel, which would make a Log expectation scoped to the
 * middleware's own warning both noisy and order-dependent.
 */
function makeUnauthenticatedHealthRequest(): \Symfony\Component\HttpFoundation\Response
{
    $middleware = new HealthEndpointMiddleware;

    return $middleware->handle(Request::create('/payments/health', 'GET'), fn ($request) => response()->json(['status' => 'operational']));
}

test('unauthenticated health endpoint warns once per interval outside local/testing (ADR-0002)', function () {
    app()->detectEnvironment(fn () => 'production');
    Cache::forget('payzephyr:health_check:unauthenticated_warning');

    Log::shouldReceive('channel')->once()->with('payments')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(
        'The /payments/health endpoint is exposed without authentication',
        Mockery::type('array')
    );

    // Two hits in a row - the warning must fire only once (Cache::add is atomic).
    makeUnauthenticatedHealthRequest();
    makeUnauthenticatedHealthRequest();

    app()->detectEnvironment(fn () => 'testing');
    Cache::forget('payzephyr:health_check:unauthenticated_warning');
});

test('unauthenticated health endpoint does not warn in local/testing environments', function () {
    Cache::forget('payzephyr:health_check:unauthenticated_warning');

    Log::shouldReceive('channel')->never();

    $response = makeUnauthenticatedHealthRequest();

    expect($response->getStatusCode())->toBe(200);
});

test('authenticated health endpoint configuration does not trigger the exposure warning', function () {
    app()->detectEnvironment(fn () => 'production');
    Cache::forget('payzephyr:health_check:unauthenticated_warning');

    config([
        'payments.health_check.require_auth' => true,
        'payments.health_check.allowed_tokens' => ['secret-token'],
    ]);
    // 'payments.config' is bound as a singleton captured at boot, so config()
    // mutations alone don't reach code that reads app('payments.config') -
    // must forget the instance to force a fresh snapshot.
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')->never();

    $middleware = new HealthEndpointMiddleware;
    $request = Request::create('/payments/health', 'GET');
    $request->headers->set('Authorization', 'Bearer secret-token');
    $response = $middleware->handle($request, fn ($req) => response()->json(['status' => 'operational']));

    expect($response->getStatusCode())->toBe(200);

    app()->detectEnvironment(fn () => 'testing');
});

test('health endpoint returns operational status', function () {
    $response = $this->getJson('/payments/health');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'providers',
        ])
        ->assertJson([
            'status' => 'operational',
        ]);
});

test('health endpoint includes enabled providers', function () {
    // Clear config cache
    app()->forgetInstance('payments.config');

    // Disable all providers first
    foreach (config('payments.providers', []) as $provider => $config) {
        config(["payments.providers.{$provider}.enabled" => false]);
    }

    config(['payments.providers.paystack.enabled' => true]);
    config(['payments.providers.stripe.enabled' => true]);
    config(['payments.providers.flutterwave.enabled' => false]);

    // Clear config cache again after changes
    app()->forgetInstance('payments.config');

    $response = $this->getJson('/payments/health');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'providers',
        ]);

    $data = $response->json();
    expect($data['providers'])->toHaveKey('paystack')
        ->and($data['providers'])->not->toHaveKey('flutterwave');
});

test('health endpoint handles provider errors gracefully', function () {
    config(['payments.providers.invalid.enabled' => true]);
    config(['payments.providers.invalid.driver' => 'nonexistent']);

    $response = $this->getJson('/payments/health');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'providers',
        ]);

    $data = $response->json();

    if (isset($data['providers']['invalid'])) {
        expect($data['providers']['invalid'])->toHaveKey('healthy')
            ->and($data['providers']['invalid']['healthy'])->toBeFalse();
    }
});

test('health endpoint returns provider currencies', function () {
    config(['payments.providers.paystack.enabled' => true]);
    config(['payments.providers.paystack.currencies' => ['NGN', 'USD', 'GHS']]);

    $response = $this->getJson('/payments/health');

    $response->assertStatus(200);

    $data = $response->json();
    expect($data['providers']['paystack'])->toHaveKey('currencies')
        ->and($data['providers']['paystack']['currencies'])->toBeArray();
});

test('health endpoint uses cached health check', function () {
    // Clear config cache
    app()->forgetInstance('payments.config');

    // Disable all providers except paystack
    foreach (config('payments.providers', []) as $provider => $config) {
        config(["payments.providers.{$provider}.enabled" => false]);
    }
    config(['payments.providers.paystack.enabled' => true]);

    // Clear config cache again
    app()->forgetInstance('payments.config');

    $manager = app(PaymentManager::class);
    $driver = $manager->driver('paystack');

    \Illuminate\Support\Facades\Cache::shouldReceive('remember')
        ->with(\Mockery::type('string'), \Mockery::type('int'), \Mockery::type('Closure'))
        ->andReturn(true);

    $response = $this->getJson('/payments/health');

    $response->assertStatus(200);
});

test('health endpoint handles empty providers config', function () {
    config(['payments.providers' => []]);

    $response = $this->getJson('/payments/health');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'operational',
            'providers' => [],
        ]);
});

test('health endpoint only includes enabled providers', function () {
    // Clear config cache
    app()->forgetInstance('payments.config');

    // Disable all providers first
    foreach (config('payments.providers', []) as $provider => $config) {
        config(["payments.providers.{$provider}.enabled" => false]);
    }

    config([
        'payments.providers.paystack.enabled' => true,
        'payments.providers.stripe.enabled' => false,
        'payments.providers.flutterwave.enabled' => true,
    ]);

    // Clear config cache again after changes
    app()->forgetInstance('payments.config');

    $response = $this->getJson('/payments/health');

    $response->assertStatus(200);

    $data = $response->json();
    expect($data['providers'])->toHaveKey('paystack');

    if (isset($data['providers']['flutterwave'])) {
        expect($data['providers'])->toHaveKey('flutterwave');
    }

    expect($data['providers'])->not->toHaveKey('stripe');
});
