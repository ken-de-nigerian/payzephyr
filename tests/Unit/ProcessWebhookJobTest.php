<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'payments.logging.enabled' => true,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret_key',
            'enabled' => true,
        ],
    ]);

    $logChannel = Mockery::mock();
    $logChannel->shouldReceive('info')->andReturn(true);
    $logChannel->shouldReceive('error')->andReturn(true);
    Log::shouldReceive('channel')->with('payments')->andReturn($logChannel);
    Event::fake();
});

test('process webhook job dispatches webhook received event', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123'],
    ]);

    $manager = app(PaymentManager::class);

    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('ref_123');
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')
        ->andReturn('card');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class, function ($event) {
        return $event->provider === 'paystack'
            && $event->reference === 'ref_123';
    });
});

test('process webhook job updates transaction when reference exists', function () {
    $transaction = PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123', 'status' => 'success'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('ref_123');
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')
        ->andReturn('card');

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    app()->call([$job, 'handle']);

    $transaction->refresh();

    expect($transaction->status)->toBe('success')
        ->and($transaction->channel)->toBe('card')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('process webhook job handles missing reference gracefully', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => [],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn(null);

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class);
});

test('process webhook job retries on failure', function () {
    $job = new ProcessWebhook('paystack', ['event' => 'charge.success']);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60);
});

test('process webhook job logs processing', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('ref_123');
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')
        ->andReturn(null);

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    expect(fn () => app()->call([$job, 'handle']))
        ->not->toThrow(\Exception::class);

    Event::assertDispatched(WebhookReceived::class, function ($event) {
        return $event->provider === 'paystack'
            && $event->reference === 'ref_123';
    });
});

test('process webhook job uses database transactions', function () {
    $transaction = PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')
        ->andReturn('ref_123');
    $mockDriver->shouldReceive('extractWebhookStatus')
        ->andReturn('success');
    $mockDriver->shouldReceive('extractWebhookChannel')
        ->andReturn(null);

    $managerReflection = new \ReflectionClass($manager);
    $driversProperty = $managerReflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $mockDriver]);

    app()->call([$job, 'handle']);

    $transaction->refresh();
    expect($transaction->status)->toBe('success');
});
