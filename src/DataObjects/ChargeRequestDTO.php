<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;

final readonly class ChargeRequestDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $customer
     * @param  array<string, mixed>|null  $customFields
     * @param  array<string, mixed>|null  $split
     * @param  array<int, string>|null  $channels
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public string $email,
        public ?string $reference = null,
        public ?string $callbackUrl = null,
        public array $metadata = [],
        public ?string $description = null,
        public ?array $customer = null,
        public ?array $customFields = null,
        public ?array $split = null,
        public ?array $channels = null,
        public ?string $idempotencyKey = null,
    ) {
        $this->validate();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero');
        }

        if ($this->amount > 999999999.99) {
            throw new InvalidArgumentException('Amount exceeds maximum allowed value');
        }

        if (empty($this->currency)) {
            throw new InvalidArgumentException('Currency is required');
        }

        if (strlen($this->currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO code');
        }

        if (! ctype_alpha($this->currency)) {
            throw new InvalidArgumentException('Currency must contain only letters');
        }

        if (! $this->isValidEmail($this->email)) {
            throw new InvalidArgumentException('Invalid email address');
        }

        if ($this->callbackUrl !== null && ! $this->isValidUrl($this->callbackUrl)) {
            throw new InvalidArgumentException('Invalid callback URL');
        }

        if ($this->reference !== null && ! $this->isValidReference($this->reference)) {
            throw new InvalidArgumentException('Invalid reference format');
        }
    }

    private function isValidEmail(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        [$local, $domain] = explode('@', $email);

        if (strlen($local) > 64) {
            return false;
        }

        if (! filter_var($domain, FILTER_VALIDATE_DOMAIN)) {
            return false;
        }

        $suspiciousPatterns = [
            '/\.\./',
            '/@\./',
            '/\.$/',
            '/^\./',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $email)) {
                return false;
            }
        }

        return true;
    }

    private function isValidUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (app()->environment('production')) {
            if (! str_starts_with($url, 'https://')) {
                return false;
            }
        }

        return true;
    }

    private function isValidReference(string $reference): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{1,'.PaymentConstants::MAX_REFERENCE_LENGTH.'}$/', $reference) === 1;
    }

    public function getAmountInMinorUnits(): int
    {
        return (int) round($this->amount * 100);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ChargeRequestDTO
    {
        $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : 0.0;
        $reference = $data['reference'] ?? null;

        if (isset($data['idempotency_key'])) {
            $key = $data['idempotency_key'];
            if (strlen($key) > PaymentConstants::MAX_REFERENCE_LENGTH || ! preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
                throw new InvalidArgumentException('Invalid idempotency key format. Must be alphanumeric with dashes/underscores and max '.PaymentConstants::MAX_REFERENCE_LENGTH.' characters.');
            }
            $idempotencyKey = $key;
        } elseif (is_string($reference) && $reference !== '') {
            $idempotencyKey = $reference;
        } else {
            $idempotencyKey = self::generateIdempotencyKey();
        }

        return new self(
            amount: $amount,
            currency: strtoupper($data['currency'] ?? ''),
            email: $data['email'] ?? '',
            reference: $data['reference'] ?? null,
            callbackUrl: $data['callback_url'] ?? null,
            metadata: $data['metadata'] ?? [],
            description: $data['description'] ?? null,
            customer: $data['customer'] ?? null,
            customFields: $data['custom_fields'] ?? null,
            split: $data['split'] ?? null,
            channels: $data['channels'] ?? null,
            idempotencyKey: $idempotencyKey,
        );
    }

    protected static function generateIdempotencyKey(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'email' => $this->email,
            'reference' => $this->reference,
            'callback_url' => $this->callbackUrl,
            'metadata' => $this->metadata,
            'description' => $this->description,
            'customer' => $this->customer,
            'custom_fields' => $this->customFields,
            'split' => $this->split,
            'channels' => $this->channels,
        ];
    }
}
