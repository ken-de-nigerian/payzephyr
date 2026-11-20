<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Fixtures;

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSplitPaymentsInterface;
use KenDeNigerian\PayZephyr\PaymentManager;
use ReflectionClass;

/**
 * The same, able to split charges.
 */
final class SplittingRecordingDriver extends SplitRecordingDriver implements SupportsSplitPaymentsInterface
{
    /**
     * A PaymentManager using these drivers (no health checks).
     *
     * @param  array<string, DriverInterface>  $drivers
     */
    public static function manager(array $drivers, string $default, ?string $fallback = null): PaymentManager
    {
        $manager = app(PaymentManager::class);
        $reflection = new ReflectionClass($manager);
        $reflection->getProperty('drivers')->setValue($manager, $drivers);
        $config = $reflection->getProperty('config');
        $config->setValue($manager, array_replace((array) $config->getValue($manager), [
            'default' => $default,
            'fallback' => $fallback,
            'health_check' => ['enabled' => false],
        ]));

        return $manager;
    }
}
