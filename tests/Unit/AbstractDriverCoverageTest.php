<?php

use GuzzleHttp\Client;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Services\ChannelMapper;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use Psr\Http\Message\ResponseInterface;

test('abstract driver setStatusNormalizer allows custom normalizer', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $normalizer = new StatusNormalizer;
    $normalizer->registerProviderMappings('paystack', [
        'success' => ['CUSTOM_SUCCESS'],
    ]);

    $driver->setStatusNormalizer($normalizer);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('abstract driver setChannelMapper allows custom mapper', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $mapper = new ChannelMapper;
    $driver->setChannelMapper($mapper);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('abstract driver mapChannels returns null when provider does not support channels', function () {
    // Create a mock driver that returns 'PayPal' as name
    $paypalDriver = new class(['client_id' => 'test', 'client_secret' => 'test', 'mode' => 'sandbox', 'currencies' => ['USD']]) extends PayPalDriver
    {
        public function getName(): string
        {
            return 'paypal';
        }
    };

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, null, [], null, null, null, null, ['card']);

    $reflection = new ReflectionClass($paypalDriver);
    $method = $reflection->getMethod('mapChannels');

    $result = $method->invoke($paypalDriver, $request);

    expect($result)->toBeNull();
});

test('abstract driver mapChannels returns mapped channels when provider supports them', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, null, [], null, null, null, null, ['card', 'bank_transfer']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapChannels');

    $result = $method->invoke($driver, $request);

    expect($result)->toBe(['card', 'bank_transfer']);
});

test('abstract driver mapChannels returns null when no channels provided', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com');
    // No channels set

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapChannels');

    $result = $method->invoke($driver, $request);

    expect($result)->toBeNull();
});

test('abstract driver makeRequest injects idempotency key when available', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $request = new ChargeRequestDTO(
        amount: 10000,
        currency: 'NGN',
        email: 'test@example.com',
        reference: null,
        callbackUrl: null,
        metadata: [],
        description: null,
        customer: null,
        customFields: null,
        split: null,
        channels: null,
        idempotencyKey: 'test_idempotency_key'
    );

    $reflection = new ReflectionClass($driver);
    $currentRequestProperty = $reflection->getProperty('currentRequest');
    $currentRequestProperty->setValue($driver, $request);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('request')
        ->once()
        ->with('POST', '/test', Mockery::on(function ($options) {
            return isset($options['headers']['Idempotency-Key'])
                && $options['headers']['Idempotency-Key'] === 'test_idempotency_key';
        }))
        ->andReturn(Mockery::mock(ResponseInterface::class));

    $driver->setClient($client);

    $makeRequestMethod = $reflection->getMethod('makeRequest');

    $makeRequestMethod->invoke($driver, 'POST', '/test', []);

    expect(true)->toBeTrue(); // If we get here, the mock was called correctly
});

test('abstract driver makeRequest does not override existing idempotency headers', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $request = new ChargeRequestDTO(
        amount: 10000,
        currency: 'NGN',
        email: 'test@example.com',
        reference: null,
        callbackUrl: null,
        metadata: [],
        description: null,
        customer: null,
        customFields: null,
        split: null,
        channels: null,
        idempotencyKey: 'test_idempotency_key'
    );

    $reflection = new ReflectionClass($driver);
    $currentRequestProperty = $reflection->getProperty('currentRequest');
    $currentRequestProperty->setValue($driver, $request);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('request')
        ->once()
        ->with('POST', '/test', Mockery::on(function ($options) {
            return isset($options['headers']['Idempotency-Key'])
                && $options['headers']['Idempotency-Key'] === 'existing_key';
        }))
        ->andReturn(Mockery::mock(ResponseInterface::class));

    $driver->setClient($client);

    $makeRequestMethod = $reflection->getMethod('makeRequest');

    $makeRequestMethod->invoke($driver, 'POST', '/test', [
        'headers' => ['Idempotency-Key' => 'existing_key'],
    ]);

    expect(true)->toBeTrue(); // If we get here, the mock was called correctly
});
