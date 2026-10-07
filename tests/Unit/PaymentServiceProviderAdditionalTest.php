<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\PaymentServiceProvider;

/**
 * Covers PaymentServiceProvider::registerRoutes()'s per-provider
 * catch (Throwable $e) block in the /payments/health route closure.
 *
 * tests/Unit/HealthEndpointTest.php's "health endpoint handles provider
 * errors gracefully" test sets config for an "invalid" provider but never
 * forgets the 'payments.config' singleton (bound once at boot), so the route
 * closure's own app('payments.config') read never sees that provider and the
 * catch block is never reached. Forgetting both the config singleton and the
 * PaymentManager singleton here forces both to pick up the broken provider.
 */
test('health route catches and reports a driver resolution failure for a misconfigured provider', function (): void {
    config([
        'payments.providers.broken.enabled' => true,
        'payments.providers.broken.driver' => 'totally_nonexistent_driver',
    ]);

    app()->forgetInstance('payments.config');
    app()->forgetInstance(PaymentManager::class);

    $response = $this->getJson('/payments/health');

    $response->assertStatus(200);

    $data = $response->json();

    expect($data['providers'])->toHaveKey('broken')
        ->and($data['providers']['broken']['healthy'])->toBeFalse()
        ->and($data['providers']['broken'])->toHaveKey('error');
});

test('routes are not registered again when the application has cached its routes', function (): void {
    // With `php artisan route:cache`, Laravel loads every route - including
    // this package's - from the cache file. Registering them again on boot
    // would duplicate them and defeat the cache.
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('routesAreCached')->once()->andReturn(true);

    Route::shouldReceive('group')->never();
    Route::shouldReceive('get')->never();

    $provider = new PaymentServiceProvider($app);
    $method = new ReflectionMethod($provider, 'registerRoutes');
    $method->invoke($provider);
});

test('the health route reports a provider configured without an enabled key, as the manager charges it', function (): void {
    // The manager reads a missing `enabled` as on; the route read it as off,
    // so a provider that was taking payments was absent from the health report.
    config([
        'payments.providers.implicit' => ['driver' => 'totally_nonexistent_driver'],
        'payments.providers.switched_off' => ['driver' => 'totally_nonexistent_driver', 'enabled' => false],
    ]);

    app()->forgetInstance('payments.config');
    app()->forgetInstance(PaymentManager::class);

    $providers = $this->getJson('/payments/health')->assertStatus(200)->json('providers');

    expect($providers)->toHaveKey('implicit')
        ->and($providers)->not->toHaveKey('switched_off');
});
