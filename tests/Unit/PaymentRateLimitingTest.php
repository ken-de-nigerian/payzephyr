<?php

use Illuminate\Support\Facades\RateLimiter;
use KenDeNigerian\PayZephyr\Facades\Payment;

test('payment rate limits by user when authenticated', function () {
    $user = new class
    {
        public function getAuthIdentifier()
        {
            return 123;
        }
    };

    mockAuthGuard(check: true, id: 123);

    $key = 'payment_charge:user_123';
    RateLimiter::clear($key);

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($key, 60);
    }

    expect(RateLimiter::tooManyAttempts($key, 10))->toBeTrue();

    expect(fn () => Payment::amount(10000)
        ->email('test@example.com')
        ->callback('https://example.com/callback')
        ->charge())
        ->toThrow(\KenDeNigerian\PayZephyr\Exceptions\ChargeException::class, 'Too many payment attempts');
});

test('payment rate limits by email when not authenticated', function () {
    mockAuthGuard(check: false);

    $email = 'test@example.com';
    $key = 'payment_charge:email_'.hash('sha256', $email);
    RateLimiter::clear($key);

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($key, 60);
    }

    expect(RateLimiter::tooManyAttempts($key, 10))->toBeTrue();

    expect(fn () => Payment::amount(10000)
        ->email($email)
        ->callback('https://example.com/callback')
        ->charge())
        ->toThrow(\KenDeNigerian\PayZephyr\Exceptions\ChargeException::class, 'Too many payment attempts');
});

test('payment rate limits by IP when no email provided', function () {
    mockAuthGuard(check: false);

    $request = new \Illuminate\Http\Request;
    $request->server->set('REMOTE_ADDR', '192.168.1.1');

    app()->instance('request', $request);

    $key = 'payment_charge:ip_192.168.1.1';
    RateLimiter::clear($key);

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($key, 60);
    }

    expect(RateLimiter::tooManyAttempts($key, 10))->toBeTrue();
});

test('payment rate limits fallback to global when no request available', function () {
    mockAuthGuard(check: false);

    if (app()->bound('request')) {
        app()->forgetInstance('request');
    }

    $payment = new \KenDeNigerian\PayZephyr\Payment(app(\KenDeNigerian\PayZephyr\PaymentManager::class));
    $reflection = new ReflectionClass($payment);
    $method = $reflection->getMethod('getRateLimitKey');
    $method->setAccessible(true);

    $key = $method->invoke($payment);

    expect($key)->toBe('payment_charge:global');
});

test('payment allows requests within rate limit', function () {
    mockAuthGuard(check: false);

    $email = 'test@example.com';
    $key = 'payment_charge:email_'.hash('sha256', $email);
    RateLimiter::clear($key);

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit($key, 60);
    }

    expect(RateLimiter::tooManyAttempts($key, 10))->toBeFalse();
});
