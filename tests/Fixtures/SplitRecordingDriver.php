<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Fixtures;

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use RuntimeException;

/**
 * A driver that records the last charge it was asked to make (and cannot split
 * charges): the split payment and subaccount tests' stand-in provider.
 */
class SplitRecordingDriver extends AbstractDriver
{
    public ?ChargeRequestDTO $charged = null;

    public function __construct(private readonly string $providerName)
    {
        parent::__construct(['currencies' => ['NGN']]);
        $this->name = $providerName;
    }

    protected function validateConfig(): void {}

    /** @return array<string, string> */
    protected function getDefaultHeaders(): array
    {
        return [];
    }

    public function healthCheck(): bool
    {
        return true;
    }

    public function charge(ChargeRequestDTO $request): ChargeResponseDTO
    {
        $this->charged = $request;

        return new ChargeResponseDTO(
            reference: $request->reference ?? 'ref',
            authorizationUrl: 'https://example.test/pay',
            accessCode: 'code',
            status: 'pending',
            provider: $this->providerName,
        );
    }

    public function verify(string $reference): never
    {
        throw new RuntimeException('not used');
    }

    /** @param array<string, array<int, string>> $headers */
    public function validateWebhook(array $headers, string $body): bool
    {
        return true;
    }
}
