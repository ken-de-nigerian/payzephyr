<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use KenDeNigerian\PayZephyr\Console\InstallCommand;
use KenDeNigerian\PayZephyr\Contracts\ChannelMapperInterface;
use KenDeNigerian\PayZephyr\Contracts\ProviderDetectorInterface;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\WebhookEventRepositoryInterface;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;
use KenDeNigerian\PayZephyr\Http\Middleware\HealthEndpointMiddleware;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Repositories\EloquentRefundRepository;
use KenDeNigerian\PayZephyr\Repositories\EloquentSubscriptionRepository;
use KenDeNigerian\PayZephyr\Repositories\EloquentTransactionRepository;
use KenDeNigerian\PayZephyr\Repositories\EloquentWebhookEventRepository;
use KenDeNigerian\PayZephyr\Services\ChannelMapper;
use KenDeNigerian\PayZephyr\Services\DriverFactory;
use KenDeNigerian\PayZephyr\Services\MetadataSanitizer;
use KenDeNigerian\PayZephyr\Services\ProviderDetector;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use Throwable;

final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payments.php', 'payments');

        $this->app->singleton('payments.config', fn () => config('payments'));

        $this->app->singleton(StatusNormalizerInterface::class, StatusNormalizer::class);
        $this->app->singleton(ProviderDetectorInterface::class, ProviderDetector::class);
        $this->app->singleton(ChannelMapperInterface::class, ChannelMapper::class);

        $this->app->singleton(StatusNormalizer::class);
        $this->app->singleton(ProviderDetector::class);
        $this->app->singleton(ChannelMapper::class);
        $this->app->singleton(MetadataSanitizer::class);

        $this->app->singleton(DriverFactory::class);

        $this->app->singleton(TransactionRepositoryInterface::class, EloquentTransactionRepository::class);
        $this->app->singleton(SubscriptionRepositoryInterface::class, EloquentSubscriptionRepository::class);
        $this->app->singleton(RefundRepositoryInterface::class, EloquentRefundRepository::class);
        $this->app->singleton(WebhookEventRepositoryInterface::class, EloquentWebhookEventRepository::class);

        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager(
                $app->make(ProviderDetectorInterface::class),
                $app->make(DriverFactory::class),
                $app->make(MetadataSanitizer::class),
                $app->make(TransactionRepositoryInterface::class)
            );
        });

        $this->app->bind(Payment::class, function ($app) {
            return new Payment($app->make(PaymentManager::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/payments.php' => config_path('payments.php'),
            ], 'payments-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'payments-migrations');

            $this->commands([
                InstallCommand::class,
            ]);
        }

        $this->registerRoutes();

        $this->configureModel();

        $this->registerWebhookStatusMappings();
    }

    protected function registerRoutes(): void
    {
        if (! $this->app->routesAreCached()) {
            $config = app('payments.config') ?? config('payments', []);
            $webhookPath = $config['webhook']['path'] ?? '/payments/webhook';
            $rateLimit = $config['webhook']['rate_limit'] ?? '120,1';

            Route::group([
                'prefix' => $webhookPath,
                'middleware' => ['api', 'throttle:'.$rateLimit],
                'namespace' => 'KenDeNigerian\PayZephyr\Http\Controllers',
            ], function () {
                Route::post('/{provider}', [WebhookController::class, 'handle'])
                    ->name('payments.webhook');
            });

            $healthConfig = $config['health_check'] ?? [];
            $healthMiddleware = $healthConfig['middleware'] ?? [];
            $healthMiddleware[] = HealthEndpointMiddleware::class;

            Route::get('/payments/health', function (PaymentManager $manager) {
                $providers = [];
                $healthConfig = app('payments.config') ?? config('payments', []);

                $enabledProviders = array_filter(
                    $healthConfig['providers'] ?? [],
                    fn ($config) => $config['enabled'] ?? false
                );

                foreach ($enabledProviders as $name => $providerConfig) {
                    try {
                        $driver = $manager->driver($name);
                        $providers[$name] = [
                            'healthy' => $driver->getCachedHealthCheck(),
                            'currencies' => $driver->getSupportedCurrencies(),
                        ];
                    } catch (Throwable $e) {
                        $providers[$name] = [
                            'healthy' => false,
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                return response()->json([
                    'status' => 'operational',
                    'providers' => $providers,
                ]);
            })->middleware(array_merge(['api'], $healthMiddleware))->name('payments.health');
        }
    }

    protected function configureModel(): void
    {
        $config = app('payments.config') ?? config('payments', []);
        $tableName = $config['logging']['table'] ?? 'payment_transactions';
        PaymentTransaction::setTableName($tableName);
    }

    protected function registerWebhookStatusMappings(): void
    {
        $normalizer = $this->app->make(StatusNormalizerInterface::class);
        $normalizer->registerProviderMappings('paypal', [
            'success' => ['PAYMENT.CAPTURE.COMPLETED', 'COMPLETED'],
            'failed' => ['PAYMENT.CAPTURE.DENIED'],
            'pending' => ['APPROVED', 'PENDING', 'CREATED'],
        ]);
        $normalizer->registerProviderMappings('square', [
            'success' => ['COMPLETED', 'APPROVED'],
            'failed' => ['FAILED', 'CANCELED'],
        ]);
        $normalizer->registerProviderMappings('stripe', [
            'success' => ['SUCCEEDED', 'PAID', 'COMPLETE'],
            'failed' => ['PAYMENT_FAILED', 'CANCELED', 'REQUIRES_ACTION'],
        ]);
        $normalizer->registerProviderMappings('mollie', [
            'success' => ['PAID', 'AUTHORIZED', 'PAIDOUT'],
            'failed' => ['FAILED', 'CANCELED', 'EXPIRED'],
            'pending' => ['OPEN', 'PENDING'],
        ]);
    }
}
