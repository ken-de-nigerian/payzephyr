<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\PaymentManager;

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
test('health route catches and reports a driver resolution failure for a misconfigured provider', function () {
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
