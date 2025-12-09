<?php

namespace KenDeNigerian\PayZephyr\Tests;

use KenDeNigerian\PayZephyr\PaymentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaymentServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use SQLite in-memory database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('payments.default', 'paystack');
        $app['config']->set('payments.providers.paystack', [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'public_key' => 'pk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN', 'USD'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Load migrations from the package
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Run migrations on the testing connection
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}
