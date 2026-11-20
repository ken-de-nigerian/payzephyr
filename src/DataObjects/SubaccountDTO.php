<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

/**
 * A subaccount as the provider holds it: its code (what Payment::split()
 * takes), the business, where it settles, the default platform percentage and
 * whether it is active.
 */
final readonly class SubaccountDTO
{
    public function __construct(
        public string $code,
        public string $businessName,
        public string $accountNumber,
        public ?string $bankName = null,
        public ?string $accountName = null,
        public float $percentageCharge = 0.0,
        public bool $active = true,
        public ?string $provider = null,
    ) {}

    /**
     * @return array<string, string|float|bool|null>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'business_name' => $this->businessName,
            'account_number' => $this->accountNumber,
            'bank_name' => $this->bankName,
            'account_name' => $this->accountName,
            'percentage_charge' => $this->percentageCharge,
            'active' => $this->active,
            'provider' => $this->provider,
        ];
    }
}
