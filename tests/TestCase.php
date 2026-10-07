<?php

namespace KenDeNigerian\PayZephyr\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\PaddleDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\RazorpayDriver;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\PaymentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Stripe\Exception\ApiErrorException;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaymentServiceProvider::class,
        ];
    }

    /**
     * A database directory, config directory and .env of this process's own.
     *
     * The install and uninstall tests publish migrations into database_path()
     * and config/payments.php into config_path(), and write feature flags to
     * the .env file. Left pointing at Testbench's skeleton, which every
     * parallel worker shares, one worker deleted files another worker's
     * `migrate` was halfway through reading - and a worker booting while
     * another was publishing the config read a half-written payments.php,
     * which `require`s to 1, and failed in mergeConfigFrom(). A published
     * config left in the skeleton was also merged into every later test's
     * configuration. Set here, before the service provider boots and computes
     * its publish targets.
     */
    private static function isolatedApplicationPath(): string
    {
        static $path = null;

        if ($path === null) {
            $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payzephyr-tests-'.getmypid().'-'.(getenv('TEST_TOKEN') ?: '0');

            if (! is_dir($path.'/database/migrations')) {
                mkdir($path.'/database/migrations', 0777, true);
            }

            if (! is_dir($path.'/config')) {
                mkdir($path.'/config', 0777, true);
            }

            // Start from the skeleton's .env, as the tests always have.
            $skeletonEnv = dirname(__DIR__).'/vendor/orchestra/testbench-core/laravel/.env';
            if (! file_exists($path.'/.env') && file_exists($skeletonEnv)) {
                copy($skeletonEnv, $path.'/.env');
            }
        }

        return $path;
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app->useDatabasePath(self::isolatedApplicationPath().DIRECTORY_SEPARATOR.'database');
        $app->useEnvironmentPath(self::isolatedApplicationPath());
        $app->useConfigPath(self::isolatedApplicationPath().DIRECTORY_SEPARATOR.'config');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::databaseConnection());

        $app['config']->set('payments.default', 'paystack');

        // Enable all providers for comprehensive testing
        $app['config']->set('payments.providers.paystack', [
            'driver' => 'paystack',
            'driver_class' => PaystackDriver::class,
            'secret_key' => 'sk_test_xxx',
            'public_key' => 'pk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN', 'USD'],
        ]);

        $app['config']->set('payments.providers.stripe', [
            'driver' => 'stripe',
            'driver_class' => StripeDriver::class,
            'secret_key' => 'sk_test_xxx',
            'public_key' => 'pk_test_xxx',
            'enabled' => true,
            'currencies' => ['USD', 'EUR'],
        ]);

        $app['config']->set('payments.providers.flutterwave', [
            'driver' => 'flutterwave',
            'driver_class' => FlutterwaveDriver::class,
            'reference_prefix' => 'FLW',
            'secret_key' => 'FLWSECK_TEST_xxx',
            'public_key' => 'FLWPUBK_TEST_xxx',
            'enabled' => true,
            'currencies' => ['NGN', 'USD'],
        ]);

        $app['config']->set('payments.providers.monnify', [
            'driver' => 'monnify',
            'driver_class' => MonnifyDriver::class,
            'api_key' => 'test_api_key',
            'secret_key' => 'test_secret_key',
            'contract_code' => 'test_contract',
            'enabled' => true,
            'currencies' => ['NGN'],
        ]);

        $app['config']->set('payments.providers.paypal', [
            'driver' => 'paypal',
            'driver_class' => PayPalDriver::class,
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'enabled' => true,
            'currencies' => ['USD', 'EUR'],
        ]);

        $app['config']->set('payments.providers.square', [
            'driver' => 'square',
            'driver_class' => SquareDriver::class,
            'access_token' => 'test_token',
            'location_id' => 'test_location',
            'enabled' => true,
            'currencies' => ['USD'],
        ]);

        $app['config']->set('payments.providers.opay', [
            'driver' => 'opay',
            'driver_class' => OPayDriver::class,
            'merchant_id' => 'test_merchant',
            'public_key' => 'test_public_key',
            'enabled' => true,
            'currencies' => ['NGN'],
        ]);

        $app['config']->set('payments.providers.paddle', [
            'driver' => 'paddle',
            'driver_class' => PaddleDriver::class,
            'api_key' => 'pdl_sdbx_apikey_test',
            'webhook_secret' => 'pdl_ntfset_test_secret',
            'enabled' => true,
            'currencies' => ['USD', 'EUR'],
        ]);

        $app['config']->set('payments.providers.mollie', [
            'driver' => 'mollie',
            'driver_class' => MollieDriver::class,
            'api_key' => 'test_api_key',
            'enabled' => true,
            'currencies' => ['EUR', 'USD'],
        ]);

        $app['config']->set('payments.providers.razorpay', [
            'driver' => 'razorpay',
            'driver_class' => RazorpayDriver::class,
            'key_id' => 'rzp_test_xxx',
            'key_secret' => 'test_key_secret',
            'webhook_secret' => 'test_webhook_secret',
            'enabled' => true,
            'currencies' => ['INR'],
        ]);

        // Keep validation enabled for comprehensive testing
        // Tests should provide proper mocks for plan validation

        // Disable health checks in tests - we're mocking all HTTP responses anyway
        $app['config']->set('payments.health_check.enabled', false);

        // Ensure all providers have proper currency support configured
        foreach (['paystack', 'stripe', 'flutterwave', 'monnify', 'paypal', 'square', 'opay', 'mollie', 'paddle', 'razorpay'] as $provider) {
            $providerConfig = $app['config']->get("payments.providers.{$provider}", []);
            if (empty($providerConfig['currencies'])) {
                $app['config']->set("payments.providers.{$provider}.currencies", match ($provider) {
                    'stripe', 'paypal', 'square', 'paddle' => ['USD', 'EUR'],
                    'mollie' => ['EUR', 'USD'],
                    'razorpay' => ['INR'],
                    default => ['NGN', 'USD'],
                });
            }
        }
    }

    /**
     * The database the suite runs against: in-memory SQLite by default, or the
     * server DB_CONNECTION names. CI runs the suite once more on each of MySQL
     * and PostgreSQL, because locking, unique-violation detection and decimal
     * sums are exactly where those differ from SQLite.
     *
     * @return array<string, string|int>
     */
    private static function databaseConnection(): array
    {
        $env = fn (string $key, string $default): string => is_string($value = getenv($key)) && $value !== '' ? $value : $default;

        return match ($env('DB_CONNECTION', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => $env('DB_HOST', '127.0.0.1'),
                'port' => $env('DB_PORT', '3306'),
                'database' => $env('DB_DATABASE', 'payzephyr_test'),
                'username' => $env('DB_USERNAME', 'root'),
                'password' => $env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $env('DB_HOST', '127.0.0.1'),
                'port' => $env('DB_PORT', '5432'),
                'database' => $env('DB_DATABASE', 'payzephyr_test'),
                'username' => $env('DB_USERNAME', 'postgres'),
                'password' => $env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.testing.driver') === 'sqlite') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
            $this->artisan('migrate', ['--database' => 'testing'])->run();

            return;
        }

        // Every test boots a new application, and with it new connections; a
        // server keeps each one open until it is closed, and refuses new ones
        // once a few hundred tests have run ("too many connections").
        $this->beforeApplicationDestroyed(function (): void {
            $db = $this->app->make('db');

            foreach (array_keys($db->getConnections()) as $name) {
                $db->purge($name);
            }
        });

        // Not loadMigrationsFrom(): Testbench rolls those migrations back
        // after every test, which would rebuild a server database each time.
        $this->app->make('migrator')->path(__DIR__.'/../database/migrations');
        $this->resetServerDatabase();
    }

    /**
     * The schema after the last fresh migration on a server database - each
     * table with its columns, and the migrations recorded - or null until the
     * first test of the process builds it.
     *
     * @var array{tables: array<string, list<string>>, migrations: int}|null
     */
    private static ?array $migratedSchema = null;

    /**
     * Give this test an empty database with the package's schema.
     *
     * In-memory SQLite starts that way; a server database keeps whatever the
     * last test left. Rebuilding it for every test takes most of a second on
     * MySQL, so the schema is built once and the tables emptied between tests.
     * A test that changed the schema - the install and uninstall tests drop
     * tables, and the stale-column tests drop a column - leaves one that no
     * longer matches, and the next test rebuilds.
     */
    private function resetServerDatabase(): void
    {
        if (self::$migratedSchema === null || $this->currentSchema() !== self::$migratedSchema) {
            $this->artisan('migrate:fresh', ['--database' => 'testing'])->run();
            self::$migratedSchema = $this->currentSchema();

            return;
        }

        $data = array_values(array_filter(array_keys(self::$migratedSchema['tables']), fn (string $table): bool => $table !== 'migrations'));

        if (config('database.connections.testing.driver') === 'pgsql') {
            DB::connection('testing')->statement('TRUNCATE TABLE '.implode(', ', array_map(fn (string $t): string => '"'.$t.'"', $data)).' RESTART IDENTITY CASCADE');

            return;
        }

        foreach ($data as $table) {
            DB::connection('testing')->table($table)->delete();
        }
    }

    /**
     * @return array{tables: array<string, list<string>>, migrations: int}
     */
    private function currentSchema(): array
    {
        $schema = DB::connection('testing')->getSchemaBuilder();
        $tables = [];

        foreach ($schema->getTableListing() as $qualified) {
            $parts = explode('.', $qualified);
            $table = end($parts);
            $columns = $schema->getColumnListing($table);
            sort($columns);
            $tables[$table] = $columns;
        }

        ksort($tables);

        return [
            'tables' => $tables,
            'migrations' => isset($tables['migrations']) ? DB::connection('testing')->table('migrations')->count() : 0,
        ];
    }

    /**
     * Helper method to set up mocked HTTP client for a provider
     * and ensure Payment facade uses the mocked manager
     */
    protected function setupMockedProvider(string $provider, array $responses): PaymentManager
    {
        // CRITICAL: Update config FIRST before any PaymentManager is created
        // Ensure health checks are disabled for testing
        config(['payments.health_check.enabled' => false]);

        // Ensure provider is enabled and has proper currency support
        $currency = match ($provider) {
            'stripe', 'paypal', 'square', 'paddle' => 'USD',
            'mollie' => 'EUR',
            'razorpay' => 'INR',
            default => 'NGN',
        };

        config(["payments.providers.{$provider}.enabled" => true]);
        $providerConfig = config("payments.providers.{$provider}", []);
        $existingCurrencies = $providerConfig['currencies'] ?? [];
        if (empty($existingCurrencies) || ! in_array($currency, $existingCurrencies)) {
            config(["payments.providers.{$provider}.currencies" => array_merge($existingCurrencies, [$currency])]);
        }

        // Update payments.config singleton - this must happen BEFORE PaymentManager is created
        $this->app->forgetInstance('payments.config');
        $this->app->singleton('payments.config', function () {
            $config = config('payments');
            // Force health check disabled
            $config['health_check']['enabled'] = false;

            return $config;
        });

        // CRITICAL: Forget PaymentManager BEFORE creating new one
        // This ensures it reads the updated config
        $this->app->forgetInstance(PaymentManager::class);

        // Also forget Payment to ensure it gets fresh manager
        $this->app->forgetInstance(Payment::class);

        // Get manager - it will read the updated config from singleton
        $manager = app(PaymentManager::class);

        // CRITICAL: Update the manager's config property directly via reflection
        // This ensures it has the latest config even if singleton was cached
        $reflection = new \ReflectionClass($manager);
        $configProperty = $reflection->getProperty('config');
        $freshConfig = app('payments.config') ?? config('payments');
        $freshConfig['health_check']['enabled'] = false;
        $freshConfig['providers'][$provider] ??= [];
        $freshConfig['providers'][$provider]['enabled'] = true;
        // Ensure currency is supported
        $currency = match ($provider) {
            'stripe', 'paypal', 'square', 'paddle' => 'USD',
            'mollie' => 'EUR',
            'razorpay' => 'INR',
            default => 'NGN',
        };
        if (empty($freshConfig['providers'][$provider]['currencies']) || ! in_array($currency, $freshConfig['providers'][$provider]['currencies'] ?? [])) {
            $freshConfig['providers'][$provider]['currencies'] = array_merge($freshConfig['providers'][$provider]['currencies'] ?? [], [$currency]);
        }
        $configProperty->setValue($manager, $freshConfig);

        // Clear any cached drivers to ensure fresh instance
        $driversProperty = $reflection->getProperty('drivers');
        $driversProperty->setValue($manager, []);

        // Get the driver - this will create it fresh
        $driver = $manager->driver($provider);

        // Stripe uses SDK, not HTTP client
        if ($provider === 'stripe' && $driver instanceof StripeDriver) {
            // Use a shared object to store references that both sessions and paymentIntents can access
            // Also store a map of all references that have been created, so we can match any STRIPE_* reference
            $sharedState = new class
            {
                public array $storedReferences = [];

                public ?string $createdSessionRef = null;

                // Store all references that match STRIPE_* pattern for verification
                public array $allStripeReferences = [];
            };

            // Create mock Stripe SDK client for charge
            // The reference will be set by the driver from the request or generated
            // We need to make this dynamic so it can match verification references
            $sessionsService = new class($sharedState)
            {
                public function __construct(private readonly object $sharedState) {}

                public function create(array $params = [], array $options = [])
                {
                    // Capture the client_reference_id from params
                    $reference = $params['client_reference_id'] ?? 'STRIPE_'.time().'_'.uniqid();
                    if (! in_array($reference, $this->sharedState->storedReferences)) {
                        $this->sharedState->storedReferences[] = $reference;
                    }
                    $this->sharedState->createdSessionRef = $reference;
                    // Store in all references map for verification matching
                    if (str_starts_with($reference, 'STRIPE_')) {
                        $this->sharedState->allStripeReferences[] = $reference;
                    }

                    return (object) [
                        'id' => 'cs_test_'.substr(md5($reference), 0, 8),
                        'url' => 'https://checkout.stripe.com/pay/cs_test_123',
                        'status' => 'open',
                        'client_reference_id' => $reference,
                    ];
                }

                public function all(array $params = []): \stdClass
                {
                    // Return sessions that match stored references
                    // The verification code searches by client_reference_id with exact match
                    // It uses limit => 1, but we'll return all sessions and let it find the match
                    $sessions = [];

                    // Return all stored references - verification will loop through and find the matching one
                    $referencesToReturn = array_unique(array_merge(
                        $this->sharedState->storedReferences,
                        $this->sharedState->allStripeReferences
                    ));

                    foreach ($referencesToReturn as $ref) {
                        $sessions[] = (object) [
                            'id' => 'cs_test_'.substr(md5($ref), 0, 8), // Unique ID per reference
                            'url' => 'https://checkout.stripe.com/pay/cs_test_123',
                            'status' => 'open',
                            'client_reference_id' => $ref,
                        ];
                    }

                    // Fallback: if no references stored but we have a session reference, use it
                    if ($sessions === [] && $this->sharedState->createdSessionRef !== null) {
                        $sessions[] = (object) [
                            'id' => 'cs_test_123',
                            'url' => 'https://checkout.stripe.com/pay/cs_test_123',
                            'status' => 'open',
                            'client_reference_id' => $this->sharedState->createdSessionRef,
                        ];
                        // Also add it to stored references for payment intents
                        if (! in_array($this->sharedState->createdSessionRef, $this->sharedState->storedReferences)) {
                            $this->sharedState->storedReferences[] = $this->sharedState->createdSessionRef;
                        }
                        if (str_starts_with($this->sharedState->createdSessionRef, 'STRIPE_') &&
                            ! in_array($this->sharedState->createdSessionRef, $this->sharedState->allStripeReferences)) {
                            $this->sharedState->allStripeReferences[] = $this->sharedState->createdSessionRef;
                        }
                    }

                    // Don't respect limit - return all sessions so verification can find the match
                    // The verification code loops through all returned sessions
                    return (object) ['data' => $sessions];
                }

                public function retrieve(string $id, array $params = [])
                {
                    // Return session with expanded payment intent if needed
                    $reference = ! empty($this->sharedState->storedReferences) ? $this->sharedState->storedReferences[0] : ($this->sharedState->createdSessionRef ?? 'STRIPE_DYNAMIC');
                    $session = (object) [
                        'id' => $id,
                        'url' => 'https://checkout.stripe.com/pay/'.$id,
                        'status' => 'complete',
                        'client_reference_id' => $reference,
                    ];

                    if (isset($params['expand']) && in_array('payment_intent', $params['expand'])) {
                        $session->payment_intent = (object) [
                            'id' => 'pi_test_123',
                            'status' => 'succeeded',
                            'amount' => 1000000,
                            'currency' => 'usd',
                            'metadata' => ['reference' => $reference],
                            'receipt_email' => 'test@example.com',
                        ];
                    }

                    return $session;
                }
            };

            // Create mock for verification (payment intents)
            // The reference will be passed to retrieve(), so we need to handle it dynamically
            // We need to make this work with the verification search which looks for metadata['reference']
            $paymentIntents = new class($sharedState)
            {
                public function __construct(private readonly object $sharedState) {}

                public function retrieve(string $id)
                {
                    // Return a mock intent that matches the reference pattern
                    // Stripe verification looks for payment intents by ID or searches by metadata
                    $reference = ! empty($this->sharedState->storedReferences) ? $this->sharedState->storedReferences[0] : ($this->sharedState->createdSessionRef ?? $id);

                    return (object) [
                        'id' => $id,
                        'status' => 'succeeded',
                        'amount' => 1000000,
                        'currency' => 'usd',
                        'created' => time(),
                        'metadata' => ['reference' => $reference],
                        'receipt_email' => 'test@example.com',
                    ];
                }

                public function all(array $params = []): \stdClass
                {
                    // Return intents that can be searched by metadata reference
                    // The verification code searches through intents looking for metadata['reference']
                    // Return all stored references - verification will find the matching one
                    $intents = [];

                    // Return all stored references
                    $referencesToReturn = array_unique(array_merge(
                        $this->sharedState->storedReferences,
                        $this->sharedState->allStripeReferences
                    ));

                    foreach ($referencesToReturn as $ref) {
                        $intents[] = (object) [
                            'id' => 'pi_test_'.substr(md5($ref), 0, 8), // Unique ID per reference
                            'status' => 'succeeded',
                            'amount' => 1000000,
                            'currency' => 'usd',
                            'created' => time(),
                            'metadata' => ['reference' => $ref],
                            'receipt_email' => 'test@example.com',
                        ];
                    }

                    // Fallback: if no references stored but we have a session reference, use it
                    if ($intents === [] && $this->sharedState->createdSessionRef !== null) {
                        $intents[] = (object) [
                            'id' => 'pi_test_123',
                            'status' => 'succeeded',
                            'amount' => 1000000,
                            'currency' => 'usd',
                            'created' => time(),
                            'metadata' => ['reference' => $this->sharedState->createdSessionRef],
                            'receipt_email' => 'test@example.com',
                        ];
                        // Also add it to stored references
                        if (! in_array($this->sharedState->createdSessionRef, $this->sharedState->storedReferences)) {
                            $this->sharedState->storedReferences[] = $this->sharedState->createdSessionRef;
                        }
                        if (str_starts_with($this->sharedState->createdSessionRef, 'STRIPE_') &&
                            ! in_array($this->sharedState->createdSessionRef, $this->sharedState->allStripeReferences)) {
                            $this->sharedState->allStripeReferences[] = $this->sharedState->createdSessionRef;
                        }
                    }

                    // Don't respect limit - return all intents so verification can find the match
                    return (object) ['data' => $intents];
                }
            };

            // Check if we need to simulate an error (for error handling tests)
            // If responses contains an error response, make create() throw an exception
            if ($responses !== [] && $responses[0] instanceof Response) {
                $statusCode = $responses[0]->getStatusCode();
                if ($statusCode >= 400) {
                    // Wrap the sessions service to throw an error on create
                    $originalSessions = $sessionsService;
                    $sessionsService = new class($originalSessions)
                    {
                        public function __construct(private readonly object $originalSessions) {}

                        public function create(array $params = [], array $options = []): never
                        {
                            // Throw Stripe API error exception
                            throw new ApiErrorException('Payment failed', 400);
                        }

                        public function all(array $params = []): object
                        {
                            return $this->originalSessions->all($params);
                        }

                        public function retrieve(string $id, array $params = [])
                        {
                            return $this->originalSessions->retrieve($id, $params);
                        }
                    };
                }
            }

            $checkoutService = new class($sessionsService)
            {
                public function __construct(public object $sessions) {}
            };

            $stripeMock = new class($paymentIntents, $checkoutService)
            {
                public function __construct(public object $paymentIntents, public object $checkout) {}
            };

            $driver->setStripeClient($stripeMock);
        } elseif ($provider === 'paypal' && $driver instanceof PayPalDriver) {
            // PayPal needs OAuth token first, then charge response
            $paypalResponses = $responses;
            if (count($responses) === 1) {
                // Add OAuth token response before charge response
                $paypalResponses = [
                    new Response(200, [], json_encode([
                        'access_token' => 'A21AA_test_token',
                        'token_type' => 'Bearer',
                        'expires_in' => 32400,
                    ])),
                    ...$responses,
                ];
            }
            $mock = new MockHandler($paypalResponses);
            $handlerStack = HandlerStack::create($mock);
            $client = new Client(['handler' => $handlerStack]);
            $driver->setClient($client);
        } else {
            // For HTTP-based providers, mock the HTTP client
            // Special handling for OPay verification to capture reference from request
            if ($provider === 'opay' && count($responses) >= 2) {
                // Create a custom handler that extracts reference from verify request
                $verifyResponse = $responses[1];
                $callCount = 0;
                $handler = function ($request, $options) use ($responses, $verifyResponse, &$callCount) {
                    $callCount++;

                    // For the verify call (second call), extract reference from request body
                    if ($callCount === 2) {
                        $body = (string) $request->getBody();
                        $data = json_decode($body, true);
                        $reference = $data['reference'] ?? 'OPAY_DYNAMIC';

                        // Update the verify response to use the actual reference
                        $responseData = json_decode((string) $verifyResponse->getBody(), true);
                        if (isset($responseData['data'])) {
                            $responseData['data']['reference'] = $reference;
                            $responseData['data']['orderNo'] = $reference;
                        }

                        return new Response(
                            $verifyResponse->getStatusCode(),
                            $verifyResponse->getHeaders(),
                            json_encode($responseData)
                        );
                    }

                    // For other calls, use the first response
                    return $responses[0];
                };

                $handlerStack = HandlerStack::create($handler);
                $client = new Client(['handler' => $handlerStack]);
                $driver->setClient($client);
            } else {
                // Monnify needs OAuth token first, then charge response
                $providerResponses = $responses;
                if ($provider === 'monnify' && count($responses) === 1) {
                    // Add OAuth token response before charge response
                    $providerResponses = [
                        new Response(200, [], json_encode([
                            'requestSuccessful' => true,
                            'responseBody' => [
                                'accessToken' => 'test_access_token',
                                'expiresIn' => 3600,
                            ],
                        ])),
                        ...$responses,
                    ];
                }
                $mock = new MockHandler($providerResponses);
                $handlerStack = HandlerStack::create($mock);
                $client = new Client(['handler' => $handlerStack]);
                $driver->setClient($client);
            }
        }

        // Use reflection to ensure the driver is cached with the mocked client
        $reflection = new \ReflectionClass($manager);
        $driversProperty = $reflection->getProperty('drivers');
        $drivers = $driversProperty->getValue($manager);
        $drivers[$provider] = $driver;
        $driversProperty->setValue($manager, $drivers);

        // Also ensure the config is updated in the manager
        $configProperty = $reflection->getProperty('config');
        $currentConfig = $configProperty->getValue($manager);
        $currentConfig['providers'][$provider] ??= [];
        $currentConfig['providers'][$provider]['enabled'] = true;
        // Ensure currency is supported
        $currency = match ($provider) {
            'stripe', 'paypal', 'square', 'paddle' => 'USD',
            'mollie' => 'EUR',
            'razorpay' => 'INR',
            default => 'NGN',
        };
        if (empty($currentConfig['providers'][$provider]['currencies']) || ! in_array($currency, $currentConfig['providers'][$provider]['currencies'] ?? [])) {
            $currentConfig['providers'][$provider]['currencies'] = array_merge($currentConfig['providers'][$provider]['currencies'] ?? [], [$currency]);
        }
        $currentConfig['health_check']['enabled'] = false;
        $configProperty->setValue($manager, $currentConfig);

        // CRITICAL: Bind PaymentManager as singleton with our mocked instance
        // This ensures Payment facade always uses our manager
        $this->app->singleton(PaymentManager::class, fn () => $manager);

        // CRITICAL: Bind Payment to use our manager
        // Payment is bound (not singleton), so each call gets fresh instance but same manager
        $this->app->bind(Payment::class, fn ($app): Payment => new Payment($manager));

        // Clear facade cache to ensure it uses our bindings
        \KenDeNigerian\PayZephyr\Facades\Payment::clearResolvedInstances();

        return $manager;
    }
}
