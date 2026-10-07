<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Exceptions\WebhookException;

test('payment exception sets and gets context', function (): void {
    $exception = new PaymentException('Test error');
    $exception->setContext(['key' => 'value']);

    expect($exception->getContext())->toBe(['key' => 'value']);
});

test('payment exception creates with context', function (): void {
    $exception = PaymentException::withContext('Test error', ['key' => 'value']);

    expect($exception->getMessage())->toBe('Test error')
        ->and($exception->getContext())->toBe(['key' => 'value']);
});

test('payment exception can chain setContext', function (): void {
    $exception = (new PaymentException('Test error'))
        ->setContext(['key' => 'value']);

    expect($exception)->toBeInstanceOf(PaymentException::class)
        ->and($exception->getContext())->toBe(['key' => 'value']);
});

test('payment exception handles empty context', function (): void {
    $exception = new PaymentException('Test error');

    expect($exception->getContext())->toBe([]);
});

test('payment exception handles complex context', function (): void {
    $context = [
        'provider' => 'paystack',
        'reference' => 'ref_123',
        'amount' => 10000,
        'metadata' => ['order_id' => 123],
    ];

    $exception = PaymentException::withContext('Test error', $context);

    expect($exception->getContext())->toBe($context);
});

test('charge exception is instance of payment exception', function (): void {
    $exception = new ChargeException('Charge failed');

    expect($exception)->toBeInstanceOf(PaymentException::class)
        ->and($exception->getMessage())->toBe('Charge failed');
});

test('verification exception is instance of payment exception', function (): void {
    $exception = new VerificationException('Verification failed');

    expect($exception)->toBeInstanceOf(PaymentException::class);
});

test('driver not found exception is instance of payment exception', function (): void {
    $exception = new DriverNotFoundException('Driver not found');

    expect($exception)->toBeInstanceOf(PaymentException::class);
});

test('invalid configuration exception is instance of payment exception', function (): void {
    $exception = new InvalidConfigurationException('Invalid config');

    expect($exception)->toBeInstanceOf(PaymentException::class);
});

test('webhook exception is instance of payment exception', function (): void {
    $exception = new WebhookException('Webhook failed');

    expect($exception)->toBeInstanceOf(PaymentException::class);
});

test('provider exception is instance of payment exception', function (): void {
    $exception = new ProviderException('All providers failed');

    expect($exception)->toBeInstanceOf(PaymentException::class);
});

test('payment exception can be thrown and caught', function (): void {
    try {
        throw new PaymentException('Test error');
    } catch (PaymentException $e) {
        expect($e->getMessage())->toBe('Test error');
    }
});

test('charge exception with context can be caught', function (): void {
    try {
        throw ChargeException::withContext('Charge failed', ['provider' => 'paystack']);
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Charge failed')
            ->and($e->getContext())->toBe(['provider' => 'paystack']);
    }
});

test('provider exception with multiple provider errors', function (): void {
    $context = [
        'exceptions' => [
            'paystack' => 'Connection timeout',
            'stripe' => 'Invalid API key',
            'flutterwave' => 'Service unavailable',
        ],
    ];

    $exception = ProviderException::withContext('All providers failed', $context);

    expect($exception->getContext()['exceptions'])->toHaveCount(3);
});

test('exception preserves previous exception', function (): void {
    $previous = new Exception('Previous error');
    $exception = new PaymentException('Payment error', 0, $previous);

    expect($exception->getPrevious())->toBe($previous);
});
