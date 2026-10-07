<?php

declare(strict_types=1);

namespace Tests\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

class PaystackDriverTestHelper
{
    public static function createWithMock(array $responses): PaystackDriver
    {
        $config = [
            'secret_key' => 'sk_test_xxx',
            'base_url' => 'https://api.paystack.co',
            'currencies' => ['NGN', 'USD', 'GHS', 'ZAR'],
        ];

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new PaystackDriver($config);
        $driver->setClient($client);

        return $driver;
    }
}
