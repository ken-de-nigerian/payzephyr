<?php

declare(strict_types=1);

namespace Tests\Helpers;

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\PaymentManager;
use ReflectionClass;

class PaymentManagerTestHelper
{
    public static function withMockDriver(PaymentManager $manager, string $provider, DriverInterface $mockDriver): PaymentManager
    {
        $reflection = new ReflectionClass($manager);
        $driversProperty = $reflection->getProperty('drivers');
        $drivers = $driversProperty->getValue($manager);
        $drivers[$provider] = $mockDriver;
        $driversProperty->setValue($manager, $drivers);

        return $manager;
    }
}
