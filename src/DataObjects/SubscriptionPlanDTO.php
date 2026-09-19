<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;

final readonly class SubscriptionPlanDTO
{
    use NormalizesMetadata;

    /**
     * Every billing interval PayZephyr understands.
     *
     * The single source of truth for both creating a plan and updating one.
     * Each driver maps these onto its provider's vocabulary, and refuses
     * anything else rather than guessing - a guess here decides how often a
     * customer is charged.
     */
    public const INTERVALS = ['daily', 'weekly', 'monthly', 'annually'];

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

        if (! in_array($this->interval, self::INTERVALS, true)) {
            throw new InvalidArgumentException(
                'Interval must be one of: '.implode(', ', self::INTERVALS)
            );
        }
    }

    /**
     * Apply the same rules to a plan update that apply to creating one.
     *
     * updatePlan() takes a raw array, so none of the checks in validate() ran
     * on it. Two consequences were live: an interval outside the four above -
     * `yearly`, `quarterly`, or Stripe's own `year` - fell through each
     * driver's interval mapper to a monthly default, so a plan meant to bill
     * once a year billed twelve times; and an amount of zero, which creation
     * refuses, silently produced a free plan.
     *
     * Only the keys actually present are checked - an update is partial by
     * nature.
     *
     * @param  array<string, mixed>  $updates
     *
     * @throws PlanException
     */
    public static function assertValidUpdates(array $updates): void
    {
        if (array_key_exists('name', $updates) && ! (is_string($updates['name']) && trim($updates['name']) !== '')) {
            throw new PlanException('Plan name cannot be updated to an empty value.');
        }

        if (array_key_exists('amount', $updates)
            && ! (is_numeric($updates['amount']) && (float) $updates['amount'] > 0)) {
            throw new PlanException('Plan amount must be a number greater than zero.');
        }

        if (array_key_exists('interval', $updates) && ! in_array($updates['interval'], self::INTERVALS, true)) {
            throw new PlanException(
                'Plan interval must be one of: '.implode(', ', self::INTERVALS).'. '.
                'Nothing was updated.'
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
