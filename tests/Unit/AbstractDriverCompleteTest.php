<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Services\ChannelMapper;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

test('abstract driver setStatusNormalizer sets custom normalizer', function () {
    $driver = new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'test';
        }

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }
    };

    $normalizer = new StatusNormalizer;
    $result = $driver->setStatusNormalizer($normalizer);

    expect($result)->toBe($driver);
});

test('abstract driver setChannelMapper sets custom mapper', function () {
    $driver = new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'test';
        }

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }
    };

    $mapper = new ChannelMapper;
    $result = $driver->setChannelMapper($mapper);

    expect($result)->toBe($driver);
});

test('abstract driver mapChannels returns null when provider does not support channels', function () {
    $driver = new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'paypal';
        } // PayPal doesn't support channels

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }
    };

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, null, [], null, null, null, null, ['card']);
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapChannels');
    $result = $method->invoke($driver, $request);

    expect($result)->toBeNull();
});

test('abstract driver mapChannels returns null when no channels provided', function () {
    $driver = new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'paystack';
        }

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }
    };

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com');
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapChannels');
    $result = $method->invoke($driver, $request);

    expect($result)->toBeNull();
});

test('abstract driver mapChannels returns mapped channels when provided', function () {
    $driver = new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'paystack';
        }

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }
    };

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, null, [], null, null, null, null, ['card', 'bank_transfer']);
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapChannels');
    $result = $method->invoke($driver, $request);

    expect($result)->toBe(['card', 'bank_transfer']);
});

test('abstract driver getStatusNormalizer creates instance if not set', function () {
    $driver = new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'test';
        }

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }

        public function testGetStatusNormalizer()
        {
            return $this->getStatusNormalizer();
        }
    };

    $normalizer = $driver->testGetStatusNormalizer();

    expect($normalizer)->toBeInstanceOf(StatusNormalizer::class);
});

test('abstract driver getChannelMapper creates instance if not set', function () {
    $driver = new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'test';
        }

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }

        public function testGetChannelMapper()
        {
            return $this->getChannelMapper();
        }
    };

    $mapper = $driver->testGetChannelMapper();

    expect($mapper)->toBeInstanceOf(ChannelMapper::class);
});

test('abstract driver normalizeStatus uses status normalizer', function () {
    $driver = new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'test';
        }

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }

        public function testNormalizeStatus(string $status)
        {
            return $this->normalizeStatus($status);
        }
    };

    expect($driver->testNormalizeStatus('SUCCESS'))->toBe('success')
        ->and($driver->testNormalizeStatus('FAILED'))->toBe('failed')
        ->and($driver->testNormalizeStatus('PENDING'))->toBe('pending');
});
