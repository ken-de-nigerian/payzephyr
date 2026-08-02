<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

function sanitizeObjectViaDriver(mixed $data): mixed
{
    $driver = new PaystackDriver(['secret_key' => 'sk_test_irrelevant', 'currencies' => ['NGN']]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('sanitizeLogContext');
    $method->setAccessible(true);

    return $method->invoke($driver, $data);
}

test('an object with toArray() is converted and recursively sanitized', function () {
    $object = new class
    {
        public function toArray(): array
        {
            return [
                'password' => 'super-secret',
                'reference' => 'ORDER_123',
            ];
        }
    };

    $result = sanitizeObjectViaDriver($object);

    expect($result)->toBeArray()
        ->and($result['password'])->toBe('[REDACTED]')
        ->and($result['reference'])->toBe('ORDER_123');
});

test('a plain object without toArray() is cast to an array and recursively sanitized', function () {
    $object = new class
    {
        public string $token = 'sk_test_plain_object_token';

        public string $reference = 'ORDER_456';
    };

    $result = sanitizeObjectViaDriver($object);

    expect($result)->toBeArray()
        ->and($result['token'])->toBe('[REDACTED]')
        ->and($result['reference'])->toBe('ORDER_456');
});

test('a stdClass instance is cast to an array and sanitized', function () {
    $object = new stdClass;
    $object->secret = 'top-secret-value';
    $object->reference = 'ORDER_789';

    $result = sanitizeObjectViaDriver($object);

    expect($result)->toBeArray()
        ->and($result['secret'])->toBe('[REDACTED]')
        ->and($result['reference'])->toBe('ORDER_789');
});
