<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

/**
 * A bank accounts can be held at: its name and the code the provider uses
 * for it (the `bankCode` of resolveAccount() and SubaccountRequestDTO).
 */
final readonly class BankDTO
{
    public function __construct(
        public string $name,
        public string $code,
        public ?string $currency = null,
    ) {}

    /**
     * @return array{name: string, code: string, currency: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'code' => $this->code, 'currency' => $this->currency];
    }
}
