<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;

final readonly class SubscriptionPlanDTO
{
    use NormalizesMetadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public float $amount,
        public string $interval,
        public string $currency = 'NGN',
        public ?string $description = null,
        public ?int $invoiceLimit = null,
        public bool $sendInvoices = true,
        public bool $sendSms = true,
        public array $metadata = [],
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (empty($this->name)) {
            throw new InvalidArgumentException('Plan name is required');
        }

        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero');
        }

        $validIntervals = ['daily', 'weekly', 'monthly', 'annually'];
        if (! in_array($this->interval, $validIntervals)) {
            throw new InvalidArgumentException(
                'Interval must be one of: '.implode(', ', $validIntervals)
            );
        }
    }

    public function getAmountInMinorUnits(): int
    {
        return (int) round($this->amount * 100);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            amount: (float) ($data['amount'] ?? 0),
            interval: $data['interval'] ?? 'monthly',
            currency: $data['currency'] ?? 'NGN',
            description: $data['description'] ?? null,
            invoiceLimit: $data['invoice_limit'] ?? null,
            sendInvoices: $data['send_invoices'] ?? true,
            sendSms: $data['send_sms'] ?? true,
            metadata: self::normalizeMetadata($data['metadata'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'amount' => $this->getAmountInMinorUnits(),
            'interval' => $this->interval,
            'currency' => $this->currency,
            'description' => $this->description,
            'invoice_limit' => $this->invoiceLimit,
            'send_invoices' => $this->sendInvoices,
            'send_sms' => $this->sendSms,
            'metadata' => $this->metadata,
        ], fn ($value) => $value !== null);
    }
}
