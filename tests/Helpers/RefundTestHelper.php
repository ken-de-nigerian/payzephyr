<?php

namespace Tests\Helpers;

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Refund;
use ReflectionClass;

class RefundTestHelper
{
    /**
     * Create a Paystack refund-creation mock response.
     */
    public static function refundMock(string|int $refundReference = 12345, array $overrides = []): Response
    {
        $data = array_merge([
            'id' => $refundReference,
            'status' => 'pending',
            'amount' => 500000,
            'currency' => 'NGN',
        ], $overrides);

        return new Response(200, [], json_encode([
            'status' => true,
            'data' => $data,
        ]));
    }

    public static function createWithMock(array $responses): Refund
    {
        $driver = PaystackDriverTestHelper::createWithMock($responses);

        $manager = new PaymentManager;

        $reflection = new ReflectionClass($manager);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($manager);
        $config['providers']['paystack']['enabled'] = true;
        $config['default'] = 'paystack';
        $config['refunds']['validation']['enabled'] = config('payments.refunds.validation.enabled', true);
        $configProperty->setValue($manager, $config);

        $driversProperty = $reflection->getProperty('drivers');
        $driversProperty->setAccessible(true);
        $drivers = $driversProperty->getValue($manager);
        $drivers['paystack'] = $driver;
        $driversProperty->setValue($manager, $drivers);

        config(['payments.default' => 'paystack']);
        config(['payments.providers.paystack.enabled' => true]);
        config(['payments.refunds.validation.enabled' => true]);

        return new Refund($manager);
    }
}
