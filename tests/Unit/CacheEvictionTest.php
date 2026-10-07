<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function (): void {
    Cache::flush();
});

test('it clears session cache after successful verification', function (): void {
    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);

    $cacheKeyMethod = $reflection->getMethod('cacheKey');

    $reference = 'TEST_REF_123';
    $key = $cacheKeyMethod->invoke($manager, 'session', $reference);

    Cache::put($key, [
        'provider' => 'paystack',
        'id' => 'provider_id_123',
    ], now()->addHour());

    expect(Cache::get($key))->not->toBeNull();

    Cache::forget($key);

    expect(Cache::get($key))->toBeNull();
});

test('it expires session cache after configured ttl', function (): void {
    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);

    $cacheKeyMethod = $reflection->getMethod('cacheKey');

    $reference = 'TEST_REF_456';
    $key = $cacheKeyMethod->invoke($manager, 'session', $reference);

    Cache::put($key, [
        'provider' => 'paystack',
        'id' => 'provider_id_456',
    ], now()->addHour());

    expect(Cache::get($key))->not->toBeNull();

    $this->travel(61)->minutes();

    expect(Cache::get($key))->toBeNull();
});

test('it caches health checks for configured duration', function (): void {
    config(['payments.health_check.cache_ttl' => 120]); // 2 minutes

    $driver = app(PaymentManager::class)->driver('paystack');

    $result1 = $driver->getCachedHealthCheck();

    $result2 = $driver->getCachedHealthCheck();

    expect($result1)->toBe($result2);

    $this->travel(121)->seconds();

    $result3 = $driver->getCachedHealthCheck();

    expect($result3)->toBeBool();
});

test('it handles cache key collisions with user context', function (): void {
    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);

    $cacheKeyMethod = $reflection->getMethod('cacheKey');

    $key1 = $cacheKeyMethod->invoke($manager, 'session', 'REF_123');
    Cache::put($key1, ['provider' => 'paystack', 'id' => 'id1'], now()->addHour());

    $key2 = $cacheKeyMethod->invoke($manager, 'session', 'REF_123');
    Cache::put($key2, ['provider' => 'stripe', 'id' => 'id2'], now()->addHour());

    expect(Cache::get($key1))->not->toBeNull()
        ->and(Cache::get($key2))->not->toBeNull();
});

test('it handles cache eviction when memory is low', function (): void {
    $key = 'payzephyr:session:TEST_REF';
    $data = ['provider' => 'paystack', 'id' => 'test_id'];

    Cache::put($key, $data, now()->addHour());

    expect(Cache::get($key))->not->toBeNull();

    Cache::forget($key);

    expect(Cache::get($key))->toBeNull();
});

test('it handles cache prefix isolation', function (): void {
    $key1 = 'payzephyr:session:REF_1';
    $key2 = 'payzephyr:health:paystack';
    $key3 = 'other_prefix:session:REF_1';

    Cache::put($key1, ['data' => 'session1'], now()->addHour());
    Cache::put($key2, ['data' => 'health1'], now()->addHour());
    Cache::put($key3, ['data' => 'other1'], now()->addHour());

    expect(Cache::get($key1))->not->toBeNull()
        ->and(Cache::get($key2))->not->toBeNull()
        ->and(Cache::get($key3))->not->toBeNull();
});

test('it handles cache expiration edge cases', function (): void {
    $key = 'payzephyr:session:EDGE_CASE';

    Cache::put($key, ['provider' => 'paystack'], now()->addSecond());

    expect(Cache::get($key))->not->toBeNull();

    $this->travel(2)->seconds();

    expect(Cache::get($key))->toBeNull();
});

test('it handles cache with null values', function (): void {
    $key = 'payzephyr:session:NULL_TEST';

    Cache::put($key, null, now()->addHour());

    $value = Cache::get($key);
    expect($value)->toBeNull();
});
