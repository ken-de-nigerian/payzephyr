<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
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
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Support\PackageConfig;

final class DriverFactory
{
    /**
     * The bundled drivers, by name.
     *
     * A name used to be turned into a class name - "paypal" into
     * PaypalDriver - and two of them do not come out right: the classes are
     * PayPalDriver and OPayDriver. PHP's class names ignore case, so on
     * Windows, or once the class was loaded, it worked; on Linux the
     * autoloader looks for PaypalDriver.php, which does not exist, and a
     * config without driver_class failed to resolve the driver.
     *
     * @var array<string, class-string<DriverInterface>>
     */
    private const BUNDLED = [
        'flutterwave' => FlutterwaveDriver::class,
        'mollie' => MollieDriver::class,
        'monnify' => MonnifyDriver::class,
        'opay' => OPayDriver::class,
        'paddle' => PaddleDriver::class,
        'paypal' => PayPalDriver::class,
        'paystack' => PaystackDriver::class,
        'razorpay' => RazorpayDriver::class,
        'square' => SquareDriver::class,
        'stripe' => StripeDriver::class,
    ];

    /** @var array<string, string> */
    protected array $drivers = [];

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws DriverNotFoundException
     */
    public function create(string $name, array $config): DriverInterface
    {
        $class = $this->resolveDriverClass($name);

        if (! class_exists($class)) {
            throw new DriverNotFoundException("Driver class [$class] not found for driver [$name]");
        }

        if (! is_subclass_of($class, DriverInterface::class)) {
            throw new DriverNotFoundException("Driver class [$class] must implement DriverInterface");
        }

        return new $class($config);
    }

    protected function resolveDriverClass(string $name): string
    {
        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        $configDriver = PackageConfig::read()->string('providers', $name, 'driver_class');
        if ($configDriver !== null && class_exists($configDriver)) {
            return $configDriver;
        }

        if (isset(self::BUNDLED[strtolower($name)])) {
            return self::BUNDLED[strtolower($name)];
        }

        $className = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        $fqcn = 'KenDeNigerian\PayZephyr\Drivers\\'.$className.'Driver';

        if (class_exists($fqcn)) {
            return $fqcn;
        }

        return $name;
    }

    /**
     * @throws DriverNotFoundException
     */
    public function register(string $name, string $class): self
    {
        if (! class_exists($class)) {
            throw new DriverNotFoundException("Cannot register driver [$name]: class [$class] does not exist");
        }

        if (! is_subclass_of($class, DriverInterface::class)) {
            throw new DriverNotFoundException("Cannot register driver [$name]: class [$class] must implement DriverInterface");
        }

        $this->drivers[$name] = $class;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getRegisteredDrivers(): array
    {
        return array_keys($this->drivers);
    }

    public function isRegistered(string $name): bool
    {
        return isset($this->drivers[$name]);
    }
}
