<?php

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\ProviderDetectorInterface;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Events\PaymentVerificationFailed;
use KenDeNigerian\PayZephyr\Events\PaymentVerificationSuccess;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\DriverFactory;
use KenDeNigerian\PayZephyr\Services\MetadataSanitizer;

/**
 * Injects a mocked DriverInterface directly into a real PaymentManager's
 * protected $drivers cache, bypassing DriverFactory/config resolution
 * entirely. Mirrors the makeQueryManager() helper in SubscriptionQueryTest.
 */
function injectMockedDriver(PaymentManager $manager, string $provider, DriverInterface $driver): void
{
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, [$provider => $driver]);
}

afterEach(function () {
    if (app()->bound('request')) {
        app()->forgetInstance('request');
    }
});

test('chargeWithFallback skips a provider that fails its health check', function () {
    app()->forgetInstance('payments.config');
    config(['payments.health_check.enabled' => true]);

    // A plain DriverInterface mock has no getCachedHealthCheck() - that method
    // lives on AbstractDriver, not the interface - so the health check resolves
    // through the interface's own healthCheck(). See
    // PaymentManager::driverIsHealthy() and CustomDriverCompatibilityTest.
    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('healthCheck')->once()->andReturn(false);
    $driver->shouldNotReceive('charge');

    $manager = new PaymentManager;
    injectMockedDriver($manager, 'unhealthy', $driver);

    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect(fn () => $manager->chargeWithFallback($request, ['unhealthy']))
        ->toThrow(ProviderException::class, 'All payment providers failed');
});

test('verify dispatches PaymentVerificationSuccess for a successful response', function () {
    Event::fake([PaymentVerificationSuccess::class, PaymentVerificationFailed::class]);

    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('verify')
        ->once()
        ->with('success_ref')
        ->andReturn(new VerificationResponseDTO(
            reference: 'success_ref',
            status: 'success',
            amount: 1000.0,
            currency: 'NGN',
        ));

    $manager = new PaymentManager;
    injectMockedDriver($manager, 'mockprovider', $driver);

    $response = $manager->verify('success_ref', 'mockprovider');

    expect($response->status)->toBe('success');
    Event::assertDispatched(PaymentVerificationSuccess::class);
    Event::assertNotDispatched(PaymentVerificationFailed::class);
});

test('verify dispatches PaymentVerificationFailed for a failed response', function () {
    Event::fake([PaymentVerificationSuccess::class, PaymentVerificationFailed::class]);

    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('verify')
        ->once()
        ->with('failed_ref')
        ->andReturn(new VerificationResponseDTO(
            reference: 'failed_ref',
            status: 'failed',
            amount: 1000.0,
            currency: 'NGN',
        ));

    $manager = new PaymentManager;
    injectMockedDriver($manager, 'mockprovider', $driver);

    $response = $manager->verify('failed_ref', 'mockprovider');

    expect($response->status)->toBe('failed');
    Event::assertDispatched(PaymentVerificationFailed::class);
    Event::assertNotDispatched(PaymentVerificationSuccess::class);
});

test('logTransaction returns early without persisting when logging is disabled', function () {
    app()->forgetInstance('payments.config');
    config(['payments.logging.enabled' => false]);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('logTransaction');

    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'no_log_ref',
    ]);

    $response = new ChargeResponseDTO(
        reference: 'no_log_ref',
        authorizationUrl: 'https://example.com',
        accessCode: 'access_123',
        status: 'pending',
        metadata: [],
        provider: 'paystack'
    );

    $method->invoke($manager, $request, $response, 'paystack');

    expect(PaymentTransaction::where('reference', 'no_log_ref')->exists())->toBeFalse();
});

test('getCacheContext memoizes the resolved context and does not re-resolve on second call', function () {
    mockAuthGuard(check: true, id: 42);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('getCacheContext');
    $method->setAccessible(true);

    $first = $method->invoke($manager);
    $second = $method->invoke($manager);

    expect($first)->toBe('user_42')
        ->and($second)->toBe('user_42');
});

test('getCacheContext resolves context from an authenticated request user', function () {
    mockAuthGuard(check: false);

    $fakeUser = new class
    {
        public int $id = 555;
    };

    $request = new Request;
    app()->instance('request', $request);
    // Binding a Request via instance() triggers Laravel's auth "rebinding"
    // hook, which overwrites the user resolver to call $app['auth']. Set our
    // fake resolver AFTER the instance() call so it wins.
    $request->setUserResolver(fn () => $fakeUser);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('getCacheContext');
    $method->setAccessible(true);

    expect($method->invoke($manager))->toBe('user_555');
});

test('getCacheContext resolves context from a session user_id when no auth user is present', function () {
    mockAuthGuard(check: false);

    $request = new Request;
    app()->instance('request', $request);
    // See note above: reset the user resolver after instance() so it stays
    // null instead of the auth-based resolver Laravel wires in on rebind.
    $request->setUserResolver(fn () => null);

    $session = new Store('test_session', new ArraySessionHandler(120));
    $session->put('user_id', 789);
    $request->setLaravelSession($session);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('getCacheContext');
    $method->setAccessible(true);

    expect($method->invoke($manager))->toBe('user_789');
});

test('resolveVerificationContext DriverNotFoundException branch falls back to reference with empty metadata', function () {
    PaymentTransaction::create([
        'reference' => 'unknown_driver_ref',
        'provider' => 'totally_unconfigured_provider',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        // metadata intentionally omitted -> null when read back through the cast
    ]);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveVerificationContext');
    $method->setAccessible(true);

    $result = $method->invoke($manager, 'unknown_driver_ref', null);

    expect($result['provider'])->toBe('totally_unconfigured_provider')
        ->and($result['id'])->toBe('unknown_driver_ref');
});

test('updateTransactionFromVerification logs and swallows exceptions from the repository', function () {
    $repository = Mockery::mock(TransactionRepositoryInterface::class);
    $repository->shouldReceive('updateIfNotSuccessful')
        ->once()
        ->andThrow(new RuntimeException('database is unavailable'));

    $manager = new PaymentManager(
        app(ProviderDetectorInterface::class),
        app(DriverFactory::class),
        app(MetadataSanitizer::class),
        $repository
    );

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('updateTransactionFromVerification');
    $method->setAccessible(true);

    $response = new VerificationResponseDTO(
        reference: 'err_ref',
        status: 'success',
        amount: 1000.0,
        currency: 'NGN',
    );

    // Should not throw - the exception is caught and logged internally.
    $method->invoke($manager, 'err_ref', $response);

    expect(true)->toBeTrue();
});
