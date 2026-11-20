<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;

/**
 * A subaccount to create or update: the business, the bank and account it
 * settles to, and the default percentage the platform keeps from each split
 * charge (a charge's own platform fee, Payment::split(platformFee:), takes
 * precedence over it).
 */
final readonly class SubaccountRequestDTO
{
    use NormalizesMetadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $businessName,
        public string $bankCode,
        public string $accountNumber,
        public float $percentageCharge = 0.0,
        public ?string $description = null,
        public ?string $contactEmail = null,
        public ?string $contactName = null,
        public ?string $contactPhone = null,
        public array $metadata = [],
    ) {
        $this->validate();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if (trim($this->businessName) === '') {
            throw new InvalidArgumentException('Business name is required');
        }

        if (trim($this->bankCode) === '') {
            throw new InvalidArgumentException('Bank code is required');
        }

        if ($this->accountNumber === '') {
            throw new InvalidArgumentException('Account number is required');
        }

        if (preg_match('/^\d{6,20}$/', $this->accountNumber) !== 1) {
            throw new InvalidArgumentException('Account number must be 6 to 20 digits');
        }

        if ($this->percentageCharge < 0 || $this->percentageCharge > 100) {
            throw new InvalidArgumentException('Percentage charge must be between 0 and 100');
        }

        if ($this->contactEmail !== null && filter_var($this->contactEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid contact email address');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $input = new Payload($data);

        return new self(
            businessName: $input->string('business_name') ?? '',
            bankCode: $input->string('bank_code') ?? '',
            accountNumber: $input->string('account_number') ?? '',
            percentageCharge: $input->float('percentage_charge') ?? 0.0,
            description: $input->string('description'),
            contactEmail: $input->string('contact_email'),
            contactName: $input->string('contact_name'),
            contactPhone: $input->string('contact_phone'),
            metadata: self::normalizeMetadata($input->get('metadata')),
        );
    }
}
