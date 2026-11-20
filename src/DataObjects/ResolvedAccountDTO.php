<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

/**
 * An account number looked up at a bank: the name it is registered to.
 */
final readonly class ResolvedAccountDTO
{
    public function __construct(
        public string $accountNumber,
        public string $accountName,
        public string $bankCode,
    ) {}

    /**
     * @return array{account_number: string, account_name: string, bank_code: string}
     */
    public function toArray(): array
    {
        return ['account_number' => $this->accountNumber, 'account_name' => $this->accountName, 'bank_code' => $this->bankCode];
    }
}
