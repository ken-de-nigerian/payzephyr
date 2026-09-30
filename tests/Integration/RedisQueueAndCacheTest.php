<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

/*
 * The rest of the suite runs the queue synchronously and caches in an array.
 * These run against a real Redis - CI's Redis job sets REDIS_HOST - to prove
 * what those stand-ins cannot: that a webhook job survives being serialized
 * onto a queue and picked up by a worker, and that the in-flight claims the
 * package takes with Cache::add are atomic on the store production uses.
 */

beforeEach(function () {
    $host = getenv('REDIS_HOST');

    if (! is_string($host) || $host === '') {
        $this->markTestSkipped('Runs against Redis: set REDIS_HOST (the CI Redis job does).');
    }

    $port = getenv('REDIS_PORT');
    $connection = fn (int $database): array => [
        'host' => $host,
        'port' => is_string($port) && $port !== '' ? $port : '6379',
        'database' => $database,
    ];

    config([
        'database.redis.client' => 'phpredis',
        'database.redis.default' => $connection(0),
        'database.redis.cache' => $connection(1),
        'queue.connections.redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'payzephyr-test',
            'retry_after' => 90,
            'block_for' => null,
        ],
        'queue.default' => 'redis',
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'cache.default' => 'redis',
    ]);

    Redis::connection('default')->flushdb();
    Redis::connection('cache')->flushdb();
});

test('a webhook queued on redis is processed by a worker', function () {
    config(['payments.logging.enabled' => true]);

    PaymentTransaction::create([
        'reference' => 'REDIS_REF_1',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'NGN',
        'email' => 'buyer@example.com',
    ]);

    ProcessWebhook::dispatch('paystack', [
        'event' => 'charge.success',
        'data' => ['id' => 998877, 'reference' => 'REDIS_REF_1', 'status' => 'success', 'channel' => 'card'],
    ]);

    expect(Queue::connection('redis')->size('payzephyr-test'))->toBe(1)
        ->and(PaymentTransaction::where('reference', 'REDIS_REF_1')->value('status'))->toBe('pending');

    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'payzephyr-test', '--once' => true, '--stop-when-empty' => true]);

    expect(Queue::connection('redis')->size('payzephyr-test'))->toBe(0)
        ->and(PaymentTransaction::where('reference', 'REDIS_REF_1')->value('status'))->toBe('success');
});

function redisClaimManager(?Throwable $throws = null): PaymentManager
{
    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('getSupportedCurrencies')->andReturn(['NGN']);
    $driver->shouldReceive('healthCheck')->andReturn(true);
    $throws === null
        ? $driver->shouldReceive('charge')->andReturnUsing(fn (ChargeRequestDTO $request) => new ChargeResponseDTO(
            reference: (string) $request->reference,
            authorizationUrl: 'https://example.test/pay',
            accessCode: 'code',
            status: 'pending',
            provider: 'primary',
        ))
        : $driver->shouldReceive('charge')->andThrow($throws);

    $manager = new PaymentManager;
    $property = new ReflectionProperty($manager, 'drivers');
    $property->setValue($manager, ['primary' => $driver]);

    return $manager;
}

function redisClaimRequest(string $reference): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray(['amount' => 100.00, 'currency' => 'NGN', 'email' => 'buyer@example.com', 'reference' => $reference]);
}

test('a second charge for a reference in flight is refused when the claim lives in redis', function () {
    config(['payments.health_check.enabled' => false]);

    // What a first request still waiting on the provider leaves behind.
    Cache::store('redis')->add('payzephyr:charge-inflight:REDIS_ORDER', true, 300);

    expect(fn () => redisClaimManager()->chargeWithFallback(redisClaimRequest('REDIS_ORDER'), ['primary']))
        ->toThrow(ProviderException::class, 'A charge for reference [REDIS_ORDER] is already in progress');
});

test('an in-flight claim taken in redis is released when the charge fails outright', function () {
    config(['payments.health_check.enabled' => false]);

    expect(fn () => redisClaimManager(new RuntimeException('card declined'))->chargeWithFallback(redisClaimRequest('REDIS_RELEASE'), ['primary']))
        ->toThrow(ProviderException::class)
        ->and(Cache::store('redis')->has('payzephyr:charge-inflight:REDIS_RELEASE'))->toBeFalse();
});
