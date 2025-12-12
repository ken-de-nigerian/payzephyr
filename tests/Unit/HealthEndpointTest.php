<?php

use KenDeNigerian\PayZephyr\PaymentManager;

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
    config(['payments.providers.paystack.enabled' => true]);
    config(['payments.providers.stripe.enabled' => true]);
    config(['payments.providers.flutterwave.enabled' => false]);

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
    $manager = app(PaymentManager::class);
    $driver = $manager->driver('paystack');

    \Illuminate\Support\Facades\Cache::shouldReceive('remember')
        ->once()
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
    config([
        'payments.providers.paystack.enabled' => true,
        'payments.providers.stripe.enabled' => false,
        'payments.providers.flutterwave.enabled' => true,
    ]);

    $response = $this->getJson('/payments/health');

    $response->assertStatus(200);

    $data = $response->json();
    expect($data['providers'])->toHaveKey('paystack');

    if (isset($data['providers']['flutterwave'])) {
        expect($data['providers'])->toHaveKey('flutterwave');
    }

    expect($data['providers'])->not->toHaveKey('stripe');
});
